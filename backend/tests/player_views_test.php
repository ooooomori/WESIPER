<?php
require dirname(__DIR__) . '/lib/player-views.php';
$assert = static function (bool $ok, string $label): void { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } };
$chrome = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
$assert(!playerViewIsBot($chrome), 'mobile safari counts');
foreach (['', 'Googlebot/2.1', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'curl/8.0', 'python-requests/2.31', 'HeadlessChrome/120'] as $agent) $assert(playerViewIsBot($agent), "bot ignored: $agent");
$a = playerViewVisitorHash('1.2.3.4', $chrome, '2026-10-02');
$assert(strlen($a) === 64 && ctype_xdigit($a), 'hash is 32-byte hex');
$assert($a === playerViewVisitorHash('1.2.3.4', $chrome, '2026-10-02'), 'same visitor same day');
$assert($a !== playerViewVisitorHash('1.2.3.4', $chrome, '2026-10-03'), 'new day new hash');
$assert($a !== playerViewVisitorHash('1.2.3.5', $chrome, '2026-10-02'), 'different ip different hash');
echo "player_views_test OK\n";
