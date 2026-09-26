<?php
require $argv[1];
$now = strtotime('2026-09-27 16:50:00 +0900');
function check($condition) { if (!$condition) throw new RuntimeException('Cache assertion failed'); }
$before = [['GAME_STATE_SC'=>'1', 'G_TM'=>'17:00']];
check(todayGamesExpiry($before, $now) === $now + 600);
check(todayGamesExpiry([['GAME_STATE_SC'=>'1','G_TM'=>'18:30']],$now) === $now+1800);
check(todayGamesExpiry([['GAME_STATE_SC'=>'2']],$now) === $now+30);
check(todayGamesExpiry([['GAME_STATE_SC'=>'3']],$now) === $now+1800);
check(todayGamesExpiry([],$now) === $now+1800);
check(todayGamesExpiry($before,$now+600) === $now+630);
$midnight = strtotime('2026-09-27 23:55:00 +0900');
check(todayGamesExpiry([],$midnight) === $midnight+300);
$directory = sys_get_temp_dir() . '/wesiper-cache-test-' . bin2hex(random_bytes(8));
$calls = 0;
$load = function ($day) use (&$calls,$before) { $calls++; return $before; };
try {
    cachedTodayGames('kbo',$load,$now,$directory);
    cachedTodayGames('kbo',$load,$now+1,$directory);
    check($calls === 1);
    cachedTodayGames('futures',$load,$now+1,$directory);
    check($calls === 2);
    $fail = function ($day) use (&$calls) { $calls++; throw new RuntimeException('Expected upstream test failure'); };
    $stale = cachedTodayGames('kbo',$fail,$now+600,$directory);
    check($stale['stale'] && $stale['games'] === $before);
    cachedTodayGames('kbo',$fail,$now+601,$directory);
    check($calls === 3);
    cachedTodayGames('kbo',$load,$now+631,$directory);
    check($calls === 4);
    cachedTodayGames('kbo',$load,$now+86400,$directory);
    check($calls === 5);
    echo "PASS: TTL, kickoff, midnight, cache hit, league isolation, stale fallback, retry, date rollover\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
