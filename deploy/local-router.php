<?php
// Serve local API entrypoints only; keep configs, libraries and scripts private.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || !preg_match('#^/api/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$#D', $path)
    || preg_match('#/(?:common|Snoopy\.class|player_cache)\.php$#', $path)) {
    http_response_code(404);
    exit;
}
$root = realpath(__DIR__ . '/../backend/api');
$file = realpath(__DIR__ . '/../backend' . $path);
if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}
require $file;
