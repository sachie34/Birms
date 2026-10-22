<?php
/**
 * app/bootstrap.php  Every web page starts with: require __DIR__ . '/../app/bootstrap.php';
 * Loads everything, sets safe error handling, security headers and the session.
 */
declare(strict_types=1);

require __DIR__ . '/core.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/permissions.php';
require __DIR__ . '/attachments_lib.php';
require __DIR__ . '/menu.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/lookups.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/residents_lib.php';
require __DIR__ . '/residents_import_lib.php';
require __DIR__ . '/requests_lib.php';
require __DIR__ . '/certificates_lib.php';
require __DIR__ . '/blotters_lib.php';
require __DIR__ . '/reports_lib.php';

date_default_timezone_set((string) config('timezone', 'Asia/Manila'));

// Errors are logged to storage/logs and never shown to users (unless env = development).
$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php-error.log');
ini_set('display_errors', config('env') === 'development' ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('BIRMS uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    echo config('env') === 'development'
        ? '<pre>' . e((string) $e) . '</pre>'
        : '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif">Something went wrong. Please try again or contact the administrator.</p>';
});

function send_security_headers(): void
{
    if (headers_sent()) { return; }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store');
    if (config('security.csp', true)) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    }
}

send_security_headers();
session_boot();
