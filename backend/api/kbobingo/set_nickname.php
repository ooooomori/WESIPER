<?php
header("Content-Type: application/json; charset=UTF-8");
include_once "common.php";

// JSON 데이터 받아오기
$data = json_decode(file_get_contents("php://input"), true);
$uuid = isset($data["uuid"]) ? trim($data["uuid"]) : "";
$nickname = isset($data["nickname"]) ? trim($data["nickname"]) : "";

if ($uuid === "" || $nickname === "") {
    echo json_encode(["code" => 400, "message" => "UUID 또는 닉네임이 비어 있습니다."]);
    exit;
}

// 닉네임 길이 검증 (2~12자)
if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 12) {
    echo json_encode(["code" => 422, "message" => "닉네임 길이는 2자 이상 12자 이하이어야 합니다."]);
    exit;
}

$table = "kbobingo_nickname";

// INSERT 또는 UPDATE
$sql = "
    INSERT INTO $table (user_id, nickname)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE nickname = VALUES(nickname)
";

$stmt = $con->prepare($sql);
$stmt->bind_param("ss", $uuid, $nickname);

if ($stmt->execute()) {
    echo json_encode(["code" => 200, "message" => "닉네임이 성공적으로 저장되었습니다."]);
} else {
    echo json_encode(["code" => 500, "message" => "데이터베이스 오류"]);
}

$stmt->close();
$con->close();
?>
