<?php
require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');

$playerId = (string)($_GET['player_id'] ?? '');
$asOf = (string)($_GET['as_of_date'] ?? '');
$dataVersion = (string)($_GET['data_version'] ?? '');
if (!preg_match('/^[0-9]+$/', $playerId)
    || !preg_match('/^2026-[0-9]{2}-[0-9]{2}$/', $asOf)
    || !preg_match('/^[a-f0-9]{64}$/', $dataVersion)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '잘못된 예측 조회 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function predictionTeamCodes(PDO $pdo, array $playerIds): array {
    if (!$playerIds) return [];
    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
    $stmt = $pdo->prepare("SELECT player_id, game_id FROM kbo_player_game_stats
        WHERE season_year=2026 AND season_type='regular' AND player_id IN ($placeholders)
        ORDER BY player_id, game_date DESC, game_id DESC");
    $stmt->execute($playerIds);
    $candidates = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (string)$row['player_id'];
        $gameId = (string)$row['game_id'];
        if (strlen($gameId) < 12) continue;
        $pair = [substr($gameId, 8, 2), substr($gameId, 10, 2)];
        if (!isset($candidates[$id])) $candidates[$id] = $pair;
        elseif (count($candidates[$id]) > 1) {
            $intersection = array_values(array_intersect($candidates[$id], $pair));
            $candidates[$id] = $intersection;
        }
    }
    $teams = [];
    foreach ($candidates as $id => $codes) $teams[$id] = count($codes) === 1 ? $codes[0] : null;
    return $teams;
}

try {
    $revisionPath = getenv('WESIPER_CANDLE_REVISION_FILE') ?: '/tmp/wesiper-candle-data-revision';
    $revision = is_readable($revisionPath) ? trim(file_get_contents($revisionPath)) : 'initial';
    $stmt = $pdo->prepare("SELECT p.player_id, p.payload, pl.p_name, pl.p_img
        FROM kbo_player_predictions p
        LEFT JOIN kbo_playerlist_20250613 pl ON pl.p_no = p.player_id
        WHERE p.season_year=2026 AND p.season_type='regular'
          AND p.as_of_date=:as_of AND p.data_version=:data_version
          AND p.source_revision=:revision
          AND p.model_version='batting-distribution-v2-events'
        ORDER BY p.generated_at DESC");
    $stmt->execute(['as_of' => $asOf, 'data_version' => $dataVersion, 'revision' => $revision]);
    $players = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (string)$row['player_id'];
        if (isset($players[$id])) continue;
        $payload = json_decode($row['payload'], true);
        if (!is_array($payload) || ($payload['status'] ?? '') !== 'ready') continue;
        $generated = strtotime($payload['generated_at'] ?? '');
        if (!$generated || time() - $generated > 48 * 3600) continue;
        $hit = $payload['probabilities']['hit'] ?? null;
        $homeRun = $payload['probabilities']['home_run'] ?? null;
        if (!is_numeric($hit) || !is_numeric($homeRun)
            || $hit < 0 || $hit > 1 || $homeRun < 0 || $homeRun > 1) continue;
        $players[$id] = ['player_id' => $id, 'name' => $row['p_name'] ?: "#$id", 'img' => $row['p_img'] ?? '',
            'avg' => isset($payload['current']['avg']) ? (float)$payload['current']['avg'] : null,
            'hit' => (float)$hit, 'home_run' => (float)$homeRun];
    }
    if (!isset($players[$playerId])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => '현재 선수의 최신 예측 순위를 준비 중입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rankings = [];
    $shownIds = [];
    foreach (['hit', 'home_run'] as $metric) {
        $ordered = array_values($players);
        usort($ordered, fn($a, $b) => ($b[$metric] <=> $a[$metric]) ?: ((int)$a['player_id'] <=> (int)$b['player_id']));
        $rows = [];
        $lastValue = null;
        $rank = 0;
        foreach ($ordered as $index => $player) {
            if ($lastValue === null || $player[$metric] !== $lastValue) $rank = $index + 1;
            $lastValue = $player[$metric];
            if ($index < 10 || $player['player_id'] === $playerId) {
                $rows[] = ['rank' => $rank, 'player_id' => $player['player_id'],
                    'name' => $player['name'], 'img' => $player['img'], 'avg' => $player['avg'],
                    'probability' => $player[$metric]];
                $shownIds[$player['player_id']] = true;
            }
        }
        $rankings[$metric] = $rows;
    }
    $shownPlayerIds = array_keys($shownIds);
    $teams = predictionTeamCodes($pdo, $shownPlayerIds);
    $placeholders = implode(',', array_fill(0, count($shownPlayerIds), '?'));
    $homeRunStmt = $pdo->prepare("SELECT player_id, event_counts FROM kbo_player_game_stats
        WHERE season_year=2026 AND season_type='regular' AND game_date<=? AND data_version=?
          AND player_id IN ($placeholders)");
    $homeRunStmt->execute([$asOf, $dataVersion, ...$shownPlayerIds]);
    $homeRuns = array_fill_keys($shownPlayerIds, 0);
    while ($row = $homeRunStmt->fetch(PDO::FETCH_ASSOC)) {
        $counts = json_decode($row['event_counts'], true);
        $homeRuns[(string)$row['player_id']] += (int)($counts['HR'] ?? 0);
    }
    $nameStmt = $pdo->prepare("SELECT player_id, MAX(player_name) AS player_name
        FROM kbo_season_records FORCE INDEX (idx_player_date)
        WHERE player_id IN ($placeholders) AND game_date BETWEEN '2026-03-28' AND ?
        GROUP BY player_id");
    $nameStmt->execute([...$shownPlayerIds, $asOf]);
    $seasonNames = [];
    while ($row = $nameStmt->fetch(PDO::FETCH_ASSOC)) $seasonNames[(string)$row['player_id']] = $row['player_name'];
    foreach ($rankings as &$rows) {
        foreach ($rows as &$row) {
            $row['team_code'] = $teams[$row['player_id']] ?? null;
            $row['home_runs'] = $homeRuns[$row['player_id']] ?? 0;
            $row['name'] = $seasonNames[$row['player_id']] ?? $row['name'];
        }
        unset($row);
    }
    unset($rows);
    echo json_encode(['success' => true, 'as_of_date' => $asOf, 'rankings' => $rankings], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Candle prediction rankings unavailable: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '예측 순위를 불러올 수 없습니다.'], JSON_UNESCAPED_UNICODE);
}
