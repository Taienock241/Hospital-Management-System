<?php
declare(strict_types=1);

$appEnv = getenv('APP_ENV') ?: 'development';
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

session_name('HOPEWELLSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $appEnv === 'production' || $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
} elseif (time() - (int) $_SESSION['last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}
$_SESSION['last_activity'] = time();
