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
