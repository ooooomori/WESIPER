<?php
include_once "common.php";
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$answerID = filter_var($data['answer_id'] ?? null, FILTER_VALIDATE_INT);
if (!$answerID || $answerID < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '정답 선수 ID가 필요합니다.']);
    exit;
}

// 검색과 달리 드래프트 정보 유무에 관계없이 모든 선수를 대상으로 한다.
$stmt = $con->prepare("SELECT `playerId`, `name`, `hs`, `hsLoc`, `birth`, `throw`, `bat`, `mainPos`, `subPos`, `draft`, `team`, `backNo` FROM $playerlist WHERE `playerId` <> ? ORDER BY RAND() LIMIT 1");
$stmt->bind_param('i', $answerID);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    echo json_encode(['success' => false, 'error' => '랜덤으로 선택할 선수가 없습니다.']);
    exit;
}

$age = 20;
if ($row['birth']) {
    $age = (new DateTime($row['birth']))->diff(new DateTime())->y;
}
$draft = $row['draft'] ?? '';
// 기존 축약 함수에서 처리하지 못하는 짧은 값도 문자열로 유지한다.
$draftParts = explode(' ', $draft);
$canShorten = strpos($draft, '1차') !== false
    || strpos($draft, '육성') !== false
    || strpos($draft, '신고') !== false
    || strpos($draft, '부상') !== false
    || count($draftParts) >= (strpos($draft, '2차') !== false ? 4 : 3);
$shortDraft = $canShorten ? shortenDraft($draft) : $draft;
echo json_encode(['success' => true, 'player' => [
    'SporkId' => $row['playerId'],
    'Name' => $row['name'],
    'Pos' => $row['mainPos'] ?? '',
    'SubPos' => explode(',', $row['subPos'] ?? ''),
    'Age' => $age,
    'Pit' => $row['throw'] ?? '',
    'Bat' => $row['bat'] ?? '',
    'Draft' => $shortDraft ?? '',
    'Team' => $row['team'] ?? '',
    'HS' => $row['hs'] ?? '',
    'HSLoc' => $row['hsLoc'] ?? '',
    'BackNo' => $row['backNo'],
]]);
$stmt->close();
$con->close();
