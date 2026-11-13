<?php

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'Northstar Employee Attendance');
define('APP_TIMEZONE', 'Africa/Accra');
define('APP_DEMO', false);
define('APP_SESSION_LIFETIME', 20 * 60);

date_default_timezone_set(APP_TIMEZONE);

/* Security response headers */

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

/* Session security configuration */

$secure = (
    !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off'
);

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    ini_set(
        'session.gc_maxlifetime',
        (string) (APP_SESSION_LIFETIME * 6)
    );

    session_name('northstar_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* Load application components */

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/Throttle.php';
require_once APP_ROOT . '/app/Auth.php';
require_once APP_ROOT . '/app/AttendanceService.php';

/* Administrator inactivity timeout */

if (
    Auth::check()
    && isset($_SESSION['_last_activity'])
    && time() - (int) $_SESSION['_last_activity']
        > APP_SESSION_LIFETIME
) {
    Auth::logout(false);

    session_start();

    flash(
        'error',
        'Your session expired due to inactivity. Please sign in again.'
    );
}

$_SESSION['_last_activity'] = time();

/* CSRF protection */

if (!isset($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
