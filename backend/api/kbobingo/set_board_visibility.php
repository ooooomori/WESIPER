<?php
include_once 'common.php';
header('Content-Type: application/json; charset=UTF-8');
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = (string)($data['uuid'] ?? '');
$gridId = (int)($data['index'] ?? 0);
if ($userId === '' || $gridId <= 0 || !isset($data['is_public']) || !is_bool($data['is_public'])) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'error' => '잘못된 공개 설정 요청']);
    exit;
}
$isPublic = $data['is_public'] ? 1 : 0;
$stmt = $con->prepare("UPDATE kbobingo_stat SET is_public = ? WHERE user_id = ? AND grid_index = ?");
$stmt->bind_param("isi", $isPublic, $userId, $gridId);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['code' => 500, 'error' => '공개 설정을 저장하지 못했습니다.']);
    exit;
}
echo json_encode(['code' => 200, 'is_public' => (bool)$isPublic]);
