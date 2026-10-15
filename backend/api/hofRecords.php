<?php
/*
 * 선수 기록실 HOF 탭: 블랙잉크·그레이잉크·HOF 스탠다드·HOF 모니터 순위(점수가 0보다 큰 선수만).
 *   role    batter(기본) | pitcher
 *   metric  blackInk(기본) | grayInk | standard | monitor
 *   active  1이면 현역만
 *   offset, limit(기본 50, 최대 200)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
$fail = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};
try {
    require_once __DIR__ . '/kbocandle/common.php';
    require_once __DIR__ . '/../lib/player-season-schedule.php';
    require_once __DIR__ . '/../lib/record-room.php';
    require_once __DIR__ . '/../lib/record-room-hof.php';
    $role = $_GET['role'] ?? 'batter';
    $metric = $_GET['metric'] ?? 'blackInk';
    if (!in_array($role, ['batter', 'pitcher'], true)) $fail('잘못된 기록 종류입니다.');
    if (!in_array($metric, ['all', 'blackInk', 'grayInk', 'standard', 'monitor'], true)) $fail('잘못된 기록입니다.');
    $activeOnly = ($_GET['active'] ?? '') === '1';
    $offset = max(0, (int)(filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT) ?: 0));
    $limit = min(200, max(1, (int)(filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT) ?: 50)));
    $schedule = profileSchedule();
    // all: 네 기록 중 하나라도 점수가 있는 선수 전체(화면에서 기록별로 다시 정렬한다). 정렬 기준은 블랙잉크다.
    $sortKey = $metric === 'all' ? 'blackInk' : $metric;
    $rows = array_filter(recordRoomHof($pdo, $schedule)[$role], static fn($row) => ($metric === 'all' ? max($row['blackInk'], $row['grayInk'], $row['standard'], $row['monitor']) : $row[$metric]) > 0 && (!$activeOnly || $row['active']));
    usort($rows, static fn($a, $b) => ($b[$sortKey] <=> $a[$sortKey]) ?: strcmp((string)$a['name'], (string)$b['name']) ?: ($a['id'] <=> $b['id']));
    $rank = 0; $last = null;
    foreach ($rows as $index => &$row) {
        if ($last === null || abs($row[$sortKey] - $last) > 1e-9) $rank = $index + 1;
        $last = $row[$sortKey];
        $row = ['rank' => $rank, 'id' => $row['id'], 'name' => $row['name'], 'team' => $row['team'], 'pos' => $row['pos'], 'active' => $row['active'],
            'first' => $row['first'], 'last' => $row['last'], 'seasons' => $row['seasons'], 'score' => $row[$sortKey],
            'scores' => ['blackInk' => $row['blackInk'], 'grayInk' => $row['grayInk'], 'standard' => $row['standard'], 'monitor' => $row['monitor']]];
    }
    unset($row);
    echo json_encode(['role' => $role, 'metric' => $metric, 'total' => count($rows), 'offset' => $offset, 'limit' => $limit, 'rows' => array_slice($rows, $offset, $limit)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Player HOF records failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '기록을 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
