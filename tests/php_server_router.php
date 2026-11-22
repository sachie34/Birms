<?php
/**
 * CI-only router for PHP's development server.
 *
 * The real deployment boundary is Apache's DocumentRoot=public plus the checked-in
 * private-directory .htaccess rules. PHP's built-in server ignores .htaccess, so CI
 * must not accidentally expose sibling source/storage directories while testing.
 */
declare(strict_types=1);

$publicRoot = realpath(__DIR__ . '/../public');
if ($publicRoot === false) {
    http_response_code(500);
    exit;
}

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($requestPath)) {
    http_response_code(400);
    exit;
}

// Decode a few times to catch nested URL encoding before checking path segments.
$decodedPath = $requestPath;
for ($i = 0; $i < 3; $i++) {
    $next = rawurldecode($decodedPath);
    if ($next === $decodedPath) { break; }
    $decodedPath = $next;
}
$normalizedPath = str_replace('\\', '/', $decodedPath);

if (str_contains($normalizedPath, chr(0))
    || preg_match('~(?:^|/)(?:app|database|docs|storage|tests|tools)(?:/|$)~i', $normalizedPath) === 1) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

$candidate = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($normalizedPath, '/'));
if ($candidate !== false
    && $candidate !== $publicRoot
    && !str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

return false;
