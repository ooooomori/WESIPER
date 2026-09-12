<?php
include_once "common.php";

$data = json_decode(file_get_contents('php://input'), true);

$gridId = $data['index'];
$score = $data['score'];
$completed = $data['completed'];
$userId = $data['uuid'];
$picks = null;
if (isset($data['picks']) && is_array($data['picks']) && count($data['picks']) === 9) {
    $normalizedPicks = [];
    foreach ($data['picks'] as $pick) {
        if ($pick !== null && (!is_numeric($pick) || (int)$pick <= 0)) {
            http_response_code(400);
            echo json_encode(['code' => 400, 'error' => '잘못된 빙고판 데이터']);
            exit;
        }
        $normalizedPicks[] = $pick === null ? null : (int)$pick;
    }
    $picks = json_encode($normalizedPicks);
}

$statTable = "kbobingo_stat";

// 점수 저장 또는 갱신
$query = "INSERT INTO $statTable (`user_id`, `grid_index`, `score`, `completed`, `picks`)
          VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE score = VALUES(score), completed = VALUES(completed),
          picks = COALESCE(VALUES(picks), picks)";

$stmt = $con->prepare($query);

// VALUES() 함수를 쓰면 뒤쪽 물음표 2개를 생략할 수 있어 실수를 방지합니다.
$stmt->bind_param("siiis", $userId, $gridId, $score, $completed, $picks);

if (!$stmt->execute()) {
    // 만약 여기서 에러가 나면 PuTTY 로그에 찍힙니다.
    error_log("DB 실행 에러: " . $stmt->error);
    http_response_code(500);
    echo json_encode(['code' => 500, 'error' => '빙고 기록을 저장하지 못했습니다.']);
    exit;
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
    SELECT ranked.user_id, ranked.score, ranked.is_public, ranked.has_board, n.nickname, ranked.rank
    FROM (
        SELECT user_id, score, is_public, (picks IS NOT NULL AND picks <> '') AS has_board,
               RANK() OVER (ORDER BY score DESC) AS rank
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
        "user_id" => $row['user_id'] === $userId ? $userId : null,
        "board_id" => hash('sha256', $row['user_id']),
        "nickname" => $nickname,
        "score" => (int)$row['score'],
        "rank" => (int)$row['rank'],
        "is_public" => (bool)$row['is_public'],
        "has_board" => (bool)$row['has_board'],
    ];
}

$stmt->close();
$stmt = $con->prepare("SELECT is_public FROM $statTable WHERE user_id = ? AND grid_index = ?");
$stmt->bind_param("si", $userId, $gridId);
$stmt->execute();
$own = $stmt->get_result()->fetch_assoc();
$statistics['is_public'] = $own ? (bool)$own['is_public'] : true;
$statistics['board_id'] = hash('sha256', $userId);
$stmt->close();
echo json_encode($statistics);
?>
