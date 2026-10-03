<?php
// php deploy/test-live-results.php backend/lib/kbo-live-results.php backend/lib/team-standings.php
require $argv[1];
require $argv[2];
function check($condition, $label) { if (!$condition) throw new RuntimeException('Live results assertion failed: ' . $label); }
$game = fn(array $over = []) => $over + ['LE_ID'=>1, 'SR_ID'=>0, 'G_ID'=>'20261003HTLG0', 'G_DT'=>'20261003', 'GAME_STATE_SC'=>'3',
    'AWAY_NM'=>'KIA', 'HOME_NM'=>'LG', 'T_SCORE_CN'=>'5', 'B_SCORE_CN'=>'3'];
$results = liveResultsFromGames([
    $game(),
    $game(['G_ID'=>'20261003SKHH0', 'AWAY_NM'=>'SSG', 'HOME_NM'=>'한화', 'GAME_STATE_SC'=>'2']),   // 진행 중
    $game(['G_ID'=>'20261003OBSS0', 'AWAY_NM'=>'두산', 'HOME_NM'=>'삼성', 'GAME_STATE_SC'=>'4']),  // 취소
    $game(['G_ID'=>'20261003NCWO0', 'AWAY_NM'=>'NC', 'HOME_NM'=>'키움', 'SR_ID'=>5]),               // 포스트시즌
    $game(['G_ID'=>'66661003KTLT0', 'AWAY_NM'=>'KT', 'HOME_NM'=>'롯데']),                           // 순위 결정전 가상 코드
    $game(['G_ID'=>'20261003KTLT0', 'AWAY_NM'=>'KT', 'HOME_NM'=>'롯데', 'T_SCORE_CN'=>'']),         // 점수 없음
    $game(['G_ID'=>'20261003KTLT1', 'AWAY_NM'=>'드림', 'HOME_NM'=>'롯데']),                         // 모르는 팀
]);
check(array_keys($results) === ['20261003HTLG0'], 'only finished regular-season games');
check($results['20261003HTLG0'] === ['game_code'=>'20261003HTLG0', 'game_date'=>'2026-10-03', 'away_team'=>'KIA', 'home_team'=>'LG', 'away_score'=>5, 'home_score'=>3], 'row shape');

$stored = [
    ['game_code'=>'20261001HTLG0', 'game_date'=>'2026-10-01', 'away_team'=>'KIA', 'home_team'=>'LG', 'away_score'=>1, 'home_score'=>2],
    ['game_code'=>'20261003HTLG0', 'game_date'=>'2026-10-03', 'away_team'=>'KIA', 'home_team'=>'LG', 'away_score'=>null, 'home_score'=>null],
];
$merged = mergeLiveResults($stored, $results, '2026-03-28', '2026-10-10');
check(count($merged) === 2 && $merged[1]['away_score'] === 5, 'pending fills an unscored game once');
// 크롤러가 같은 경기를 저장한 뒤에는 DB 값만 쓰고 두 번 세지 않는다.
$stored[1]['away_score'] = 6; $stored[1]['home_score'] = 3;
$merged = mergeLiveResults($stored, $results, '2026-03-28', '2026-10-10');
check(count($merged) === 2 && $merged[1]['away_score'] === 6, 'crawler result wins without double counting');
check(count(mergeLiveResults([$stored[0]], $results, '2026-03-28', '2026-10-02')) === 1, 'outside the regular season is ignored');
$rows = calculateStandings(mergeLiveResults([$stored[0]], $results, '2026-03-28', '2026-10-10'));
$byTeam = []; foreach ($rows as $row) { $values = array_column($row['row'], 'Text'); $byTeam[$values[1]] = $values; }
check(array_slice($byTeam['KIA'], 2, 4) === ['2','1','1','0'] && $byTeam['KIA'][9] === '패승', 'standings include the live result in order');

$directory = sys_get_temp_dir() . '/wesiper-live-test-' . bin2hex(random_bytes(8));
$now = strtotime('2026-10-03 22:10:00 +0900');
try {
    check(pendingLiveResults($directory) === [], 'empty store');
    recordLiveResults($results, $now, $directory);
    recordLiveResults([], $now, $directory);
    check(array_keys(pendingLiveResults($directory)) === ['20261003HTLG0'], 'saved');
    // 자정이 지나 오늘 목록이 비어도 남아 있고, 다음 날 결과가 더해진다.
    check(liveResultsFromGames([$game(['G_ID'=>'20261004HTLG0'])]) === [], 'code and game date must agree');
    $next = liveResultsFromGames([$game(['G_ID'=>'20261004HTLG0', 'G_DT'=>'20261004'])]);
    recordLiveResults($next, $now + 86400, $directory);
    check(array_keys(pendingLiveResults($directory)) === ['20261003HTLG0', '20261004HTLG0'], 'accumulates across days');
    recordLiveResults($next, $now + 8 * 86400, $directory);
    check(array_keys(pendingLiveResults($directory)) === ['20261004HTLG0'], 'old results expire');
    echo "PASS: finished-only filter, merge without double counting, season bounds, standings order, persistence and expiry\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
