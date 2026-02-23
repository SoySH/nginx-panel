<?php
define('VISUDO_DURATION', 600); // ⏱️  10 minutos (300 segundos)

function applyVisudo() {
    try {
        $lockDir = '/var/www/panel/data/locks';
        $lock = "$lockDir/visudo.lock";
        $sudoersFile = '/etc/sudoers.d/nginx-dash';
        
        // Crear directorio de locks
        if (!is_dir($lockDir)) {
            if (!mkdir($lockDir, 0755, true)) {
                error_log("Visudo: No se pudo crear directorio de locks");
                return "No se pudo crear directorio de locks";
            }
        }
        
        // Lock activo
        if (file_exists($lock)) {
            $ts = (int) @file_get_contents($lock);
            if ($ts && (time() - $ts) < VISUDO_DURATION) {
                $remaining = VISUDO_DURATION - (time() - $ts);
                error_log("Visudo: Lock activo ($remaining s restantes)");
                return "Visudo ya activo ($remaining s)";
            }
            @unlink($lock);
        }
        
        // Validaciones
        if (!file_exists($sudoersFile)) {
            return "Archivo sudoers inexistente";
        }
        
        // Crear lock
        file_put_contents($lock, time(), LOCK_EX);
        error_log("Visudo: Activando permisos temporales");
        
        // Activar SOLO el bloque temporal
        $cmd = <<<BASH
sudo /usr/bin/sed -i '/# ===== BLOQUE TEMPORAL =====/,/# ===== FIN BLOQUE TEMPORAL =====/ {
  s/^# www-data/www-data/
  s/# TEMPORALES - DESHABILITADOS/# TEMPORALES - ACTIVOS/
}' "$sudoersFile"
BASH;
        
        exec($cmd . " 2>&1", $out, $code);
        
        if ($code !== 0) {
            @unlink($lock);
            return "Error activando permisos: " . implode("\n", $out);
        }
        
        // Test permisos
        exec("sudo -n /usr/sbin/nginx -t 2>&1", $testOut, $testCode);
        if ($testCode !== 0) {
            @unlink($lock);
            return "Permisos inválidos: " . implode("\n", $testOut);
        }
        
        // Programar rollback
        scheduleRollback($sudoersFile, $lock);
        
        error_log("Visudo: Permisos activados correctamente");
        return true;
        
    } catch (Throwable $e) {
        error_log("Visudo EX: " . $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Rollback automático seguro
 */
function scheduleRollback($sudoersFile, $lock) {
    $duration = VISUDO_DURATION;
    
    $script = <<<BASH
(
  sleep $duration
  if [ -f "$lock" ]; then
sudo /usr/bin/sed -i '/# ===== BLOQUE TEMPORAL =====/,/# ===== FIN BLOQUE TEMPORAL =====/ {
  s/^www-data/# www-data/
  s/# TEMPORALES - ACTIVOS/# TEMPORALES - DESHABILITADOS/
}' "$sudoersFile"

    rm -f "$lock"
    logger "NGINX Panel: Visudo auto-deshabilitado"
    echo "\$(date): Rollback ejecutado" >> /var/www/panel/logs/visudo.log
  fi
) > /dev/null 2>&1 &
BASH;
    
    error_log("Visudo: Rollback programado en $duration segundos");
    exec($script);
}
