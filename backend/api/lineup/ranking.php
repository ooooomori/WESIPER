<?php
// 라인업 맞추기: 오늘의 라인업 랭킹(상위 10명과 내 순위). client는 브라우저가 만든 임의 식별자다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    require_once __DIR__ . '/../kbocandle/common.php';
    require_once __DIR__ . '/../../lib/lineup-game.php';
    $ranking = lineupDailyRanking($pdo, date('Y-m-d'), (string)($_GET['client'] ?? ''));
    echo json_encode(['success' => $ranking !== null, 'ranking' => $ranking], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Lineup ranking failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '랭킹을 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
