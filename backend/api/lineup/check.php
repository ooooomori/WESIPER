<?php
// 라인업 맞추기: 제출한 9칸을 채점한다. 다 맞혔거나 포기(reveal)하면 정답 라인업도 함께 내준다.
// 판이 끝나면(다 맞힘·정답 보기·제출 후 건너뛰기 abandon) 문제별 통계에 결과를 남기고, 그 문제의 통계를 함께 내준다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$body = json_decode(file_get_contents('php://input') ?: '', true);
$code = is_array($body) ? (string)($body['game'] ?? '') : '';
$team = is_array($body) ? (string)($body['team'] ?? '') : '';
$picks = is_array($body) && is_array($body['picks'] ?? null) ? array_values($body['picks']) : [];
$reveal = is_array($body) && !empty($body['reveal']);
$abandon = is_array($body) && !empty($body['abandon']);
$daily = is_array($body) && !empty($body['daily']);
$difficulty = is_array($body) ? (string)($body['difficulty'] ?? '') : '';
$client = is_array($body) ? (string)($body['client'] ?? '') : '';
$attempts = is_array($body) ? (int)($body['attempts'] ?? 0) : 0;
$milliseconds = is_array($body) && is_numeric($body['milliseconds'] ?? null) ? (int)$body['milliseconds'] : null;
$validPicks = count($picks) === 9 && !array_filter($picks, static fn($pick) => $pick !== null && !(is_int($pick) && $pick > 0));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !preg_match('/^[0-9A-Z]{13,17}$/D', $code) || $team === '' || !$validPicks) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../kbocandle/common.php';
    require_once __DIR__ . '/../../lib/lineup-game.php';
    $game = lineupGameByCode($pdo, $code);
    $lineup = $game !== null && (lineupSameTeam($game['away_team'], $team) || lineupSameTeam($game['home_team'], $team))
        ? lineupForTeam($pdo, $game, $team) : null;
    if ($lineup === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '경기를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $side = lineupSameTeam($game['away_team'], $team) ? 'away' : 'home';
    if ($abandon) {
        lineupRecordResult($pdo, $code, $side, $difficulty, $client, false, $attempts, null);
        echo json_encode(['success' => true]);
        exit;
    }
    $results = lineupGrade($lineup['starters'], $picks);
    $solved = count(array_keys($results, 'correct', true)) === 9;
    $response = ['success' => true, 'results' => $results, 'solved' => $solved];
    if ($solved || $reveal) {
        lineupRecordResult($pdo, $code, $side, $difficulty, $client, $solved, $attempts, $solved ? $milliseconds : null);
        $response['stats'] = in_array($difficulty, LINEUP_DIFFICULTIES, true) ? lineupPuzzleStats($pdo, $code, $side, $difficulty) : null;
        // 오늘의 라인업으로 풀었다고 하면, 정말 오늘의 문제인지 확인한 뒤 랭킹에 올린다.
        if ($daily && $difficulty === LINEUP_DAILY_DIFFICULTY) {
            $today = date('Y-m-d');
            [$dailyGame] = lineupDailyGame($pdo, getKBOSchedule(), $today) ?? [null];
            if ($dailyGame !== null && $dailyGame['game_code'] === $code && lineupSameTeam($dailyGame['team'], $team)) {
                lineupRecordDaily($pdo, $today, $client, $solved, $attempts, $solved ? $milliseconds : null);
            }
        }
        $stats = lineupSeasonStats($pdo, getKBOSchedule(), $game, $lineup['starters']);
        $response['answer'] = array_map(static fn($starter) => ['order' => $starter['order'], 'pos' => $starter['pos'], 'avg' => $stats[$starter['id']]['avg'] ?? null, 'ops' => $stats[$starter['id']]['ops'] ?? null, 'id' => $starter['id'], 'name' => $starter['name']], $lineup['starters']);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Lineup check failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '채점하지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
