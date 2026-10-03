<?php
include_once 'common.php';
require_once 'player_cache.php';
require_once dirname(__DIR__, 2) . '/lib/bingo-player.php';

    // 선수 기록·소속·포지션은 모두 운영 DB에서 읽는다 (backend/lib/bingo-player.php).
    // KBO 홈페이지 파싱은 사용하지 않는다.
    $data = json_decode(file_get_contents('php://input'), true);
    $searchName = $data["keyword"];
    
    // SQL 인젝션 방지를 위해 prepared statement 사용
    $sql = "SELECT `player_id`, `name`, `img`, `pos`, `is_MLB`, `draft`, `backNo`, `bat`, `throw`, `team`, `is_kbodle`, `retire`
            FROM $playerlist 
            WHERE COALESCE(`pos`, '') <> '코치'
                AND (`name` LIKE ? OR `oldname` LIKE ? OR `fullname` LIKE ?)
            ORDER BY LENGTH(name) ASC, `name` ASC LIMIT 15";
    $stmt = $con->prepare($sql);
    $searchName = "%$searchName%"; // 와일드카드 추가
    $stmt->bind_param("sss", $searchName, $searchName, $searchName);
    $stmt->execute();
    $result = $stmt->get_result();
    $playerRows = $result->fetch_all(MYSQLI_ASSOC);
    $careerProfiles = playerCareerProfiles($con, array_column($playerRows, 'player_id'));
    $cacheVersion = bingoPlayerCacheVersion();

    $searchResult = array();
    $searchResult['list'] = array();
    // 결과 처리
    foreach ($playerRows as $row) {
        $kbodata = cachedBingoPlayer($con, $row['player_id'], function () use ($row) {
            return bingoBuildPlayer(bingoPdo(), $row);
        }, bingoPlayerCacheKey($con, $row, $cacheVersion));
        if(!empty($kbodata) && !isset($kbodata['error'])) {
            unset($kbodata['Source']);
            $kbodata = applyPlayerCareerProfile($kbodata, $careerProfiles[(int)$row['player_id']]);

            $kbodata["Name"] = $row["name"];
            $kbodata['Profile']['is_MLB'] = $row['is_MLB'] == 1;
            $searchResult['list'][] = $kbodata;

            if($kbodata['Img'] !== $row['img']) {
                $stmt = $con->prepare("UPDATE $playerlist SET img = ? WHERE player_id = ?");
                $stmt->bind_param("si", $kbodata['Img'], $row['player_id']); // 문자열, 정수
                $stmt->execute();
            }

            if(count($searchResult['list']) >= 10) break;
        }
    }
    
    $searchResult['success'] = true;
    $searchResult['rows'] = $result->num_rows;

    echo json_encode($searchResult);
?>
