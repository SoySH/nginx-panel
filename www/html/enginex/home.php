<?php
session_start();

/* ==============================
   CARGAR .ENV (ANTES DE USARLO)
   ============================== */
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Validar que las variables existan (NO fallback inseguro)
if (
    empty($_ENV['DB_HOST']) ||
    empty($_ENV['DB_NAME']) ||
    empty($_ENV['DB_USER']) ||
    empty($_ENV['DB_PASS'])
) {
    die('Error crítico: variables de entorno de base de datos no definidas.');
}

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];


/* ==============================
   CONFIGURACIÓN DE SEGURIDAD
   ============================== */

//ini_set('display_errors', 0);
//ini_set('display_startup_errors', 0);
//error_reporting(E_ALL);

ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => 'prueba.net',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Protección contra Session Fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Generar token CSRF solo si no existe
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verificar functions.php
if (!file_exists('functions.php')) {
    die('ERROR: No se encuentra el archivo functions.php');
}

require_once 'functions.php';

// Headers de seguridad
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: upgrade-insecure-requests;");

// Si ya está autenticado
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}


/* ==============================
   LÓGICA DE LOGIN
   ============================== */

$mensaje = '';
$tiempo_bloqueo = 900; // 15 min

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

$tiempo_transcurrido = time() - $_SESSION['last_attempt_time'];

if ($_SESSION['login_attempts'] >= 5 && $tiempo_transcurrido < $tiempo_bloqueo) {
    $tiempo_restante = $tiempo_bloqueo - $tiempo_transcurrido;
    $mensaje = "Demasiados intentos fallidos. Intenta nuevamente en " . ceil($tiempo_restante / 60) . " minutos.";
} elseif ($tiempo_transcurrido >= $tiempo_bloqueo) {
    $_SESSION['login_attempts'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['login_attempts'] < 5) {

    $csrf_from_post = $_POST['csrf_token'] ?? '';
    $csrf_from_session = $_SESSION['csrf_token'] ?? '';

    if (
        empty($csrf_from_post) ||
        empty($csrf_from_session) ||
        !hash_equals($csrf_from_session, $csrf_from_post)
    ) {
        $mensaje = "Error de seguridad: token inválido.";
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
    } else {
        try {

            $pdo = new PDO(
                "mysql:host=$host;dbname=$db;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {

                $mensaje = "Usuario y contraseña son requeridos";

            } elseif (strlen($username) > 50 || strlen($password) > 100) {

                $mensaje = "Datos inválidos";

            } else {

                $stmt = $pdo->prepare("
                    SELECT id, username, password
                    FROM usuarios
                    WHERE username = :username
                    LIMIT 1
                ");

                $stmt->execute(['username' => $username]);
                $usuario = $stmt->fetch();

                if ($usuario && password_verify($password, $usuario['password'])) {

                    session_regenerate_id(true);

                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['usuario_nombre'] = htmlspecialchars($usuario['username'], ENT_QUOTES, 'UTF-8');
                    $_SESSION['login_time'] = time();
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $_SESSION['login_attempts'] = 0;

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    if (function_exists('registrarActividad')) {
                        registrarActividad('login', 'Inicio de sesión exitoso', $usuario['id'], $pdo);
                    }

                    header('Location: index.php');
                    exit;

                } else {
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    $mensaje = "Usuario o contraseña incorrectos";
                }
            }

        } catch (PDOException $e) {
            error_log("Error de login: " . $e->getMessage());
            $mensaje = "Error del sistema. Intenta más tarde.";
        }
    }
}

$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <link rel="icon" href="./server.png">
  <title>ENGINE-X USUARIO</title>
  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="./fontawesome/css/all.min.css">
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #468534cc 0%, #091402eb 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      position: relative;
    }

    .bg-pattern {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      opacity: 0.1;
      background-image: 
        radial-gradient(circle at 25% 25%, white 2px, transparent 2px),
        radial-gradient(circle at 75% 75%, white 2px, transparent 2px);
      background-size: 50px 50px;
    }

    .login-container {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 400px;
      opacity: 0;
      transform: translateY(20px);
      transition: all 0.6s ease-out;
    }

    .login-container.show {
      opacity: 1;
      transform: translateY(0);
    }

    .company-header {
      text-align: center;
      margin-bottom: 2rem;
    }

    .company-logo {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 64px;
      height: 64px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      margin-bottom: 1rem;
      animation: floating 3s ease-in-out infinite;
    }

    .company-logo i {
      font-size: 1.5rem;
      color: white;
    }

    .company-title {
      font-size: 1.875rem;
      font-weight: 700;
      color: white;
      margin-bottom: 0.5rem;
    }

    .company-subtitle {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1rem;
    }

    .login-box {
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 1rem;
      padding: 2rem;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .status-indicator {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.5rem;
    }

    .status-dot {
      width: 8px;
      height: 8px;
      background: #4ade80;
      border-radius: 50%;
      margin-right: 0.5rem;
      animation: pulse-dot 2s infinite;
    }

    .status-text {
      color: white;
      font-size: 0.875rem;
      font-weight: 500;
    }

    .alert {
      background: rgba(239, 68, 68, 0.2);
      border: 1px solid rgba(239, 68, 68, 0.4);
      border-radius: 0.5rem;
      padding: 0.75rem 1rem;
      margin-bottom: 1.5rem;
      color: #fecaca;
      font-size: 0.875rem;
      display: flex;
      align-items: center;
      animation: fadeIn 0.5s ease-in-out;
    }

    .alert i {
      margin-right: 0.5rem;
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-label {
      display: block;
      color: white;
      font-size: 0.875rem;
      font-weight: 500;
      margin-bottom: 0.5rem;
    }

    .form-label i {
      margin-right: 0.5rem;
    }

    .form-input {
      width: 100%;
      padding: 0.75rem 1rem;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 0.5rem;
      color: white;
      font-size: 1rem;
      transition: all 0.2s ease;
    }

    .form-input:focus {
      outline: none;
      border-color: rgba(255, 255, 255, 0.5);
      box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
    }

    .form-input::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .submit-btn {
      width: 100%;
      background: rgba(255, 255, 255, 0.2);
      color: white;
      font-weight: 600;
      padding: 0.75rem 1rem;
      border: none;
      border-radius: 0.5rem;
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.2s ease;
      transform: scale(1);
    }

    .submit-btn:hover:not(:disabled) {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.02);
    }

    .submit-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .submit-btn:focus {
      outline: none;
      box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
    }

    .submit-btn i {
      margin-right: 0.5rem;
    }

    .security-notice {
      margin-top: 1.5rem;
      padding: 0.75rem;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 0.5rem;
      display: flex;
      align-items: flex-start;
    }

    .security-notice i {
      color: #93c5fd;
      margin-right: 0.5rem;
      margin-top: 0.125rem;
      flex-shrink: 0;
    }

    .security-notice-content {
      font-size: 0.75rem;
      color: rgba(255, 255, 255, 0.8);
      width: 100%;
    }

    .security-notice-title {
      font-weight: 500;
      margin-bottom: 0.25rem;
      color: white;
    }

    .token-box {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 0.375rem;
      padding: 0.5rem;
      margin-top: 0.5rem;
      position: relative;
      overflow: hidden;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .token-box:hover {
      background: rgba(0, 0, 0, 0.3);
    }

    .token-content {
      display: flex;
      align-items: center;
      font-family: 'Courier New', monospace;
      font-size: 0.7rem;
      color: rgba(255, 255, 255, 0.9);
    }

    .token-label {
      margin-right: 0.5rem;
      white-space: nowrap;
    }

    .token-value {
      position: relative;
    }

    .token-text-partial,
    .token-text-full {
      transition: opacity 0.3s;
    }

    .token-text-full {
      position: absolute;
      left: 0;
      top: 0;
      opacity: 0;
      white-space: nowrap;
    }

    .token-box:hover .token-text-partial {
      opacity: 0;
    }

    .token-box:hover .token-text-full {
      opacity: 1;
    }

    .token-shimmer {
      position: absolute;
      top: 0;
      left: -100%;
      right: 0;
      bottom: 0;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      opacity: 0;
      transition: opacity 0.3s;
    }

    .token-box:hover .token-shimmer {
      opacity: 1;
      animation: shimmer 2s infinite;
    }

    .footer {
      text-align: center;
      margin-top: 2rem;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.875rem;
    }

    .footer-links {
      display: flex;
      justify-content: center;
      gap: 1rem;
      margin-top: 0.5rem;
    }

    .footer-links a {
      color: rgba(255, 255, 255, 0.6);
      text-decoration: none;
      transition: color 0.2s ease;
    }

    .footer-links a:hover {
      color: white;
    }

    @keyframes floating {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-10px); }
    }

    @keyframes pulse-dot {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }

    @keyframes fadeIn {
      from { 
        opacity: 0; 
        transform: translateY(-10px); 
      }
      to { 
        opacity: 1; 
        transform: translateY(0); 
      }
    }

    @keyframes shimmer {
      0% { transform: translateX(-100%); }
      100% { transform: translateX(100%); }
    }

    @media (max-width: 480px) {
      body {
        padding: 0.5rem;
      }
      
      .login-box {
        padding: 1.5rem;
      }
      
      .company-title {
        font-size: 1.5rem;
      }

      .token-content {
        font-size: 0.65rem;
      }
    }
  </style>
</head>
<body>
  <div class="bg-pattern"></div>

  <div class="login-container" id="loginContainer">
    
    <div class="company-header">
      <div class="company-logo">
        <i class="fas fa-shield-alt"></i>
      </div>
      <h1 class="company-title">ENGINE-X</h1>
      <p class="company-subtitle">Sites</p>
    </div>

    <div class="login-box">
      <div class="status-indicator">
        <div class="status-dot"></div>
        <span class="status-text">Sistema Activo</span>
      </div>

      <?php if ($mensaje): ?>
        <div class="alert" role="alert">
          <i class="fas fa-exclamation-circle"></i>
          <div><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      <?php endif; ?>
      
      <form method="POST" action="" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        
        <div class="form-group">
          <label for="username" class="form-label">
            <i class="fas fa-user"></i>Usuario
          </label>
          <input 
            type="text" 
            name="username"
            id="username"
            autocomplete="username"
            required
            maxlength="50"
            pattern="[a-zA-Z0-9_-]+"
            class="form-input"
            placeholder="Ingresa tu nombre de usuario"
            <?php echo ($_SESSION['login_attempts'] >= 5) ? 'disabled' : ''; ?>
          >
        </div>
        
        <div class="form-group">
          <label for="password" class="form-label">
            <i class="fas fa-lock"></i>Contraseña
          </label>
          <input 
            type="password" 
            name="password"
            id="password"
            autocomplete="current-password"
            required
            maxlength="100"
            class="form-input"
            placeholder="Ingresa tu contraseña"
            <?php echo ($_SESSION['login_attempts'] >= 5) ? 'disabled' : ''; ?>
          >
        </div>
        
        <button 
          type="submit" 
          class="submit-btn"
          <?php echo ($_SESSION['login_attempts'] >= 5) ? 'disabled' : ''; ?>
        >
          <i class="fas fa-sign-in-alt"></i>
          Iniciar Sesión
        </button>
      </form>
      
      <div class="security-notice">
        <i class="fas fa-shield-alt"></i>
        <div class="security-notice-content">
          <p class="security-notice-title">Activated</p>
          <p>Protect.</p>
          <div class="token-box">
            <div class="token-content">
              <span class="token-label">ID-TRUSTED:</span>
              <div class="token-value">
                <span class="token-text-partial">
                  <?php echo htmlspecialchars(substr($csrf_token, 0, 8), ENT_QUOTES, 'UTF-8'); ?>...<?php echo str_repeat("*", 16); ?>
                </span>
                <span class="token-text-full">
                  <?php echo htmlspecialchars(substr($csrf_token, 0, 16), ENT_QUOTES, 'UTF-8'); ?>...<?php echo htmlspecialchars(substr($csrf_token, -8), ENT_QUOTES, 'UTF-8'); ?>
                </span>
              </div>
            </div>
            <div class="token-shimmer"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="footer">
      <p>&copy; <?php echo date('Y'); ?> github/SoySH | Algunos derechos reservados.</p>
      <div class="footer-links">
        <a href="#" rel="noopener noreferrer">Términos</a>
        <a href="#" rel="noopener noreferrer">Privacidad</a>
        <a href="#" rel="noopener noreferrer">Soporte</a>
      </div>
    </div>
  </div>

  <script>
    'use strict';
    
    const loginContainer = document.getElementById('loginContainer');

    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(() => {
        loginContainer.classList.add('show');
      }, 200);
      
      // Protección básica contra auto-fill malicioso
      const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
      inputs.forEach(input => {
        input.addEventListener('input', function() {
          this.value = this.value.trim();
        });
      });
    });
  </script>
</body>
</html>
