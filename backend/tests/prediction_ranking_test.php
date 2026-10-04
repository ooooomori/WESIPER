<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/predictionRanking.php';

function predictionTestRow(int $id, string $last, float $hit, float $homeRun, string $status = 'ready', ?string $name = null): array {
    return ['player_id' => $id, 'name' => $name ?? "선수$id", 'team' => 'LG', 'payload' => json_encode([
        'status' => $status, 'last_player_date' => $last, 'current' => ['avg' => 0.3],
        'probabilities' => ['hit' => $hit, 'home_run' => $homeRun]])];
}
function predictionTestAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$asOf = '2026-10-01';
$rows = [
    predictionTestRow(1, '2026-10-01', 0.70, 0.10),
    predictionTestRow(1, '2026-10-01', 0.10, 0.90),              // 같은 선수의 더 오래된 예측: 무시
    predictionTestRow(2, '2026-09-01', 0.70, 0.05),              // 기준일 30일 전 당일: 포함
    predictionTestRow(3, '2026-08-31', 0.99, 0.99),              // 31일 전: 제외
    predictionTestRow(4, '2026-10-01', 0.95, 0.95, 'insufficient_data'),
    predictionTestRow(5, '2026-09-30', 0.60, 0.20),
    predictionTestRow(6, '2026-09-30', 1.20, 0.20),              // 범위를 벗어난 확률: 제외
    ['player_id' => 7, 'name' => 'x', 'team' => null, 'payload' => 'not json'],
];
$players = predictionRankingPlayers($rows, $asOf);
predictionTestAssert(array_column($players, 'PlayerId') === ['1', '2', '5'], 'Eligible players mismatch: ' . json_encode(array_column($players, 'PlayerId')));
predictionTestAssert($players[0]['hit'] === 0.70, 'Newest prediction per player must win');

$hit = predictionRankingTop($players, 'hit', 10);
predictionTestAssert(array_column($hit, 'rank') === [1, 1, 3], 'Tied probabilities must share a rank');
predictionTestAssert(array_column($hit, 'PlayerId') === ['1', '2', '5'], 'Ties must be ordered by player id');
$homeRun = predictionRankingTop($players, 'home_run', 2);
predictionTestAssert(array_column($homeRun, 'PlayerId') === ['5', '1'], 'Home run order or limit mismatch');
predictionTestAssert($homeRun[0]['probability'] === 0.20 && !isset($homeRun[0]['hit']), 'Row shape mismatch');
predictionTestAssert(predictionRankingTop([], 'hit', 10) === [], 'Empty input must give an empty ranking');
echo "PASS: newest prediction per player, 30-day activity cutoff, status and range filters, tie ranks, limit\n";

// 검색: 순위 대상은 전체 순위·확률, 빠진 선수는 이유
$rows = [
    predictionTestRow(1, '2026-10-01', 0.70, 0.10, 'ready', '김도영'),
    predictionTestRow(2, '2026-10-01', 0.80, 0.05, 'ready', '김도'),
    predictionTestRow(3, '2026-08-01', 0.99, 0.99, 'ready', '김도현'),             // 최근 출전 없음
    predictionTestRow(4, '2026-10-01', 0.50, 0.50, 'insufficient_data', '박김도'),
    predictionTestRow(5, '2026-10-01', 0.90, 0.30, 'ready', '최정'),
];
$players = predictionRankingPlayers($rows, $asOf, $excluded);
predictionTestAssert(array_column($excluded, 'reason', 'PlayerId') == ['3' => 'inactive', '4' => 'insufficient'], 'Exclusion reasons mismatch: ' . json_encode($excluded));
$found = predictionRankingSearch($players, $excluded, '김도', 8);
predictionTestAssert(array_column($found, 'Name') === ['김도', '김도영', '김도현', '박김도'], 'Search order mismatch: ' . json_encode(array_column($found, 'Name'), JSON_UNESCAPED_UNICODE));
predictionTestAssert($found[0]['hit'] === ['rank' => 2, 'probability' => 0.80] && $found[0]['homeRun'] === ['rank' => 3, 'probability' => 0.05], 'Overall ranks must come from the full ranking');
predictionTestAssert($found[2]['status'] === 'inactive' && $found[2]['lastPlayed'] === '2026-08-01', 'Inactive player shape mismatch');
// 순위에서 빠져도 계산된 확률은 순위 없이 돌려준다.
predictionTestAssert($found[2]['hit'] === ['rank' => null, 'probability' => 0.99] && $found[2]['homeRun'] === ['rank' => null, 'probability' => 0.99] && $found[2]['avg'] === 0.3, 'Inactive player must keep its chances without a rank');
predictionTestAssert($found[3]['hit'] === null && $found[3]['homeRun'] === null, 'A player without any computed chance must have none');
predictionTestAssert($found[3]['status'] === 'insufficient', 'Insufficient player status mismatch');
predictionTestAssert(count(predictionRankingSearch($players, $excluded, '김도', 2)) === 2, 'Search limit mismatch');
predictionTestAssert(predictionRankingSearch($players, $excluded, '없는선수', 8) === [], 'Unknown name must return no matches');
echo "PASS: search by name with overall ranks, exclusion reasons, exact-name priority and limit\n";
$found = predictionRankingSearch($players, $excluded, '김도현', 8);
predictionTestAssert(array_column($found, 'Name') === ['김도현'], 'Exact-name search mismatch');
$rows[] = predictionTestRow(6, '2026-10-01', 0.10, 0.10, 'ready', '김도현수');
$players = predictionRankingPlayers($rows, $asOf, $excluded);
predictionTestAssert(array_column(predictionRankingSearch($players, $excluded, '김도현', 8), 'Name') === ['김도현', '김도현수'], 'An excluded exact-name match must still come first');
echo "PASS: excluded exact-name match is listed first\n";

// 표본 부족: 예측 배치가 따로 저장한 보정 확률을 쓴다.
$lowSample = ['player_id' => 9, 'name' => '신인', 'team' => 'KT', 'payload' => json_encode(['status' => 'insufficient_data', 'last_player_date' => '2026-10-01',
    'current' => ['avg' => 0.0], 'probabilities' => null, 'low_sample_probabilities' => ['hit' => 0.61, 'home_run' => 0.07]])];
$invalid = ['player_id' => 10, 'name' => '신인왕', 'team' => 'KT', 'payload' => json_encode(['status' => 'insufficient_data', 'last_player_date' => '2026-10-01',
    'probabilities' => null, 'low_sample_probabilities' => ['hit' => 1.5, 'home_run' => 0.07]])];
$players = predictionRankingPlayers([$lowSample, $invalid, predictionTestRow(1, '2026-10-01', 0.70, 0.10, 'ready', '김도영')], $asOf, $excluded);
predictionTestAssert(array_column($players, 'PlayerId') === ['1'], 'Low-sample players must stay out of the ranking');
$found = predictionRankingSearch($players, $excluded, '신인', 8);
predictionTestAssert($found[0]['status'] === 'insufficient' && $found[0]['hit'] === ['rank' => null, 'probability' => 0.61] && $found[0]['homeRun'] === ['rank' => null, 'probability' => 0.07], 'Low-sample chances mismatch');
predictionTestAssert($found[1]['hit'] === null, 'Out-of-range low-sample chances must be dropped');
echo "PASS: excluded players keep computed chances without a rank; low-sample chances come from the batch
";

// 최근 흐름: 경기는 최근 것부터. 타수 없는 경기는 건너뛴다.
$game = static fn(int $ab, int $h, int $hr = 0): array => ['ab' => $ab, 'h' => $h, 'hr' => $hr];
predictionTestAssert(predictionSeasonForm([]) === ['homeRuns' => 0, 'hitStreak' => 0, 'hitGamesAgo' => null, 'homeRunStreak' => 0, 'homeRunGamesAgo' => null], 'Empty season form mismatch');
predictionTestAssert(predictionSeasonForm([$game(4, 2, 1), $game(0, 0), $game(3, 1, 1), $game(4, 1), $game(4, 0), $game(4, 3, 2)])
    === ['homeRuns' => 4, 'hitStreak' => 3, 'hitGamesAgo' => 1, 'homeRunStreak' => 2, 'homeRunGamesAgo' => 1], 'Streaks must skip games without an at-bat');
predictionTestAssert(predictionSeasonForm([$game(4, 0), $game(3, 2), $game(4, 1, 1)])
    === ['homeRuns' => 1, 'hitStreak' => 0, 'hitGamesAgo' => 2, 'homeRunStreak' => 0, 'homeRunGamesAgo' => 3], 'Last hit and home run distance mismatch');
predictionTestAssert(predictionSeasonForm([$game(4, 0), $game(0, 0), $game(3, 0)])['hitGamesAgo'] === null, 'No hit this season must give null');
predictionTestAssert(predictionSeasonForm([$game(4, 1), $game(4, 1)])['homeRunGamesAgo'] === null, 'No home run this season must give null');
echo "PASS: season form (hit streak, home run streak, games since last home run)\n";
