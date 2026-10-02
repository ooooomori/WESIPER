<?php
// 선수 프로필 조회 기록. 집계 실패가 프로필 화면을 막지 않도록 항상 짧게 응답한다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
$body = json_decode(file_get_contents('php://input') ?: '', true);
$pid = is_array($body) ? (string)($body['pid'] ?? '') : '';
if (!preg_match('/^\d{1,10}$/D', $pid)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
require_once __DIR__ . '/../lib/player-views.php';
if (playerViewIsBot($userAgent)) {
    echo json_encode(['success' => true, 'counted' => false]);
    exit;
}
try {
    require_once __DIR__ . '/kbocandle/common.php';
    $today = date('Y-m-d');
    $counted = recordPlayerView($pdo, $pid, playerViewVisitorHash((string)($_SERVER['REMOTE_ADDR'] ?? ''), $userAgent, $today), $today);
    echo json_encode(['success' => true, 'counted' => $counted]);
} catch (Throwable $error) {
    error_log('Player view not recorded: ' . $error->getMessage());
    echo json_encode(['success' => false]);
}
