<?php


    include_once "common.php";

    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $playerID = $data["p_no"];
    
    $sql = "SELECT `playerId`, `name`, `hs`, `hsLoc` ,`team`, `backNo`, `bat`, `throw`, `mainPos`, `subPos`, `birth`, `draft`
    FROM $playerlist
    WHERE `playerId` = ?";
    $stmt = $con->prepare($sql);
    $stmt->bind_param("s", $playerID);
    $stmt->execute();
    $result = $stmt->get_result();

    $answer = array();
    // 결과 처리
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
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
        $answer["Name"] = $row["name"];
        $answer['SporkId'] = $row['playerId'];
        $answer['success'] = true;
    } else {
        $answer['success'] = false;
        $answer['error'] = "정답 데이터가 존재하지 않습니다.";
    }

    echo json_encode($answer);
    $con->close();
