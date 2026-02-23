<?php
session_start();

// Configuración de seguridad estricta
//ini_set('display_errors', 0);
//ini_set('display_startup_errors', 0);
//error_reporting(E_ALL);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

// Headers de seguridad
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Validar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: home.php");
    exit;
}

// Protección anti-session hijacking
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    session_unset();
    session_destroy();
    header("Location: home.php");
    exit;
}

// Timeout de sesión (10 minutos de inactividad)
$timeout_duration = 600;
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: home.php?timeout=1");
    exit;
}

// Generar token CSRF si no existe
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Configuración de logs
$logDir = '/var/www/panel/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

function logError($msg) {
    global $logDir;
    $logFile = "$logDir/errors.log";
    $timestamp = date('Y-m-d H:i:s');
    $userId = $_SESSION['usuario_id'] ?? 'anonymous';
    $message = "[$timestamp] [User: $userId] $msg" . PHP_EOL;
    @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
}

function logActivity($action, $details = '') {
    global $logDir;
    $logFile = "$logDir/activity.log";
    $timestamp = date('Y-m-d H:i:s');
    $userId = $_SESSION['usuario_id'] ?? 'anonymous';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $message = "[$timestamp] [User: $userId] [IP: $ip] [Action: $action] $details" . PHP_EOL;
    @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
}

// Rate limiting para acciones AJAX
function checkRateLimit($action, $maxAttempts = 10, $timeWindow = 60) {
    $key = 'rate_limit_' . $action;
    
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

// AJAX HANDLER
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Verificar método POST para acciones que modifican datos
    $modifyActions = ['challenge-verify', 'visudo'];
    if (in_array($_GET['ajax'], $modifyActions) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
        exit;
    }
    
    // Verificar CSRF token para acciones POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            http_response_code(403);
            logError("AJAX CSRF validation failed for action: " . ($_GET['ajax'] ?? 'unknown'));
            echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido']);
            exit;
        }
    }
    
    // Rate limiting
    if (!checkRateLimit($_GET['ajax'])) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'msg' => 'Demasiadas solicitudes. Espera un momento.']);
        exit;
    }

    require_once '/var/www/panel/telegram.php';
    require_once '/var/www/panel/challenge.php';
    require_once '/var/www/panel/visudo.php';

    try {
        $ajax_action = $_GET['ajax'];
        
        switch ($ajax_action) {
            case 'challenge-request':
                $challenge = createChallenge(session_id());
                if (!$challenge) {
                    throw new Exception("No se pudo generar el challenge");
                }

                $file = '/var/www/panel/data/challenges.json';
                $dataDir = dirname($file);
                
                if (!is_dir($dataDir)) {
                    if (!@mkdir($dataDir, 0750, true)) {
                        throw new Exception("No se pudo crear directorio de datos");
                    }
                }
                
                if (!file_exists($file)) {
                    if (@file_put_contents($file, json_encode([]), LOCK_EX) === false) {
                        throw new Exception("No se pudo crear archivo de challenges");
                    }
                    @chmod($file, 0640);
                }

                $content = @file_get_contents($file);
                if ($content === false) {
                    throw new Exception("No se pudo leer archivo de challenges");
                }
                
                $data = json_decode($content, true);
                if (!is_array($data)) {
                    $data = [];
                }

                // Limpiar challenges expirados
                $data = array_filter($data, function($c) {
                    return $c['expires'] >= time();
                });

                $data[] = [
                    'code'    => $challenge['code'],
                    'session' => session_id(),
                    'expires' => time() + 300,
                    'used'    => false,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                if (@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
                    throw new Exception("No se pudo guardar el challenge");
                }

                $telegramResult = sendTelegram("🔐 Código de verificación:\n*{$challenge['code']}*");
                
                if (!$telegramResult) {
                    logError("Advertencia: Telegram no confirmó el envío del código");
                }
                
                logActivity('challenge-request', "Challenge generado");
                echo json_encode(['ok' => true, 'code' => $challenge['code']]);
                break;

            case 'challenge-verify':
                $code = strtoupper(trim($_POST['code'] ?? ''));
                
                if (empty($code)) {
                    throw new Exception("Código vacío");
                }
                
                if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
                    throw new Exception("Formato de código inválido");
                }

                $file = '/var/www/panel/data/challenges.json';
                
                if (!file_exists($file)) {
                    throw new Exception("No hay challenges disponibles");
                }
                
                $content = @file_get_contents($file);
                if ($content === false) {
                    throw new Exception("Error leyendo challenges");
                }
                
                $data = json_decode($content, true);
                if (!is_array($data)) {
                    throw new Exception("Datos de challenges corruptos");
                }

                $valid = false;
                foreach ($data as &$c) {
                    if (
                        !$c['used'] &&
                        $c['code'] === $code &&
                        $c['expires'] >= time() &&
                        $c['session'] === session_id()
                    ) {
                        $c['used'] = true;
                        $c['verified_at'] = date('Y-m-d H:i:s');
                        $valid = true;
                        break;
                    }
                }

                if (!$valid) {
                    logActivity('challenge-verify', "Intento fallido con código: $code");
                    throw new Exception("Código inválido o expirado");
                }

                if (@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
                    throw new Exception("Error guardando verificación");
                }
                
                logActivity('challenge-verify', "Challenge verificado correctamente");
                echo json_encode(['ok' => true]);
                break;

            case 'visudo':
                $file = '/var/www/panel/data/challenges.json';
                
                if (!file_exists($file)) {
                    throw new Exception("No se ha verificado ningún código");
                }
                
                $content = @file_get_contents($file);
                if ($content === false) {
                    throw new Exception("Error leyendo verificaciones");
                }
                
                $data = json_decode($content, true);
                if (!is_array($data)) {
                    throw new Exception("Datos de verificación corruptos");
                }

                $authorized = false;
                foreach ($data as $c) {
                    if ($c['used'] && $c['session'] === session_id() && $c['expires'] >= time()) {
                        $authorized = true;
                        break;
                    }
                }

                if (!$authorized) {
                    throw new Exception("No se ha verificado ningún código válido");
                }

                // Aplicar visudo en el sistema
                $result = applyVisudo();
                if ($result !== true) {
                    throw new Exception($result ?: 'Error aplicando visudo');
                }

                // Otorgar permisos temporales en auth.php
                require_once '/var/www/panel/auth.php';
                if (!grantVisudoPermission()) {
                    throw new Exception('Error otorgando permisos');
                }

                logActivity('visudo', "Visudo aplicado y permisos otorgados (10 min)");
                echo json_encode(['ok' => true, 'msg' => 'Permisos habilitados por 10 minutos']);
                break;

            default:
                throw new Exception("Acción inválida");
        }

    } catch (Throwable $e) {
        $errorMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        logError("AJAX Error [{$ajax_action}]: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => $errorMsg]);
    }

    exit;
}

// Directorios NGINX
$avail_dir  = "/etc/nginx/sites-available";
$enable_dir = "/etc/nginx/sites-enabled";

if (!is_dir($avail_dir) || !is_readable($avail_dir)) {
    logError("No se puede acceder al directorio de sitios disponibles");
    $files = [];
} else {
    $files = array_filter(
        @scandir($avail_dir) ?: [],
        fn($f) => !in_array($f, ['.', '..'])
    );
}

// Estado NGINX
exec("sudo systemctl is-active nginx 2>&1", $nginx_status);
$nginx_running = trim($nginx_status[0] ?? '') === 'active';

// Escapar el nombre de usuario para mostrar
$usuario_nombre = htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="./server.png">
    <title>ENGINE-X Sites</title>

    <link href="./bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="./fontawesome/css/all.min.css" rel="stylesheet">
    <script src="./sweetalert2@11.js"></script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fa-solid fa-server"></i>
            <span>ENGINE-X <strong>Sites</strong></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li class="nav-section">Principal</li>

            <li>
                <a href="#" class="active">
                    <i class="fa-solid fa-globe"></i>
                    <span>Sitios</span>
                    <span class="badge-count"><?= count($files) ?></span>
                </a>
            </li>

            <li>
                <a href="#" id="btnCreate">
                    <i class="fa-solid fa-plus"></i>
                    <span>Crear Sitio</span>
                </a>
            </li>

            <li class="nav-section">Estado</li>

            <li>
                <a class="status-indicator <?= $nginx_running ? 'status-online' : 'status-offline' ?>">
                    <i class="fa-solid fa-circle"></i>
                    <span>NGINX <?= $nginx_running ? 'Activo' : 'Inactivo' ?></span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <small> github/SoySH v4.0</small>
    </div>
</aside>

<main class="main-content">

<header class="content-header">
    <div>
        <h1>Administrar Configuraciones</h1>
        <p class="subtitle">Gestiona tus sitios de NGINX fácilmente</p>
    </div>

    <div class="header-actions">
        <button id="btnTelegramChallenge" class="btn-reload" data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa-solid fa-shield-halved"></i> Telegram
        </button>

        <button class="btn-reload" onclick="location.reload()">
            <i class="fa-solid fa-rotate"></i> Recargar
        </button>

        <button class="btn-logout" onclick="confirmLogout()">
            <i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión
        </button>
    </div>
</header>

<div class="sites-grid">
<?php foreach ($files as $file):
    $enabled  = file_exists("$enable_dir/$file");
    $path     = "$avail_dir/$file";
    $size     = file_exists($path) ? @filesize($path) : 0;
    $modified = file_exists($path) ? @filemtime($path) : 0;
    $file_escaped = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
?>
    <div class="site-card <?= $enabled ? 'enabled' : 'disabled' ?>">
        <div class="card-status">
            <span class="status-dot <?= $enabled ? 'dot-green' : 'dot-red' ?>"></span>
            <span><?= $enabled ? 'Habilitado' : 'Deshabilitado' ?></span>
        </div>

        <div class="card-body">
            <i class="fa-solid fa-file-code"></i>
            <h3><?= $file_escaped ?></h3>

            <div class="card-meta">
                <span><?= $modified ? date('d/m/Y H:i', $modified) : 'N/A' ?></span>
                <span><?= $size > 0 ? number_format($size / 1024, 1) : '0' ?> KB</span>
            </div>
        </div>

        <div class="card-actions">
            <button class="action-btn" data-action="edit" data-file="<?= $file_escaped ?>" title="Editar">
                <i class="fa-solid fa-pen"></i>
            </button>
            <button class="action-btn" data-action="toggle" data-file="<?= $file_escaped ?>" data-state="<?= $enabled ? 'on' : 'off' ?>" title="<?= $enabled ? 'Deshabilitar' : 'Habilitar' ?>">
                <i class="fa-solid fa-<?= $enabled ? 'toggle-on' : 'toggle-off' ?>"></i>
            </button>
            <button class="action-btn" data-action="rename" data-file="<?= $file_escaped ?>" title="Renombrar">
                <i class="fa-solid fa-i-cursor"></i>
            </button>
            <button class="action-btn danger" data-action="delete" data-file="<?= $file_escaped ?>" title="Eliminar">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>
    </div>
<?php endforeach; ?>
</div>

</main>

<!-- Modal Editor -->
<div class="modal fade" id="editorModal" tabindex="-1" aria-labelledby="editorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="editorModalLabel">
                        <i class="fa-solid fa-file-code"></i>
                        Editando: <span id="editingFileName"></span>
                    </h5>
                    <div class="editor-info">
                        <span id="lineCount">Líneas: 0</span>
                        <span id="editorStatus" class="editor-status saved">
                            <i class="fa-solid fa-circle-check"></i> Sin cambios
                        </span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0">
                <textarea id="editor" class="code-editor" spellcheck="false"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark"></i> Cerrar
                </button>
                <button type="button" class="btn btn-primary" id="saveBtn">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar cambios
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" class="toast-container" role="alert" aria-live="polite" aria-atomic="true"></div>

<!-- CSRF Token para JavaScript -->
<script>
    window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>

<script src="./bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>

</body>
</html>
