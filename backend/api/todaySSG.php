<?php
header("Access-Control-Allow-Origin: *"); // 모든 도메인 허용
header("Access-Control-Allow-Methods: GET, POST, OPTIONS"); // 허용할 HTTP 메서드
header("Access-Control-Allow-Headers: Content-Type"); // 허용할 헤더
     // 보낼 데이터 배열 생성
     $postData = array(
        'leId' => '1',
        'srId' => '0,1,3,4,5,7,9',
        'date' => date('Ymd'),
    );

    // POST 요청을 보낼 대상 PHP 파일 URL
    $url = 'https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList';

    // 사용자 정의 헤더 설정
    $headers = array(
        'Content-Type: application/x-www-form-urlencoded'
    );

    // cURL 핸들 생성
    $ch = curl_init();

    // cURL 옵션 설정
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // 사용자 정의 헤더 추가

    // 요청 실행 및 응답 받기
    $response = curl_exec($ch);

    // cURL 핸들 닫기
    curl_close($ch);

    $response = json_decode($response, true);

    // 응답 처리
    if ($response === false) {
        $result = array("success" => false, "error" => curl_error($ch));
    }
    else if ($response === null) {
        $result = array("success" => false, "error" => "데이터 없음");
    } else {
        $result = array("success" => true, "isGameExist" => false);
        foreach($response["game"] as $game) {
            if($game["AWAY_ID"] === "SK" || $game["HOME_ID"] === "SK") {
                $result["isGameExist"] = true;
                $result["game"] = array(
                    "away" => $game["AWAY_NM"],
                    "home" => $game["HOME_NM"],
                    "away_score" => $game["T_SCORE_CN"],
                    "home_score" => $game["B_SCORE_CN"],
                    "stadium" => $game["S_NM"],
                    "status" => $game["GAME_STATE_SC"] === "4" ? $game["CANCEL_SC_NM"] : ($game["GAME_INN_NO"] ? $game["GAME_INN_NO"]."회".$game["GAME_TB_SC_NM"] : $game["G_TM"]),
                    "isGameFinished" => $game["GAME_STATE_SC"] === "3"
                );
                break;
            }
        }
    }

    echo json_encode($result);
?>