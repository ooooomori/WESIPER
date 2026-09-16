<?php
include_once "common.php";

$data = json_decode(file_get_contents('php://input'), true);
$search_name = $data['name'] ?? '';

if (trim($search_name) === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Filter the small player list first. Putting EXISTS in this query makes
    // MariaDB materialize the million-row season-record index on every search.
    $sql = "SELECT 
                p.p_name, 
                p.p_no,
                p.p_img,
                p.p_pos,
                COALESCE((SELECT pd.team FROM player_data pd WHERE pd.playerId = p.p_no LIMIT 1), '은퇴') AS current_status
            FROM kbo_playerlist_20250613 p
            WHERE p.p_name LIKE :name1 OR p.p_oldname LIKE :name2
            ORDER BY p.p_name ASC,
                     CASE
                         WHEN p.p_img REGEXP '^[0-9]{4}_' THEN CAST(SUBSTRING_INDEX(p.p_img, '_', 1) AS UNSIGNED)
                         ELSE 0
                     END DESC
            LIMIT 30";

    $stmt = $pdo->prepare($sql);
    
    $wildcard = '%' . $search_name . '%';
    $stmt->execute([
        'name1' => $wildcard,
        'name2' => $wildcard
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $finalResults = [];
    $recordCheck = $pdo->prepare(
        'SELECT 1 FROM kbo_season_records FORCE INDEX (idx_player_date) WHERE player_id = :player_id LIMIT 1'
    );

    foreach ($results as $row) {
        $p_no = $row['p_no'];
        $recordCheck->execute(['player_id' => $p_no]);
        if (!$recordCheck->fetchColumn()) continue;

        $baseImg = $row['p_img'] ?? null;

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
