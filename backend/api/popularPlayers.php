<?php
// 최근 7일 선수 프로필 조회수 순위. 5분 동안 파일 캐시를 재사용한다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
$limit = max(1, min(30, (int)($_GET['limit'] ?? 10)));
$cachePath = sys_get_temp_dir() . '/wesiper-popular-players-v3-' . $limit . '.json';
clearstatcache(true, $cachePath);
if (is_file($cachePath) && time() - filemtime($cachePath) < 300) {
    readfile($cachePath);
    exit;
}
try {
    require_once __DIR__ . '/kbocandle/common.php';
    require_once __DIR__ . '/../lib/player-search-stats.php';
    require_once __DIR__ . '/../lib/player-views.php';
    $today = date('Y-m-d');
    $json = json_encode([
        'success' => true,
        'days' => PLAYER_VIEW_WINDOW_DAYS,
        'updatedAt' => date('c'),
        'list' => popularPlayers($pdo, $today, $limit),
    ], JSON_UNESCAPED_UNICODE);
    @file_put_contents($cachePath, $json, LOCK_EX);
    echo $json;
} catch (Throwable $error) {
    error_log('Popular players unavailable: ' . $error->getMessage());
    // 테이블 생성 전이거나 DB 오류일 때는 빈 목록을 돌려 프런트엔드가 기본 목록을 쓰게 한다.
    echo json_encode(['success' => false, 'list' => []], JSON_UNESCAPED_UNICODE);
}
