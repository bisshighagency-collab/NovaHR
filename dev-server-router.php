<?php
/**
 * Convenience router for local development with PHP's built-in server, so the
 * whole app (frontend + backend) can be served from a single origin:
 *
 *   php -S localhost:8080 dev-server-router.php
 *
 * Requests to /api/* are handed to the backend; everything else is served as
 * a static file from frontend/. This file is NOT needed for a real Apache/
 * Nginx deployment (see README.md) — it only exists to make `php -S` usable
 * for quick local testing without a virtual host.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/backend/index.php';
    return true;
}

$path = __DIR__ . '/frontend' . $uri;
if ($uri !== '/' && file_exists($path) && !is_dir($path)) {
    return false; // let the built-in server serve the static file as-is
}

if (is_dir($path)) {
    $path = rtrim($path, '/') . '/index.html';
    if (file_exists($path)) {
        readfile($path);
        return true;
    }
}

readfile(__DIR__ . '/frontend/index.html');
return true;
