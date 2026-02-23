<?php
session_start();
/**
 * Funciones de seguridad y utilidades para el sistema
 */

/**
 * Genera un token CSRF
 * @return string Token generado
 */
function generarTokenCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica si el token CSRF es válido
 * @param string $token Token a verificar
 * @return bool True si el token es válido, false en caso contrario
 */
function verificarTokenCSRF($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

/**
 * Genera un token de autenticación para "recordar sesión"
 * @param int $usuario_id ID del usuario
 * @param PDO $pdo Conexión a la base de datos
 * @return string Token generado
 */
function generarTokenAutenticacion($usuario_id, $pdo) {
    // Generar token único
    $token = bin2hex(random_bytes(32));
    $hash_token = password_hash($token, PASSWORD_DEFAULT);
    
    // Fecha de expiración (30 días)
    $expiracion = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    try {
        // Eliminar tokens antiguos del usuario
        $query = "DELETE FROM tokens_autenticacion WHERE usuario_id = :usuario_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->execute();
        
        // Insertar nuevo token
        $query = "INSERT INTO tokens_autenticacion (usuario_id, token, expiracion) VALUES (:usuario_id, :token, :expiracion)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':token', $hash_token);
        $stmt->bindParam(':expiracion', $expiracion);
        $stmt->execute();
        
        return $token;
    } catch (PDOException $e) {
        // Si la tabla no existe, intentar crearla
        if ($e->getCode() == '42S02') {
            crearTablaTokens($pdo);
            return generarTokenAutenticacion($usuario_id, $pdo);
        }
        return false;
    }
}

/**
 * Verifica un token de autenticación
 * @param string $token Token a verificar
 * @param PDO $pdo Conexión a la base de datos
 * @return int|false ID del usuario si el token es válido, false en caso contrario
 */
function verificarTokenAutenticacion($token, $pdo) {
    try {
        $query = "SELECT t.usuario_id, t.token, t.expiracion, u.username, u.rol, u.imagen_perfil 
                 FROM tokens_autenticacion t 
                 JOIN usuarios u ON t.usuario_id = u.id 
                 WHERE t.expiracion > NOW()";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tokens as $t) {
            if (password_verify($token, $t['token'])) {
                return [
                    'id' => $t['usuario_id'],
                    'username' => $t['username'],
                    'rol' => $t['rol'],
                    'imagen_perfil' => $t['imagen_perfil']
                ];
            }
        }
        
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Elimina un token de autenticación
 * @param string $token Token a eliminar
 * @param PDO $pdo Conexión a la base de datos
 * @return bool True si se eliminó correctamente, false en caso contrario
 */
function eliminarTokenAutenticacion($token, $pdo) {
    try {
        $query = "SELECT token FROM tokens_autenticacion";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tokens as $t) {
            if (password_verify($token, $t['token'])) {
                $query = "DELETE FROM tokens_autenticacion WHERE token = :token";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':token', $t['token']);
                $stmt->execute();
                return true;
            }
        }
        
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Crea la tabla de tokens si no existe
 * @param PDO $pdo Conexión a la base de datos
 * @return bool True si se creó correctamente, false en caso contrario
 */
function crearTablaTokens($pdo) {
    try {
        $query = "CREATE TABLE IF NOT EXISTS tokens_autenticacion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expiracion DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        )";
        $pdo->exec($query);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Registra una actividad en el sistema
 * @param string $tipo Tipo de actividad
 * @param string $descripcion Descripción de la actividad
 * @param int $usuario_id ID del usuario que realizó la actividad
 * @param PDO $pdo Conexión a la base de datos
 * @return bool True si se registró correctamente, false en caso contrario
 */
function registrarActividad($tipo, $descripcion, $usuario_id, $pdo) {
    try {
        // Verificar si la tabla existe
        $query = "SHOW TABLES LIKE 'actividades'";
        $result = $pdo->query($query);
        
        // Si la tabla no existe, crearla
        if ($result->rowCount() == 0) {
            $query = "CREATE TABLE actividades (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tipo VARCHAR(50) NOT NULL,
                descripcion TEXT NOT NULL,
                usuario_id INT NOT NULL,
                fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
            )";
            $pdo->exec($query);
        }
        
        // Insertar la actividad
        $query = "INSERT INTO actividades (tipo, descripcion, usuario_id) VALUES (:tipo, :descripcion, :usuario_id)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':descripcion', $descripcion);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Obtiene las actividades recientes del sistema
 * @param PDO $pdo Conexión a la base de datos
 * @param int $limite Número máximo de actividades a obtener
 * @return array|false Array con las actividades o false en caso de error
 */
function obtenerActividadesRecientes($pdo, $limite = 5) {
    try {
        $query = "SELECT a.id, a.tipo, a.descripcion, a.fecha, u.username 
                 FROM actividades a 
                 JOIN usuarios u ON a.usuario_id = u.id 
                 ORDER BY a.fecha DESC 
                 LIMIT :limite";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Si la tabla no existe, devolver un array vacío
        return [];
    }
}

/**
 * Formatea una fecha para mostrarla de forma amigable
 * @param string $fecha Fecha en formato Y-m-d H:i:s
 * @return string Fecha formateada
 */
function formatearFechaAmigable($fecha) {
    $timestamp = strtotime($fecha);
    $ahora = time();
    $diferencia = $ahora - $timestamp;
    
    if ($diferencia < 60) {
        return "Hace unos segundos";
    } elseif ($diferencia < 3600) {
        $minutos = floor($diferencia / 60);
        return "Hace " . $minutos . " minuto" . ($minutos > 1 ? "s" : "");
    } elseif ($diferencia < 86400) {
        $horas = floor($diferencia / 3600);
        return "Hace " . $horas . " hora" . ($horas > 1 ? "s" : "");
    } elseif ($diferencia < 604800) {
        $dias = floor($diferencia / 86400);
        return "Hace " . $dias . " día" . ($dias > 1 ? "s" : "");
    } else {
        return date("d/m/Y", $timestamp);
    }
}
