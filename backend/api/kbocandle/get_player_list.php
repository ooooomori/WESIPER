<?php
include_once "common.php";

$data = json_decode(file_get_contents('php://input'), true);
$search_name = $data['name'] ?? '';

if (trim($search_name) === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 💡 player_data 테이블을 LEFT JOIN하여 playerId와 p_no를 매칭하고, 존재 여부에 따라 team 또는 '은퇴'를 분기 처리
    $sql = "SELECT 
                p.p_name, 
                p.p_no,
                p.p_img,
                p.p_pos,
                COALESCE(pd.team, '은퇴') AS current_status
            FROM kbo_playerlist_20250613 p
            LEFT JOIN player_data pd ON pd.playerId = p.p_no
            WHERE (p.p_name LIKE :name1 OR p.p_oldname LIKE :name2) AND EXISTS (
                  SELECT 1 
                  FROM kbo_season_records 
                  WHERE kbo_season_records.player_id = p.p_no
              )
            ORDER BY p.p_name ASC";

    $stmt = $pdo->prepare($sql);
    
    $wildcard = '%' . $search_name . '%';
    $stmt->execute([
        'name1' => $wildcard,
        'name2' => $wildcard
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $finalResults = [];

    foreach ($results as $row) {
        $p_no = $row['p_no'];
        $baseImg = $row['p_img'] ?? $null;

        if (function_exists('image_exists')) {
            $baseImg = image_exists($p_no) ? $p_no : $baseImg;
        }

        $finalResults[] = [
            "PlayerId" => $p_no,
            "Name" => $row['p_name'],
            "Img" => $baseImg,
            "Pos" => $row['p_pos'] ?? '',
            "Team" => $row['current_status'], // 존재하면 팀명, 없으면 '은퇴'
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($finalResults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}