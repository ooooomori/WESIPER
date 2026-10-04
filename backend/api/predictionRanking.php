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

/** 안타·홈런 확률 쌍이 0~1 범위의 숫자면 [hit, home_run]을, 아니면 null을 돌려준다. */
function predictionRankingChances(mixed $probabilities): ?array {
    $hit = is_array($probabilities) ? ($probabilities['hit'] ?? null) : null;
    $homeRun = is_array($probabilities) ? ($probabilities['home_run'] ?? null) : null;
    if (!is_numeric($hit) || !is_numeric($homeRun) || $hit < 0 || $hit > 1 || $homeRun < 0 || $homeRun > 1) return null;
    return [(float)$hit, (float)$homeRun];
}

/**
 * 예측 행들에서 선수별 최신 예측만 남기고, 준비되지 않았거나 최근 출전이 없는 선수를 뺀다.
 * 빠진 선수는 $excluded에 이유와 함께 담는다(inactive: 최근 출전 없음, insufficient: 표본 부족 등).
 * 빠진 선수도 계산된 확률이 있으면 함께 담는다. 표본 부족 선수는 예측 배치가 따로 저장한
 * low_sample_probabilities(리그 평균으로 보정한 값)를 쓴다.
 */
function predictionRankingPlayers(array $rows, string $asOf, ?array &$excluded = null): array {
    $cutoff = (new DateTimeImmutable($asOf))->modify('-' . PREDICTION_ACTIVE_DAYS . ' days')->format('Y-m-d');
    $players = []; $excluded = [];
    foreach ($rows as $row) {
        $id = (string)$row['player_id'];
        if (array_key_exists($id, $players)) continue; // generated_at 내림차순: 첫 행이 최신
        $players[$id] = null;
        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload)) $payload = [];
        $last = (string)($payload['last_player_date'] ?? '');
        $dated = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $last) === 1;
        $ready = ($payload['status'] ?? '') === 'ready' ? predictionRankingChances($payload['probabilities'] ?? null) : null;
        $player = ['PlayerId' => $id, 'Name' => $row['name'] ?: "#$id", 'Team' => $row['team'] ?? null,
            'avg' => isset($payload['current']['avg']) && is_numeric($payload['current']['avg']) ? (float)$payload['current']['avg'] : null,
            'lastPlayed' => $dated ? $last : null];
        if ($ready && $dated && $last >= $cutoff) {
            $players[$id] = $player + ['hit' => $ready[0], 'home_run' => $ready[1]];
            continue;
        }
        $chances = $ready ?? predictionRankingChances($payload['low_sample_probabilities'] ?? null);
        $excluded[$id] = $player + ['reason' => $ready && $dated ? 'inactive' : 'insufficient',
            'hit' => $chances[0] ?? null, 'home_run' => $chances[1] ?? null];
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

/**
 * 이름에 $query가 들어간 선수의 예측을 찾는다. 순위 대상은 전체 순위와 확률을, 빠진 선수는 이유와 (있으면) 확률을 돌려준다.
 * 이름이 정확히 같은 선수를 먼저, 그다음 안타 확률이 높은 순으로 $limit명까지.
 */
function predictionRankingSearch(array $players, array $excluded, string $query, int $limit): array {
    $ranks = [];
    foreach (['hit' => 'hit', 'homeRun' => 'home_run'] as $key => $metric)
        foreach (predictionRankingTop($players, $metric, count($players)) as $row) $ranks[$row['PlayerId']][$key] = ['rank' => $row['rank'], 'probability' => $row['probability']];
    $matches = [];
    foreach ($players as $player) {
        if (mb_stripos($player['Name'], $query, 0, 'UTF-8') === false) continue;
        $matches[] = ['PlayerId' => $player['PlayerId'], 'Name' => $player['Name'], 'Team' => $player['Team'], 'avg' => $player['avg'],
            'status' => 'ready', 'lastPlayed' => $player['lastPlayed'], 'hit' => $ranks[$player['PlayerId']]['hit'], 'homeRun' => $ranks[$player['PlayerId']]['homeRun']];
    }
    usort($matches, static fn($a, $b) => (($b['Name'] === $query) <=> ($a['Name'] === $query)) ?: ($b['hit']['probability'] <=> $a['hit']['probability']) ?: ((int)$a['PlayerId'] <=> (int)$b['PlayerId']));
    foreach ($excluded as $player) {
        if (mb_stripos($player['Name'], $query, 0, 'UTF-8') === false) continue;
        // 순위에는 넣지 않지만(rank null) 계산된 확률은 보여준다.
        $chance = static fn(?float $value): ?array => $value === null ? null : ['rank' => null, 'probability' => $value];
        $matches[] = ['PlayerId' => $player['PlayerId'], 'Name' => $player['Name'], 'Team' => $player['Team'], 'avg' => $player['avg'],
            'status' => $player['reason'], 'lastPlayed' => $player['lastPlayed'], 'hit' => $chance($player['hit']), 'homeRun' => $chance($player['home_run'])];
    }
    // 이름이 정확히 같은 선수는 순위에서 빠졌더라도 맨 위에 둔다(usort는 나머지 순서를 유지한다).
    usort($matches, static fn($a, $b) => ($b['Name'] === $query) <=> ($a['Name'] === $query));
    return array_slice($matches, 0, $limit);
}

/**
 * 한 선수의 시즌 경기들(최근 경기부터)에서 홈런 수와 최근 흐름을 계산한다.
 * 타수가 없는 경기(대주자·대수비, 볼넷만 얻은 경기)는 연속 기록을 끊지도 잇지도 않는다.
 * hitStreak: 연속 안타 경기 수, homeRunStreak: 연속 홈런 경기 수,
 * hitGamesAgo·homeRunGamesAgo: 마지막 안타·홈런이 몇 경기 전인지(직전 경기 = 1, 올해 기록이 없으면 null).
 */
function predictionSeasonForm(array $games): array {
    $form = ['homeRuns' => 0, 'hitStreak' => 0, 'hitGamesAgo' => null, 'homeRunStreak' => 0, 'homeRunGamesAgo' => null];
    $played = 0; $hitOpen = true; $homeRunOpen = true;
    foreach ($games as $game) {
        $homeRuns = (int)($game['hr'] ?? 0);
        $form['homeRuns'] += $homeRuns;
        if ((int)($game['ab'] ?? 0) <= 0 && $homeRuns <= 0) continue;
        $played++;
        if ($hitOpen) { if ((int)($game['h'] ?? 0) > 0) $form['hitStreak']++; else $hitOpen = false; }
        if ($homeRunOpen) { if ($homeRuns > 0) $form['homeRunStreak']++; else $homeRunOpen = false; }
        if ((int)($game['h'] ?? 0) > 0 && $form['hitGamesAgo'] === null) $form['hitGamesAgo'] = $played;
        if ($homeRuns > 0 && $form['homeRunGamesAgo'] === null) $form['homeRunGamesAgo'] = $played;
    }
    return $form;
}

/** 선수들의 시즌 홈런 수와 최근 흐름(예측 배치가 함께 저장한 경기별 집계에서 계산). */
function predictionSeasonForms(PDO $pdo, int $year, array $ids): array {
    $games = array_fill_keys($ids, []);
    if ($ids) {
        $stmt = $pdo->prepare("SELECT player_id, ab, h, event_counts FROM kbo_player_game_stats
            WHERE season_year=? AND season_type='regular' AND player_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY player_id, game_date DESC, game_id DESC');
        $stmt->execute([$year, ...$ids]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts = json_decode((string)$row['event_counts'], true);
            $games[(string)$row['player_id']][] = ['ab' => $row['ab'], 'h' => $row['h'], 'hr' => $counts['HR'] ?? 0];
        }
    }
    return array_map('predictionSeasonForm', $games);
}

if (PHP_SAPI === 'cli') return; // 테스트에서 함수만 불러 쓴다.

try {
    require_once __DIR__ . '/kbocandle/common.php';
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
    $query = isset($_GET['q']) ? trim((string)$_GET['q']) : null;
    if ($limit === false || ($query !== null && (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 20))) {
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
    $players = predictionRankingPlayers($stmt->fetchAll(PDO::FETCH_ASSOC), (string)$asOf, $excluded);
    if ($query !== null) {
        $matches = predictionRankingSearch($players, $excluded, $query, 8);
        $forms = predictionSeasonForms($pdo, $year, array_column($matches, 'PlayerId'));
        foreach ($matches as &$match) $match += $forms[$match['PlayerId']] ?? predictionSeasonForm([]);
        unset($match);
        echo json_encode(['available' => true, 'asOf' => $asOf, 'activeDays' => PREDICTION_ACTIVE_DAYS, 'players' => count($players), 'query' => $query, 'matches' => $matches], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rankings = ['hit' => predictionRankingTop($players, 'hit', $limit), 'homeRun' => predictionRankingTop($players, 'home_run', $limit)];

    $ids = array_values(array_unique(array_merge(array_column($rankings['hit'], 'PlayerId'), array_column($rankings['homeRun'], 'PlayerId'))));
    $forms = predictionSeasonForms($pdo, $year, $ids);
    foreach ($rankings as &$rows) foreach ($rows as &$row) $row += $forms[$row['PlayerId']] ?? predictionSeasonForm([]);
    unset($rows, $row);

    echo json_encode(['available' => true, 'asOf' => $asOf, 'activeDays' => PREDICTION_ACTIVE_DAYS, 'players' => count($players), 'rankings' => $rankings], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Prediction ranking unavailable: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '예측 순위를 불러올 수 없습니다.'], JSON_UNESCAPED_UNICODE);
}
