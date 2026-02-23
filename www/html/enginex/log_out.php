<?php
session_start();
require_once 'functions.php';

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
         

// Eliminar token de autenticación si existe
if (isset($_COOKIE['auth_token'])) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Registrar actividad antes de cerrar sesión
        if (isset($_SESSION['usuario_id'])) {
            registrarActividad('logout', 'Cierre de sesión', $_SESSION['usuario_id'], $pdo);
            
            // Eliminar token
            eliminarTokenAutenticacion($_COOKIE['auth_token'], $pdo);
        }
        
        // Eliminar cookie
        setcookie('auth_token', '', time() - 3600, '/');
    } catch (PDOException $e) {
        // Error de conexión, continuar con el cierre de sesión
    }
}

// Destruir todas las variables de sesión
$_SESSION = array();

// Destruir la sesión
session_destroy();

// Redirigir al login
header('Location: home.php');
exit;
?>
