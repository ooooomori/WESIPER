<?php
include_once 'common.php';
header('Content-Type: application/json; charset=UTF-8');
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$gridId = (int)($data['index'] ?? 0);
$boardId = (string)($data['board_id'] ?? '');
$stmt = $con->prepare("SELECT picks FROM kbobingo_stat WHERE SHA2(user_id, 256) = ? AND grid_index = ? AND is_public = 1");
$stmt->bind_param("si", $boardId, $gridId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$picks = $row ? json_decode($row['picks'] ?? '', true) : null;
if (!is_array($picks) || count($picks) !== 9) {
    http_response_code(404);
    echo json_encode(['code' => 404, 'error' => '공개된 빙고판이 없습니다.']);
    exit;
}
$stmt = $con->prepare("SELECT * FROM kbobingo_grid WHERE PK = ?");
$stmt->bind_param("i", $gridId);
$stmt->execute();
$grid = $stmt->get_result()->fetch_assoc();
if (!$grid) {
    http_response_code(404);
    echo json_encode(['code' => 404, 'error' => '빙고판을 찾을 수 없습니다.']);
    exit;
}
$players = [];
$stmt = $con->prepare("SELECT p_no, p_name, p_img FROM $playerlist WHERE p_no = ?");
foreach ($picks as $pick) {
    if ($pick === null) { $players[] = null; continue; }
    $id = (int)$pick;
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $player = $stmt->get_result()->fetch_assoc();
    $players[] = $player ? [
        'no' => (int)$player['p_no'],
        'name' => $player['p_name'],
        'img' => image_exists($player['p_no']) ? $player['p_no'] : $player['p_img'],
    ] : ['no' => $id, 'name' => '선수 정보 없음', 'img' => 'ssg_b_l'];
}
echo json_encode(['code' => 200, 'players' => $players, 'grid' => [
    'index' => $gridId,
    'row' => [$grid['row-1'], $grid['row-2'], $grid['row-3']],
    'col' => [$grid['col-1'], $grid['col-2'], $grid['col-3']],
]], JSON_UNESCAPED_UNICODE);
