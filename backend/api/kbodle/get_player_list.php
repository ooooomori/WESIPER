<?php
include_once __DIR__ . '/common.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$keyword = '%' . ($data['keyword'] ?? '') . '%';
$sql = "SELECT `player_id` AS `playerId`, `name`, `hs`, `hsLoc`, `birth`, `throw`, `bat`,
        `mainPos`, `subPos`, `draft`, `team`, `backNo`
    FROM $playerlist
    WHERE `is_kbodle` IN (1, 2) AND (`name` LIKE ? OR `oldname` LIKE ? OR `fullname` LIKE ?)
    ORDER BY `name` ASC";
$stmt = $con->prepare($sql);
$stmt->bind_param('sss', $keyword, $keyword, $keyword);
$stmt->execute();
$result = $stmt->get_result();
$searchResult = ['list' => [], 'success' => true];
while ($row = $result->fetch_assoc()) {
    if (!$row['draft']) continue;
    $age = $row['birth'] ? (new DateTime($row['birth']))->diff(new DateTime())->y : 20;
    $searchResult['list'][] = [
        'SporkId' => $row['playerId'],
        'Name' => $row['name'],
        'Pos' => $row['mainPos'],
        'SubPos' => explode(',', $row['subPos'] ?? ''),
        'Age' => $age,
        'Pit' => $row['throw'],
        'Bat' => $row['bat'],
        'Draft' => shortenDraft($row['draft']),
        'Team' => $row['team'],
        'HS' => $row['hs'],
        'HSLoc' => $row['hsLoc'],
        'BackNo' => $row['backNo'],
    ];
}
echo json_encode($searchResult, JSON_UNESCAPED_UNICODE);
$stmt->close();
$con->close();
