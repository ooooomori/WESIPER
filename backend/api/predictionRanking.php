<?php
// 메인 화면의 다음 경기 안타·홈런 예측 순위.
// 매일 02:00 크롤링 뒤 predict_next_game.py가 저장한 최신 예측(kbo_player_predictions)을 읽는다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header_register_callback(static function (): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });

const PREDICTION_MODEL_VERSION = 'batting-distribution-v2-events';
// 기준일(마지막 반영 경기일)로부터 이 일수 안에 출전 기록이 없는 선수는 순위에서 뺀다.
const PREDICTION_ACTIVE_DAYS = 30;
// 예측 기준일이 이보다 오래되면(시즌 종료·휴식기·배치 중단) 순위를 내보내지 않는다.
const PREDICTION_FRESH_DAYS = 4;

/** 예측 행들에서 선수별 최신 예측만 남기고, 준비되지 않았거나 최근 출전이 없는 선수를 뺀다. */
function predictionRankingPlayers(array $rows, string $asOf): array {
    $cutoff = (new DateTimeImmutable($asOf))->modify('-' . PREDICTION_ACTIVE_DAYS . ' days')->format('Y-m-d');
    $players = [];
    foreach ($rows as $row) {
        $id = (string)$row['player_id'];
        if (isset($players[$id])) continue; // generated_at 내림차순: 첫 행이 최신
        $players[$id] = null;
        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload) || ($payload['status'] ?? '') !== 'ready') continue;
        $last = (string)($payload['last_player_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $last) || $last < $cutoff) continue;
        $hit = $payload['probabilities']['hit'] ?? null;
        $homeRun = $payload['probabilities']['home_run'] ?? null;
        if (!is_numeric($hit) || !is_numeric($homeRun) || $hit < 0 || $hit > 1 || $homeRun < 0 || $homeRun > 1) continue;
        $players[$id] = ['PlayerId' => $id, 'Name' => $row['name'] ?: "#$id", 'Team' => $row['team'] ?? null,
            'avg' => isset($payload['current']['avg']) ? (float)$payload['current']['avg'] : null,
            'lastPlayed' => $last, 'hit' => (float)$hit, 'home_run' => (float)$homeRun];
    }
    return array_values(array_filter($players));
}

/** 확률 내림차순 상위 $limit명. 같은 확률은 같은 순위(1, 1, 3 …). */
function predictionRankingTop(array $players, string $metric, int $limit): array {
    usort($players, static fn($a, $b) => ($b[$metric] <=> $a[$metric]) ?: ((int)$a['PlayerId'] <=> (int)$b['PlayerId']));
    $rows = []; $rank = 0; $previous = null;
    foreach (array_slice($players, 0, $limit) as $index => $player) {
        if ($previous === null || $player[$metric] !== $previous) $rank = $index + 1;
        $previous = $player[$metric];
        $rows[] = ['rank' => $rank, 'PlayerId' => $player['PlayerId'], 'Name' => $player['Name'], 'Team' => $player['Team'],
            'avg' => $player['avg'], 'probability' => $player[$metric]];
    }
    return $rows;
}

if (PHP_SAPI === 'cli') return; // 테스트에서 함수만 불러 쓴다.

try {
    require_once __DIR__ . '/kbocandle/common.php';
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
    if ($limit === false) {
        http_response_code(400);
        echo json_encode(['error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $year = (int)$today->format('Y');
    $stmt = $pdo->prepare("SELECT MAX(as_of_date) FROM kbo_player_predictions WHERE season_year=? AND season_type='regular' AND model_version=?");
    $stmt->execute([$year, PREDICTION_MODEL_VERSION]);
    $asOf = $stmt->fetchColumn();
    if (!$asOf || $asOf < $today->modify('-' . PREDICTION_FRESH_DAYS . ' days')->format('Y-m-d')) {
        echo json_encode(['available' => false, 'asOf' => $asOf ?: null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $pdo->prepare("SELECT p.player_id, p.payload, pl.name, pl.team
        FROM kbo_player_predictions p LEFT JOIN kbo_player_data pl ON pl.player_id = p.player_id
        WHERE p.season_year=? AND p.season_type='regular' AND p.as_of_date=? AND p.model_version=?
        ORDER BY p.generated_at DESC");
    $stmt->execute([$year, $asOf, PREDICTION_MODEL_VERSION]);
    $players = predictionRankingPlayers($stmt->fetchAll(PDO::FETCH_ASSOC), (string)$asOf);
    $rankings = ['hit' => predictionRankingTop($players, 'hit', $limit), 'homeRun' => predictionRankingTop($players, 'home_run', $limit)];

    // 표시할 선수들의 시즌 홈런 수(예측 배치가 함께 저장한 경기별 집계에서 합산)
    $ids = array_values(array_unique(array_merge(array_column($rankings['hit'], 'PlayerId'), array_column($rankings['homeRun'], 'PlayerId'))));
    $homeRuns = array_fill_keys($ids, 0);
    if ($ids) {
        $stmt = $pdo->prepare("SELECT player_id, event_counts FROM kbo_player_game_stats
            WHERE season_year=? AND season_type='regular' AND player_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$year, ...$ids]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts = json_decode((string)$row['event_counts'], true);
            $homeRuns[(string)$row['player_id']] += (int)($counts['HR'] ?? 0);
        }
    }
    foreach ($rankings as &$rows) foreach ($rows as &$row) $row['homeRuns'] = $homeRuns[$row['PlayerId']] ?? 0;
    unset($rows, $row);

    echo json_encode(['available' => true, 'asOf' => $asOf, 'activeDays' => PREDICTION_ACTIVE_DAYS, 'players' => count($players), 'rankings' => $rankings], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Prediction ranking unavailable: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '예측 순위를 불러올 수 없습니다.'], JSON_UNESCAPED_UNICODE);
}
