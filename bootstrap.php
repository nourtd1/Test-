<?php
declare(strict_types=1);

// Lightweight PSR-4 autoloader for App\* classes (fallback when Composer is absent)
spl_autoload_register(static function(string $class): void {
    if (strpos($class, 'App\\') !== 0) { return; }
    $relative = str_replace('App\\', 'src\\', $class) . '.php';
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    if (is_file($path)) { require $path; }
});

// Start session early
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

use App\Support\Csrf;

// Ensure CSRF token exists
Csrf::ensureToken();

// Error reporting & logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
ini_set('error_log', $logDir . '/app.log');

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer-when-downgrade");
header("Permissions-Policy: microphone=*\, geolocation=()\, camera=()");
$csp = [
    "default-src 'self'",
    "script-src 'self' https://cdn.jsdelivr.net",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
    "font-src 'self' https://fonts.gstatic.com",
    "img-src 'self' data:",
    "connect-src 'self'",
    "frame-ancestors 'none'",
    "base-uri 'self'",
    "form-action 'self'"
];
header('Content-Security-Policy: ' . implode('; ', $csp));

