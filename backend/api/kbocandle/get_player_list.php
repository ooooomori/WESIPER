<?php
include_once "common.php";

$data = json_decode(file_get_contents('php://input'), true);
$search_name = trim($data['name'] ?? '');

if (trim($search_name) === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../lib/player-search.php';
    $results = searchKboPlayers($pdo, $search_name);
    $finalResults = [];
    $needsTeam=array_filter($results,static fn($row)=>(string)$row['is_kbodle']==='0');
    $lastTeams=searchPlayerLastTeams($pdo,array_column($needsTeam,'player_id'));

    foreach ($results as $row) {
        $player_id = $row['player_id'];
        $formerTeam = null;
        $lastRecordYear = null;
        $firstRecordYear = null;
        if ((string)$row['is_kbodle'] === '0') {
            // Recent first-team/futures records take priority over stored profile teams.
            $formerTeam = $lastTeams[$player_id] ?? (trim((string)($row['stored_team'] ?? '')) ?: null);
            $lastRecordYear = empty($row['retire']) ? $row['last_record_year'] : null;
            $firstRecordYear = $row['first_record_year'] ?? null;
            if ($formerTeam) $formerTeam = trim($formerTeam);
        }

        $baseImg = $row['img'] ?? null;

        if (function_exists('image_exists')) {
            $baseImg = image_exists($player_id) ? $player_id : $baseImg;
        }

        $finalResults[] = [
            "PlayerId" => $player_id,
            "Name" => $row['name'],
            "FirstTeamGames" => $row['first_team_games'],
            "Img" => $baseImg,
            "Pos" => $row['pos'] ?? '',
            "MainPos" => $row['mainPos'] ?? null,
            "FormerTeam" => $formerTeam,
            "Draft" => $row['draft'] ?? null,
            "Retire" => $row['retire'] ?? null,
            "LastRecordYear" => $lastRecordYear,
            "FirstRecordYear" => $firstRecordYear,
            "BackNo" => $row['back_no'],
            "IsActive" => (string)$row['is_kbodle'] !== '0',
            "IsNumberRetired" => (int)($row['is_number_retired'] ?? 0),
            "NumberRetiredTeam" => (int)($row['is_number_retired'] ?? 0) === 1 ? ($formerTeam ?: $row['stored_team']) : null,
            "Team" => $row['current_status'], // 존재하면 팀명, 없으면 '은퇴'
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($finalResults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
