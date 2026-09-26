<?php
// The private development server serves only this API, never config/library files.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/todayGames.php') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/api/todayGames.php';
