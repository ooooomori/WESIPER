<?php
    date_default_timezone_set('Asia/Seoul');
    
    // 사용자 정의 비교 함수
    function compareByWPA($a, $b) {
        return $b['WPA'] - $a['WPA']; // 내림차순 정렬
    }

    function getKeyPlayer($type) {
        $data = json_decode(file_get_contents('php://input'), true);
        // 보낼 데이터 배열 생성
        $postData = array(
            'leId' => $data['leId'],
            'srId' => $data['srId'],
            'gameId' => $data['gameId'],
            'groupSc'=> 'GAME_WPA_RT',
            'sort'=> 'DESC',
        );


        // POST 요청을 보낼 대상 PHP 파일 URL
        $url = "https://www.koreabaseball.com/ws/Schedule.asmx/GetKeyPlayer$type";

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
            $result = array("success" => false, "error" => curl_error($ch), "player" => array());
        }
        else if ($response === null) {
            $result = array("success" => false, "error" => "데이터 없음", "player" => array());
        } else {
            $result = array("success" => true, "player" => array());
            foreach($response['record'] as $data) {
                $data['WPA'] = preg_match('/\d+(\.\d+)?/', $data['RECORD_IF'], $matches) ? (float)$matches[0] : null;
                preg_match('/\((.*?)\)/', $data['RECORD_IF'], $parenthesesMatches);
                $data['RECORD'] = isset($parenthesesMatches[1]) ? $parenthesesMatches[1] : null;
                $data['POS'] = $type;
                $result["player"][] = $data;
            }
            
        };
        return $result;
    }
    
    $gameDetail = array();
    $gameDetail["KeyPlayer"] = array_merge(getKeyPlayer("Pitcher")["player"], getKeyPlayer("Hitter")["player"]);

    // 정렬
    usort($gameDetail["KeyPlayer"], 'compareByWPA');
    $gameDetail["success"] = true;
     

    echo json_encode($gameDetail);
?>