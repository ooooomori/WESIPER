<?php
include_once "common.php";

$data = json_decode(file_get_contents('php://input'), true);

$gridId = $data['index'];
$score = $data['score'];
$completed = $data['completed'];
$userId = $data['uuid'];

$statTable = "kbobingo_stat";

// 점수 저장 또는 갱신
$query = "INSERT INTO $statTable (`user_id`, `grid_index`, `score`, `completed`)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE score = VALUES(score), completed = VALUES(completed)";

$stmt = $con->prepare($query);

// VALUES() 함수를 쓰면 뒤쪽 물음표 2개를 생략할 수 있어 실수를 방지합니다.
$stmt->bind_param("siii", $userId, $gridId, $score, $completed);

if (!$stmt->execute()) {
    // 만약 여기서 에러가 나면 PuTTY 로그에 찍힙니다.
    error_log("DB 실행 에러: " . $stmt->error);
}

$stmt->close();

// 등수 및 백분율 계산
$sql = "
    SELECT 
        total_records,
        ranked.rank,
        COALESCE(n.nickname, SUBSTR(ranked.user_id, 1, 8)) AS nickname
    FROM 
        (SELECT COUNT(*) AS total_records FROM $statTable WHERE grid_index = ?) AS total,
        (
            SELECT user_id, RANK() OVER (ORDER BY score DESC) AS rank
            FROM $statTable
            WHERE grid_index = ?
        ) AS ranked
    LEFT JOIN kbobingo_nickname n ON ranked.user_id = n.user_id
    WHERE ranked.user_id = ?
";

$stmt = $con->prepare($sql);
$stmt->bind_param("iis", $gridId, $gridId, $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();

    $total = (int)$row['total_records'];
    $rank = (int)$row['rank'];

    $percentage = 100 * $rank / $total;
    if ($percentage > 2) {
        $percentage = number_format($percentage, 0);
    } elseif ($percentage > 0.1) {
        $percentage = number_format($percentage, 1);
    } elseif ($percentage > 0.01) {
        $percentage = number_format($percentage, 2);
    } else {
        $percentage = 0.01;
    }

    $statistics = [
        "code" => 200,
        "percentage" => $percentage,
        "total" => $total,
        "rank" => $rank,
        "nickname" => $row['nickname'],
        "top" => []
    ];
} else {
    $statistics = [
        "code" => 404,
        "error" => "결과 테이블 비어있음",
        "top" => []
    ];
}

$stmt->close();

// 상위 5위 점수와 닉네임 가져오기
$sql = "
    SELECT ranked.user_id, ranked.score, n.nickname, ranked.rank
    FROM (
        SELECT user_id, score, RANK() OVER (ORDER BY score DESC) AS rank
        FROM $statTable
        WHERE grid_index = ?
    ) AS ranked
    LEFT JOIN kbobingo_nickname n ON ranked.user_id = n.user_id
    WHERE ranked.rank <= 5
";

$stmt = $con->prepare($sql);
$stmt->bind_param("i", $gridId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $nickname = $row['nickname'];
    if (!$nickname || trim($nickname) === "") {
        $nickname = substr($row['user_id'], 0, 8); // 닉네임이 없으면 user_id 앞 8자
    }

    $statistics['top'][] = [
        "user_id" => $row['user_id'],
        "nickname" => $nickname,
        "score" => (int)$row['score'],
        "rank" => (int)$row['rank'],
    ];
}

$stmt->close();
echo json_encode($statistics);
?>
