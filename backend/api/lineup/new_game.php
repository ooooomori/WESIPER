<?php
// 라인업 맞추기: 조건(연도·팀·난이도)에 맞는 경기 하나와 빈 라인업을 내준다. 정답은 check.php에서만 확인한다.
// game(경기 코드)과 side(away|home)를 주면 무작위로 뽑지 않고 그 문제를 그대로 내준다(주소로 문제를 여는 경우).
// daily=1이면 오늘의 라인업(하루 한 문제, 난이도 보통)을 내준다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../kbocandle/common.php';
require_once __DIR__ . '/../../lib/lineup-game.php';

function lineupFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$schedule = getKBOSchedule();
$yearInput = (string)($_GET['year'] ?? 'random');
$teamInput = (string)($_GET['team'] ?? 'random');
$difficulty = (string)($_GET['difficulty'] ?? 'easy');
$daily = !empty($_GET['daily']);
if ($daily) $difficulty = LINEUP_DAILY_DIFFICULTY;
$today = date('Y-m-d');
$codeInput = $daily ? '' : (string)($_GET['game'] ?? '');
$sideInput = (string)($_GET['side'] ?? '');
$year = $yearInput === 'random' ? null : (int)$yearInput;
$teamId = $teamInput === 'random' ? null : $teamInput;
if (!in_array($difficulty, LINEUP_DIFFICULTIES, true)
    || ($year !== null && (!preg_match('/^\d{4}$/D', $yearInput) || $year < LINEUP_FIRST_YEAR || $year > lineupLastYear($schedule)))
    || ($teamId !== null && !isset(LINEUP_TEAMS[$teamId]))
    || ($codeInput !== '' && (!preg_match('/^[0-9A-Z]{13,17}$/D', $codeInput) || !in_array($sideInput, ['away', 'home'], true)))) {
    lineupFail(400, '잘못된 요청입니다.');
}

try {
    // 기록이 아직 들어오지 않은 경기가 뽑히면 다시 뽑는다.
    $game = null;
    $lineup = null;
    if ($codeInput !== '') {
        $game = lineupGameByCode($pdo, $codeInput);
        if ($game !== null && lineupIsRegularGame($schedule, $game)) {
            $game['team'] = $game[$sideInput . '_team'];
            $lineup = lineupForTeam($pdo, $game, $game['team']);
        }
    }
    if ($daily) [$game, $lineup] = lineupDailyGame($pdo, $schedule, $today) ?? [null, null];
    for ($attempt = 0; !$daily && $codeInput === '' && $attempt < 5 && $lineup === null; $attempt++) {
        $game = lineupRandomGame($pdo, $schedule, $year, $teamId);
        if ($game === null) continue;
        $lineup = lineupForTeam($pdo, $game, $game['team']);
    }
    if ($lineup === null) lineupFail(404, '조건에 맞는 경기를 찾지 못했습니다.');

    $isHome = $game['team'] === $game['home_team'];
    // 익스트림은 포지션과 시즌 기록(타율·OPS)을 가린다.
    $extreme = $difficulty === 'extreme';
    $stats = $extreme ? [] : lineupSeasonStats($pdo, $schedule, $game, $lineup['starters']);
    $typed = in_array($difficulty, ['hard', 'extreme'], true);
    $candidates = [];
    if (!$typed) {
        $pool = $difficulty === 'normal' ? [...$lineup['starters'], ...$lineup['subs']] : $lineup['starters'];
        // 후보에 동명이인이 있으면 출생연도로 구분할 수 있게 한다.
        $nameCounts = array_count_values(array_column($pool, 'name'));
        // 이름 입력으로 후보를 찾을 때 풀네임·개명 전 이름·별명으로도 찾을 수 있게 함께 내려준다.
        $nicknames = lineupPlayerNicknames($pdo, array_column($pool, 'id'));
        $candidates = array_map(static fn($player) => ['id' => $player['id'], 'name' => $player['name'],
            'aliases' => array_values(array_diff(array_unique([...$player['names'], ...($nicknames[$player['id']] ?? [])]), [$player['name']]))]
            + ($nameCounts[$player['name']] > 1 && $player['birthYear'] ? ['birthYear' => $player['birthYear']] : []), $pool);
        // 이름순으로 늘어놓아 타순을 짐작할 수 없게 한다.
        usort($candidates, static fn($a, $b) => strcmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
    }
    echo json_encode([
        'success' => true,
        'difficulty' => $difficulty,
        'daily' => $daily ? ['date' => $today, 'number' => lineupDailyNumber($today)] : null,
        'game' => [
            'code' => $game['game_code'],
            'date' => $game['game_date'],
            'stadium' => $game['stadium'],
            'team' => $game['team'],
            'opponent' => $isHome ? $game['away_team'] : $game['home_team'],
            'isHome' => $isHome,
            'side' => $isHome ? 'home' : 'away',
            // 더블헤더면 몇 차전인지(1·2), 아니면 null
            'doubleheader' => lineupGameNumber($game['game_code']) ?: null,
            'teamScore' => (int)$game[$isHome ? 'home_score' : 'away_score'],
            'opponentScore' => (int)$game[$isHome ? 'away_score' : 'home_score'],
            'starter' => $lineup['starter'],
            'opponentStarter' => $lineup['opponentStarter'],
        ],
        // 그날의 타석 결과는 누구인지 짐작하는 단서로 준다.
        'slots' => array_map(static fn($starter) => ['order' => $starter['order'], 'pos' => $extreme ? null : $starter['pos'], 'avg' => $stats[$starter['id']]['avg'] ?? null, 'ops' => $stats[$starter['id']]['ops'] ?? null,
            // 쉬움에서는 타자의 타석(L/R/S)도 단서로 준다.
            'bat' => $difficulty === 'easy' ? $starter['bat'] : null, 'records' => $starter['records']], $lineup['starters']),
        'candidates' => $candidates,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Lineup game failed: ' . $error->getMessage());
    lineupFail(500, '경기를 불러오지 못했습니다.');
}
