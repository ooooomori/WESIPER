<?php
// 라인업 맞추기 · 타자 연습: 조건(연도·팀)에 맞는 경기 하나의 양 팀 선발 라인업과 선발투수를 이름까지 그대로 내준다.
// 맞히는 게임이 아니라 따라 치는 연습이라 정답을 가리지 않는다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../kbocandle/common.php';
require_once __DIR__ . '/../../lib/lineup-game.php';

function lineupTypingFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$schedule = getKBOSchedule();
$yearInput = (string)($_GET['year'] ?? 'random');
$teamInput = (string)($_GET['team'] ?? 'random');
$year = $yearInput === 'random' ? null : (int)$yearInput;
$teamId = $teamInput === 'random' ? null : $teamInput;
if (($year !== null && (!preg_match('/^\d{4}$/D', $yearInput) || $year < LINEUP_FIRST_YEAR || $year > lineupLastYear($schedule)))
    || ($teamId !== null && !isset(LINEUP_TEAMS[$teamId]))) {
    lineupTypingFail(400, '잘못된 요청입니다.');
}

try {
    // 양 팀 모두 선발 9명과 선발투수가 온전히 있는 경기가 나올 때까지 다시 뽑는다.
    $sides = null;
    for ($attempt = 0; $attempt < 5 && $sides === null; $attempt++) {
        $game = lineupRandomGame($pdo, $schedule, $year, $teamId);
        if ($game === null) continue;
        $found = [];
        // 원정팀부터 친다.
        foreach (['away', 'home'] as $side) {
            $lineup = lineupForTeam($pdo, $game, $game[$side . '_team']);
            if ($lineup === null || !$lineup['starter']) continue 2;
            // 타자는 타석(L/R/S)과 경기 전 타율·OPS를, 투수는 던지는 손(L/R)과 경기 전 평균자책점을 함께 보여준다.
            $stats = lineupSeasonStats($pdo, $schedule, $game, $lineup['starters']);
            $players = array_map(static fn($starter) => ['label' => (string)$starter['order'], 'pos' => $starter['pos'], 'name' => $starter['name'],
                'bat' => $starter['bat'], 'avg' => $stats[$starter['id']]['avg'] ?? null, 'ops' => $stats[$starter['id']]['ops'] ?? null], $lineup['starters']);
            $players[] = ['label' => 'P', 'pos' => 'P', 'name' => $lineup['starter'], 'throw' => $lineup['starterThrow'], 'era' => lineupPitcherEra($pdo, $schedule, $game, $lineup['starterId'])];
            $found[] = ['side' => $side, 'team' => $game[$side . '_team'], 'score' => (int)$game[$side . '_score'], 'players' => $players];
        }
        $sides = $found;
    }
    if ($sides === null) lineupTypingFail(404, '조건에 맞는 경기를 찾지 못했습니다.');

    echo json_encode([
        'success' => true,
        'game' => ['code' => $game['game_code'], 'date' => $game['game_date'], 'stadium' => $game['stadium'], 'doubleheader' => lineupGameNumber($game['game_code']) ?: null],
        'sides' => $sides,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Lineup typing game failed: ' . $error->getMessage());
    lineupTypingFail(500, '경기를 불러오지 못했습니다.');
}
