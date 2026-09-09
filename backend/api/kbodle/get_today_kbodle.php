<?php
    include_once "common.php";

    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }


    $sql = "SELECT kbodle_answer.PK, kbodle_answer.PlayerID, kbodle_answer.PlayerName, player_data.hs, player_data.hsLoc, player_data.team, player_data.mainPos, player_data.subPos, player_data.backNo, player_data.draft, player_data.birth, player_data.throw, player_data.bat
    FROM kbodle_answer
    INNER JOIN player_data ON kbodle_answer.PlayerID = player_data.playerId
    WHERE kbodle_answer.Kbodle_Date = DATE(NOW());";
    
    $result = $con->query($sql);

    $answer = array();
    // 결과 처리
    if ($result->num_rows > 0) {
    
        $row = $result->fetch_assoc();
        //$playerInfo = getPlayerData($row['playerID'], "answer");

        $age = 20;
        if($row['birth']) {
            $currentDay = new DateTime();
            $birthDay = new Datetime($row['birth']);
            $age = $birthDay->diff($currentDay)->y;
        }

        if(!$row['hs']) $row['hs'] = "국외";
        if(!$row['subPos']) $row['subPos'] = array();
        $answer["HS"] = $row['hs'];
        $answer["Bat"] = $row['bat'];
        $answer["Pit"] = $row['throw'];
        $answer["BackNo"] = $row['backNo'];
        $answer["Age"] = $age;
        $answer["Draft"] = shortenDraft($row['draft']);
        $answer["Team"] = $row['team'];
        $answer["Pos"] = $row['mainPos'];
        $answer["SubPos"] = $row['subPos'];
        $answer["HSLoc"] = $row['hsLoc'];
        $answer["Name"] = $row["PlayerName"];
        $answer['SporkId'] = $row['PlayerID'];
        $answer['index'] = $row['PK'];
        $answer['success'] = true;
        //$answer = array_merge($answer, $playerInfo);
        
        $answer['success'] = true;
        //$answer['error'] = $playerInfo;
    } else {
        $answer['success'] = false;
        $answer['error'] = "정답 데이터가 존재하지 않습니다.";
    }

    echo json_encode($answer);
    $con->close();
    
?>
