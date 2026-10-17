<?php
// 1군 정규시즌 기록이 있는 선수(1982~2000 시즌 합계 + 2001년 이후 경기별 기록) 중 한 명을 무작위로 고른다. 후보 목록은 하루 동안 파일 캐시를 재사용한다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$exclude = (int)($_GET['exclude'] ?? 0);
$cachePath = sys_get_temp_dir() . '/wesiper-random-player-ids-v2.json';
try {
    clearstatcache(true, $cachePath);
    $ids = is_file($cachePath) && time() - filemtime($cachePath) < 86400 ? json_decode((string)file_get_contents($cachePath), true) : null;
    if (!is_array($ids) || !$ids) {
        require_once __DIR__ . '/kbocandle/common.php';
        $ids = $pdo->query("SELECT p.player_id FROM kbo_player_data p
            WHERE COALESCE(p.pos, '') <> '코치' AND p.player_id IN (
                SELECT player_id FROM kbo_player_season_batting_totals WHERE league_level=1 AND series_id=0 AND games>0
                UNION SELECT player_id FROM kbo_player_season_pitching_totals WHERE league_level=1 AND series_id=0 AND games>0
                UNION SELECT DISTINCT player_id FROM kbo_season_records WHERE league_level=1
                UNION SELECT DISTINCT player_id FROM kbo_season_pitch_records WHERE league_level=1)")
            ->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', $ids);
        if ($ids) @file_put_contents($cachePath, json_encode($ids), LOCK_EX);
    }
    $candidates = count($ids) > 1 ? array_values(array_filter($ids, static fn($id) => $id !== $exclude)) : $ids;
    if (!$candidates) throw new RuntimeException('no players');
    echo json_encode(['success' => true, 'playerId' => $candidates[random_int(0, count($candidates) - 1)]]);
} catch (Throwable $error) {
    error_log('Random player unavailable: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
