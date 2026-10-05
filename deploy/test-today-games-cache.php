<?php
require $argv[1];
$now = strtotime('2026-09-27 16:50:00 +0900');
function check($condition) { if (!$condition) throw new RuntimeException('Cache assertion failed'); }
$before = [['GAME_STATE_SC'=>'1', 'G_TM'=>'17:00']];
$midnightAfter = strtotime('2026-09-28 00:00:00 +0900');
$at = static fn(string $time) => strtotime("2026-09-27 $time:00 +0900");
// 시작 30분 전에 한 번, 그다음은 시작 3시간 뒤, 이후 끝날 때까지 30분 간격. 경기 중에는 묻지 않는다.
check(todayGamesExpiry([['GAME_STATE_SC'=>'1','G_TM'=>'18:30']],$at('09:00')) === $at('18:00'));
check(todayGamesExpiry([['GAME_STATE_SC'=>'1','G_TM'=>'18:30']],$at('18:00')) === $at('21:30'));
check(todayGamesExpiry([['GAME_STATE_SC'=>'2','G_TM'=>'18:30']],$at('19:45')) === $at('21:30'));
check(todayGamesExpiry([['GAME_STATE_SC'=>'2','G_TM'=>'18:30']],$at('21:30')) === $at('22:00'));
check(todayGamesExpiry($before,$now) === $at('20:00'));
// 여러 경기: 가장 빠른 다음 확인 시각을 따른다. 끝났거나 취소된 경기는 더 묻지 않는다.
check(todayGamesExpiry([['GAME_STATE_SC'=>'1','G_TM'=>'14:00'],['GAME_STATE_SC'=>'1','G_TM'=>'17:00']],$at('13:30')) === $at('16:30'));
check(todayGamesExpiry([['GAME_STATE_SC'=>'3','G_TM'=>'14:00'],['GAME_STATE_SC'=>'2','G_TM'=>'17:00']],$at('17:10')) === $at('20:00'));
check(todayGamesExpiry([['GAME_STATE_SC'=>'3'],['GAME_STATE_SC'=>'4']],$now) === $midnightAfter);
check(todayGamesExpiry([],$now) === $midnightAfter);
check(todayGamesExpiry([['GAME_STATE_SC'=>'1','G_TM'=>'']],$now) === $now+1800);
check(todayGamesExpiry([['GAME_STATE_SC'=>'2','G_TM'=>'22:00']],$at('23:50')) === $midnightAfter);
// 화면 상태: 진행 중인 경기는 예정 경기처럼 보이고 점수를 내보내지 않는다.
check(todayGamesPublicState(['GAME_STATE_SC'=>'2','G_TM'=>'18:30','T_SCORE_CN'=>'3','B_SCORE_CN'=>'1'])
    === ['state'=>'scheduled','code'=>'1','status'=>'18:30','away_score'=>'','home_score'=>'']);
check(todayGamesPublicState(['GAME_STATE_SC'=>'1','G_TM'=>'18:30'])['state'] === 'scheduled');
check(todayGamesPublicState(['GAME_STATE_SC'=>'3','G_TM'=>'18:30','T_SCORE_CN'=>'3','B_SCORE_CN'=>'1'])
    === ['state'=>'final','code'=>'3','status'=>'종료','away_score'=>'3','home_score'=>'1']);
check(todayGamesPublicState(['GAME_STATE_SC'=>'4','CANCEL_SC_NM'=>'우천취소'])['status'] === '우천취소');
check(todayGamesPublicState(['GAME_STATE_SC'=>'4'])['status'] === '경기 취소');
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
    // 경기 중(17:00 시작, 20:00 전)에는 KBO에 다시 묻지 않는다.
    cachedTodayGames('kbo',$fail,$at('19:00'),$directory);
    check($calls === 2);
    $stale = cachedTodayGames('kbo',$fail,$at('20:00'),$directory);
    check($stale['stale'] && $stale['games'] === $before);
    cachedTodayGames('kbo',$fail,$at('20:00')+299,$directory);
    check($calls === 3);
    cachedTodayGames('kbo',$load,$at('20:00')+301,$directory);
    check($calls === 4);
    cachedTodayGames('kbo',$load,$now+86400,$directory);
    check($calls === 5);
    echo "PASS: check schedule, public state, cache hit, league isolation, no in-game refresh, stale fallback, retry, date rollover\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
