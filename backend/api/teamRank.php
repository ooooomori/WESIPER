<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../lib/team-standings.php';
try {
    $configPath = __DIR__ . '/../config/database.php';
    $config = require (is_file($configPath) ? $configPath : '/opt/bitnami/apache/conf/wesiper-db.php');
    $db = new PDO("mysql:host={$config['host']};port=" . ($config['port'] ?? 3306) . ";dbname={$config['database']};charset=utf8mb4",
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $year = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
    $query = $db->prepare('SELECT * FROM kbo_schedule WHERE game_date >= ? AND game_date < ? AND away_score IS NOT NULL AND home_score IS NOT NULL ORDER BY game_date, game_code');
    $query->execute(["$year-03-01", "$year-11-01"]);
    echo json_encode(['code' => '100', 'title' => "$year 저장된 경기 결과 기준", 'rows' => calculateStandings($query->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Team standings: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '팀 순위를 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
