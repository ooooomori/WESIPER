<?php
// The private development server serves only this API, never config/library files.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!in_array($path, ['/api/todayGames.php', '/api/playerProfile.php', '/api/kbocandle/get_player_list.php', '/api/teamRank.php'], true)) {
    http_response_code(404);
    exit;
}
require __DIR__ . $path;
