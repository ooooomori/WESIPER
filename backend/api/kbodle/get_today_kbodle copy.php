<?php


    include_once "common.php";

    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }


    $sql = "SELECT `PK`, `PlayerName`, `PlayerID`, `PlayerBirth` FROM `kbodle_answer` WHERE `Kbodle_Date` = DATE(NOW())";
    $result = $con->query($sql);
    
    
    // 결과 처리
    if ($result->num_rows > 0) {
        $answerRow = $result->fetch_assoc();
        $playerInfo = getPlayerData($answerRow['PlayerName'], $answerRow['PlayerBirth'], "answer");
        $answer = $playerInfo;
        $answer['ID'] = $answerRow['PlayerID'];
        $answer['index'] = $answerRow['PK'];
        $answer['success'] = true;
        print_r($answer);
    } else {
        $answer['success'] = false;
        $answer['error'] = "정답 데이터가 존재하지 않습니다.";
    }
    echo json_encode($answer);
    $con->close();
    
?>
