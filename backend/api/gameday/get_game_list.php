<?php
    date_default_timezone_set('Asia/Seoul');
    
    $data = json_decode(file_get_contents('php://input'), true);

    $gameDate = new DateTime($data["date"]);
    $gameDate = $gameDate->format('Ymd');

    function getGameData($isKbo, $gameDate) {
        // 보낼 데이터 배열 생성
        $postData = array(
            'leId' => $isKbo ? '1' : '2',
            'srId' => $isKbo ? '0,1,3,4,5,7,9' : '0,1,9,10,15',
            'date' => $gameDate,
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
            $result = array("success" => true, "game" => $response['game']);
        };
        return $result;
    }
    
    $gameList = array();
    $gameList["kbo"] = getGameData(true, $gameDate);
    $gameList["futures"] = getGameData(false, $gameDate);
    $gameList["success"] = true;
     

    echo json_encode($gameList);
?>