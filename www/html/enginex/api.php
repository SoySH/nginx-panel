<?php
session_start();
// Configuración de seguridad
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("X-Content-Type-Options: nosniff");

// Verificar sesión (USAR usuario_id, NO user_id)
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida. Por favor, inicia sesión nuevamente.']);
    exit;
}

// Protección anti-session hijacking
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión inválida']);
    exit;
}

// Timeout de sesión (10 minutos)
$timeout_duration = 600;
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $timeout_duration) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada']);
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

// Logging de errores
$logDir = '/var/www/panel/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

function logError($msg) {
    global $logDir;
    $logFile = "$logDir/api_errors.log";
    $timestamp = date('Y-m-d H:i:s');
    $userId = $_SESSION['usuario_id'] ?? 'unknown';
    $message = "[$timestamp] [User: $userId] $msg" . PHP_EOL;
    @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
}

function logActivity($action, $details = '') {
    global $logDir;
    $logFile = "$logDir/api_activity.log";
    $timestamp = date('Y-m-d H:i:s');
    $userId = $_SESSION['usuario_id'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $message = "[$timestamp] [User: $userId] [IP: $ip] [Action: $action] $details" . PHP_EOL;
    @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
}

// Rate limiting
function checkRateLimit($action, $maxAttempts = 20, $timeWindow = 60) {
    $key = 'api_rate_limit_' . $action;
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'time' => time()];
    }
    
    $elapsed = time() - $_SESSION[$key]['time'];
    
    if ($elapsed > $timeWindow) {
        $_SESSION[$key] = ['count' => 1, 'time' => time()];
        return true;
    }
    
    if ($_SESSION[$key]['count'] >= $maxAttempts) {
        return false;
    }
    
    $_SESSION[$key]['count']++;
    return true;
}

// Incluir verificación de permisos
$authFile = '/var/www/panel/auth.php';
if (!file_exists($authFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Sistema de autenticación no configurado']);
    exit;
}

require_once $authFile;

// Verificar que las funciones existan
if (!function_exists('requireVisudoPermission')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Funciones de autenticación no disponibles']);
    exit;
}

// Directorios
$availDir = '/etc/nginx/sites-available';
$enableDir = '/etc/nginx/sites-enabled';

try {
    $action = $_POST['action'] ?? '';
    
    if (empty($action)) {
        throw new Exception('Acción no especificada');
    }
    
    // Rate limiting
    if (!checkRateLimit($action)) {
        http_response_code(429);
        throw new Exception('Demasiadas solicitudes. Espera un momento.');
    }
    
    // Sanitizar entrada
    $file = isset($_POST['file']) ? basename($_POST['file']) : '';
    
    switch ($action) {
        
        // ========================================
        // LEER ARCHIVO - REQUIERE VISUDO
        // ========================================
        case 'read':
            if (!requireVisudoPermission()) {
                http_response_code(403);
                throw new Exception('Permisos insuficientes');
            }
            
            if (empty($file)) {
                throw new Exception('Nombre de archivo vacío');
            }
            
            $path = "$availDir/$file";
            if (!file_exists($path)) {
                throw new Exception('El archivo no existe');
            }
            
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new Exception('No se pudo leer el archivo');
            }
            
            logActivity('read', "Archivo: $file");
            
            echo json_encode([
                'ok' => true,
                'content' => $content
            ]);
            break;
        
        // ========================================
        // GUARDAR ARCHIVO - REQUIERE VISUDO
        // ========================================
        case 'save':
            if (!requireVisudoPermission()) {
                http_response_code(403);
                throw new Exception('Permisos insuficientes');
            }
            
            if (empty($file)) {
                throw new Exception('Nombre de archivo vacío');
            }
            
            $content = $_POST['content'] ?? '';
            
            if (empty($content)) {
                throw new Exception('Contenido vacío');
            }
            
            $path = "$availDir/$file";
            $tmpPath = "/tmp/nginx_" . uniqid() . "_" . bin2hex(random_bytes(4));
            
            // Guardar en temporal
            if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
                throw new Exception('No se pudo crear archivo temporal');
            }
            
            // Copiar con sudo
            exec("sudo cp " . escapeshellarg($tmpPath) . " " . escapeshellarg($path) . " 2>&1", $cpOutput, $cpCode);
            @unlink($tmpPath);
            
            if ($cpCode !== 0) {
                logError("Error al guardar $file: " . implode("\n", $cpOutput));
                throw new Exception('Error al guardar: ' . implode("\n", $cpOutput));
            }
            
            // Validar sintaxis de NGINX
            exec("sudo nginx -t 2>&1", $testOutput, $testCode);
            
            if ($testCode !== 0) {
                $errorMsg = implode("\n", $testOutput);
                logError("Error de sintaxis en $file: $errorMsg");
                throw new Exception($errorMsg);
            }
            
            // Recargar NGINX
            exec("sudo systemctl reload nginx 2>&1", $reloadOutput, $reloadCode);
            
            if ($reloadCode !== 0) {
                logError("Error al recargar NGINX después de guardar $file");
                throw new Exception('NGINX recargado con advertencias: ' . implode("\n", $reloadOutput));
            }
            
            logActivity('save', "Archivo: $file");
            
            echo json_encode([
                'ok' => true,
                'msg' => 'Configuración guardada y aplicada'
            ]);
            break;
        
        // ========================================
        // HABILITAR SITIO - REQUIERE VISUDO
        // ========================================
        case 'enable':
            if (!requireVisudoPermission()) {
                http_response_code(403);
                throw new Exception('Permisos insuficientes');
            }
            
            if (empty($file)) {
                throw new Exception('Nombre de archivo vacío');
            }
            
            $availPath = "$availDir/$file";
            $enablePath = "$enableDir/$file";
            
            if (!file_exists($availPath)) {
                throw new Exception('El archivo no existe');
            }
            
            if (file_exists($enablePath) || is_link($enablePath)) {
                throw new Exception('El sitio ya está habilitado');
            }
            
            // Crear enlace simbólico
            exec("sudo ln -s " . escapeshellarg($availPath) . " " . escapeshellarg($enablePath) . " 2>&1", $lnOutput, $lnCode);
            
            if ($lnCode !== 0) {
                logError("Error al habilitar $file: " . implode("\n", $lnOutput));
                throw new Exception('Error al habilitar: ' . implode("\n", $lnOutput));
            }
            
            // Validar y recargar
            exec("sudo nginx -t 2>&1", $testOutput, $testCode);
            if ($testCode !== 0) {
                exec("sudo rm " . escapeshellarg($enablePath) . " 2>&1");
                logError("Error de sintaxis al habilitar $file");
                throw new Exception('Error de sintaxis: ' . implode("\n", $testOutput));
            }
            
            exec("sudo systemctl reload nginx 2>&1", $reloadOutput, $reloadCode);
            
            logActivity('enable', "Sitio: $file");
            
            echo json_encode([
                'ok' => true,
                'msg' => "Sitio '$file' habilitado correctamente"
            ]);
            break;
        
        // ========================================
        // DESHABILITAR SITIO - REQUIERE VISUDO
        // ========================================
        case 'disable':
            if (!requireVisudoPermission()) {
                http_response_code(403);
                throw new Exception('Permisos insuficientes');
            }
            
            if (empty($file)) {
                throw new Exception('Nombre de archivo vacío');
            }
            
            $enablePath = "$enableDir/$file";
            
            if (!file_exists($enablePath) && !is_link($enablePath)) {
                throw new Exception('El sitio no está habilitado');
            }
            
            exec("sudo rm " . escapeshellarg($enablePath) . " 2>&1", $rmOutput, $rmCode);
            
            if ($rmCode !== 0) {
                logError("Error al deshabilitar $file: " . implode("\n", $rmOutput));
                throw new Exception('Error al deshabilitar: ' . implode("\n", $rmOutput));
            }
            
            exec("sudo systemctl reload nginx 2>&1");
            
            logActivity('disable', "Sitio: $file");
            
            echo json_encode([
                'ok' => true,
                'msg' => "Sitio '$file' deshabilitado correctamente"
            ]);
            break;
        
        // ========================================
        // RENOMBRAR SITIO - REQUIERE VISUDO
        // ========================================
        case 'rename':
            if (!requireVisudoPermission()) {
                http_response_code(403);
                throw new Exception('Permisos insuficientes');
            }
            
            $newName = isset($_POST['newname']) ? basename($_POST['newname']) : '';
            
            if (empty($file) || empty($newName)) {
                throw new Exception('Nombres vacíos');
            }
            
            if ($file === $newName) {
                throw new Exception('El nombre es el mismo');
            }
            
            $oldPath = "$availDir/$file";
            $newPath = "$availDir/$newName";
            
            if (!file_exists($oldPath)) {
                throw new Exception('El archivo no existe');
            }
            
            if (file_exists($newPath)) {
                throw new Exception('Ya existe un archivo con ese nombre');
            }
            
            // Renombrar
            exec("sudo mv " . escapeshellarg($oldPath) . " " . escapeshellarg($newPath) . " 2>&1", $mvOutput, $mvCode);
            
            if ($mvCode !== 0) {
                logError("Error al renombrar $file a $newName: " . implode("\n", $mvOutput));
                throw new Exception('Error al renombrar: ' . implode("\n", $mvOutput));
            }
            
            // Si estaba habilitado, actualizar el enlace
            $oldLink = "$enableDir/$file";
            $newLink = "$enableDir/$newName";
            
            if (file_exists($oldLink) || is_link($oldLink)) {
                exec("sudo rm -f " . escapeshellarg($oldLink) . " 2>&1");
                exec("sudo ln -s " . escapeshellarg($newPath) . " " . escapeshellarg($newLink) . " 2>&1");
            }
            
            exec("sudo systemctl reload nginx 2>&1");
            
            logActivity('rename', "De: $file, A: $newName");
            
            echo json_encode([
                'ok' => true,
                'msg' => "Renombrado de '$file' a '$newName'"
            ]);
            break;
        
        // ========================================
        // ELIMINAR SITIO - REQUIERE VISUDO
        // ========================================
        case 'delete':
            if (!requireVisudoPermission()) {
                http_response_code(403);
                throw new Exception('Permisos insuficientes');
            }
            
            if (empty($file)) {
                throw new Exception('Nombre de archivo vacío');
            }
            
            $availPath = "$availDir/$file";
            $enablePath = "$enableDir/$file";
            
            if (!file_exists($availPath)) {
                throw new Exception('El archivo no existe');
            }
            
            // Eliminar enlace si existe
            if (file_exists($enablePath) || is_link($enablePath)) {
                exec("sudo rm " . escapeshellarg($enablePath) . " 2>&1");
            }
            
            // Eliminar archivo
            exec("sudo rm " . escapeshellarg($availPath) . " 2>&1", $rmOutput, $rmCode);
            
            if ($rmCode !== 0) {
                logError("Error al eliminar $file: " . implode("\n", $rmOutput));
                throw new Exception('Error al eliminar: ' . implode("\n", $rmOutput));
            }
            
            exec("sudo systemctl reload nginx 2>&1");
            
            logActivity('delete', "Sitio: $file");
            
            echo json_encode([
                'ok' => true,
                'msg' => "Sitio '$file' eliminado correctamente"
            ]);
            break;
        
        // ========================================
        // CREAR SITIO - NO REQUIERE VISUDO
        // ========================================
        case 'create':
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Nombre vacío');
            }
            
            // Validar caracteres permitidos
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
                throw new Exception('El nombre contiene caracteres no permitidos');
            }
            
            // Agregar .conf si no lo tiene
            if (!preg_match('/\.conf$/', $name)) {
                $name .= '.conf';
            }
            
            $name = basename($name);
            $path = "$availDir/$name";
            
            if (file_exists($path)) {
                throw new Exception('Ya existe un sitio con ese nombre');
            }
            
            // Plantilla básica
            $template = <<<NGINX
server {
    listen 80;
    server_name ejemplo.com;
    
    root /var/www/html;
    index index.html index.php;
    
    location / {
        try_files \$uri \$uri/ =404;
    }
}
NGINX;
            
            $tmpPath = "/tmp/nginx_" . uniqid() . "_" . bin2hex(random_bytes(4));
            if (@file_put_contents($tmpPath, $template, LOCK_EX) === false) {
                throw new Exception('No se pudo crear archivo temporal');
            }
            
            exec("sudo cp " . escapeshellarg($tmpPath) . " " . escapeshellarg($path) . " 2>&1", $cpOutput, $cpCode);
            @unlink($tmpPath);
            
            if ($cpCode !== 0) {
                logError("Error al crear $name: " . implode("\n", $cpOutput));
                throw new Exception('Error al crear: ' . implode("\n", $cpOutput));
            }
            
            logActivity('create', "Sitio: $name");
            
            echo json_encode([
                'ok' => true,
                'msg' => "Sitio '$name' creado correctamente"
            ]);
            break;
        
        default:
            throw new Exception('Acción no válida: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8'));
    }
    
} catch (Exception $e) {
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    
    $errorMsg = $e->getMessage();
    logError("Error en acción '{$action}': $errorMsg");
    
    echo json_encode([
        'ok' => false,
        'msg' => $errorMsg
    ]);
}
?>
