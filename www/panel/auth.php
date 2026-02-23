<?php
/**
 * Sistema de autenticación y permisos
 * Maneja los permisos temporales de visudo después de verificar código de Telegram
 */

// Archivo donde se guardan los permisos activos
define('PERMISSIONS_FILE', '/var/www/panel/data/permissions.json');

/**
 * Verifica si el usuario tiene permisos de visudo activos
 * @return bool True si tiene permisos, False si no
 */
function hasVisudoPermission() {
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }
    
    $sessionId = session_id();
    $file = PERMISSIONS_FILE;
    
    if (!file_exists($file)) {
        return false;
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return false;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return false;
    }
    
    // Buscar permiso activo para esta sesión
    foreach ($data as $permission) {
        if (
            isset($permission['session']) &&
            isset($permission['expires']) &&
            $permission['session'] === $sessionId &&
            $permission['expires'] >= time()
        ) {
            return true;
        }
    }
    
    return false;
}

/**
 * Requiere permisos de visudo o lanza excepción
 * @throws Exception Si no tiene permisos
 * @return bool True si tiene permisos
 */
function requireVisudoPermission() {
    if (!hasVisudoPermission()) {
        throw new Exception('Debes verificar tu identidad con Telegram primero');
    }
    return true;
}

/**
 * Otorga permisos de visudo temporales (30 minutos)
 * Se llama después de verificar el código de Telegram
 * @return bool True si se otorgaron correctamente
 */
function grantVisudoPermission() {
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }
    
    $sessionId = session_id();
    $file = PERMISSIONS_FILE;
    $dataDir = dirname($file);
    
    // Crear directorio si no existe
    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0750, true)) {
            return false;
        }
    }
    
    // Inicializar archivo si no existe
    if (!file_exists($file)) {
        if (@file_put_contents($file, json_encode([]), LOCK_EX) === false) {
            return false;
        }
        @chmod($file, 0640);
    }
    
    // Leer permisos existentes
    $content = @file_get_contents($file);
    if ($content === false) {
        return false;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        $data = [];
    }
    
    // Limpiar permisos expirados
    $data = array_filter($data, function($p) {
        return isset($p['expires']) && $p['expires'] >= time();
    });
    
    // Verificar si ya existe permiso para esta sesión
    $found = false;
    foreach ($data as &$permission) {
        if ($permission['session'] === $sessionId) {
            // Renovar permiso existente
            $permission['expires'] = time() + 1800; // 30 minutos
            $permission['renewed_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    // Si no existe, crear nuevo permiso
    if (!$found) {
        $data[] = [
            'session' => $sessionId,
            'usuario_id' => $_SESSION['usuario_id'],
            'expires' => time() + 1800, // 30 minutos
            'granted_at' => date('Y-m-d H:i:s')
        ];
    }
    
    // Guardar permisos
    if (@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        return false;
    }
    
    return true;
}

/**
 * Revoca permisos de visudo para la sesión actual
 * @return bool True si se revocaron correctamente
 */
function revokeVisudoPermission() {
    $sessionId = session_id();
    $file = PERMISSIONS_FILE;
    
    if (!file_exists($file)) {
        return true; // No hay permisos que revocar
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return false;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return true;
    }
    
    // Eliminar permisos de esta sesión
    $data = array_filter($data, function($p) use ($sessionId) {
        return !isset($p['session']) || $p['session'] !== $sessionId;
    });
    
    // Guardar
    if (@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        return false;
    }
    
    return true;
}

/**
 * Obtiene el tiempo restante de permisos en segundos
 * @return int|null Segundos restantes o null si no hay permisos
 */
function getVisudoPermissionTimeRemaining() {
    if (!isset($_SESSION['usuario_id'])) {
        return null;
    }
    
    $sessionId = session_id();
    $file = PERMISSIONS_FILE;
    
    if (!file_exists($file)) {
        return null;
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return null;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return null;
    }
    
    foreach ($data as $permission) {
        if (
            isset($permission['session']) &&
            isset($permission['expires']) &&
            $permission['session'] === $sessionId
        ) {
            $remaining = $permission['expires'] - time();
            return $remaining > 0 ? $remaining : null;
        }
    }
    
    return null;
}

/**
 * Limpia permisos expirados (mantenimiento)
 * Debe llamarse periódicamente
 */
function cleanExpiredPermissions() {
    $file = PERMISSIONS_FILE;
    
    if (!file_exists($file)) {
        return;
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return;
    }
    
    $original_count = count($data);
    
    // Eliminar expirados
    $data = array_filter($data, function($p) {
        return isset($p['expires']) && $p['expires'] >= time();
    });
    
    // Solo guardar si hubo cambios
    if (count($data) !== $original_count) {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

// Limpiar permisos expirados automáticamente (con probabilidad del 10%)
if (rand(1, 10) === 1) {
    cleanExpiredPermissions();
}
?>
