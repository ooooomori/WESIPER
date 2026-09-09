<?php


    include_once "common.php";

    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }


    $sql = "SELECT PK, playerID
    FROM kbodle_answer
    WHERE Kbodle_Date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ORDER BY PK DESC";
    
    $result = $con->query($sql);
    
    $answer = array();
    // 결과 처리
    if ($result->num_rows > 0) {
        $answer["list"] = array();
        while($row = $result->fetch_assoc()) {
            $answer["list"][] = $row;
        }
        $answer["status"] = 200;
    } else {
        $answer['status'] = 404;
        $answer['error'] = "정답 데이터가 존재하지 않습니다.";
    }

    echo json_encode($answer);
    $con->close();
    
?>
