<?php
    include_once "common.php";

    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }
    
    /*
    include_once '../Snoopy.class.php';
        
    $snoopy = new Snoopy;  // Corrected capitalization

    $snoopy->fetch("https://www.koreabaseball.com/Player/RegisterAll.aspx");
    $html = $snoopy->results;

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);        

    $result = [];
    $result["status"] = 200;
    $dateElement = $xpath->query('//*[@id="cphContents_cphContents_cphContents_lblGameDate"]');
    $date = $dateElement->item(0)->textContent;
    if($dateElement->length > 0) {
        $result["date"] = $date;

        for ($trIndex = 1; $trIndex <= 10; $trIndex++) {
            // td[1]의 텍스트를 키로 사용
            $keyQuery = "//*[@id='cphContents_cphContents_cphContents_udpRecord']/div/table/tbody/tr[$trIndex]/th";
            $keyElements = $xpath->query($keyQuery);
            if ($keyElements->length > 0) {
                $key = trim($keyElements->item(0)->textContent);
                
                $trResult = [];
                for ($tdIndex = 3; $tdIndex <= 6; $tdIndex++) {
                    $query = "//*[@id='cphContents_cphContents_cphContents_udpRecord']/div/table/tbody/tr[$trIndex]/td[$tdIndex]/ul";
                    $elements = $xpath->query($query);
                    
                    $tdResult = [];
                    if ($elements->length > 0) {
                        $ul = $elements->item(0);
                        $lis = $ul->getElementsByTagName('li');
                        foreach ($lis as $li) {
                            $tdResult[] = trim($li->textContent);
                        }
                    }
                    $trResult[] = $tdResult;
                }
                $result[$key] = $trResult;
            } else {
                $result["status"] = 404;
            }
        }

    } else {
        $result["status"] = 404;
    }

    */

// 1. 기본 결과 구조 및 팀 목록 정의
$teams = ["KIA", "SSG", "NC", "키움", "두산", "삼성", "한화", "롯데", "LG", "KT"];
$result = ['status' => 200]; 

foreach ($teams as $team) {
    $result[$team] = [[], [], [], []];
}

$posMap = ["투수" => 0, "포수" => 1, "내야수" => 2, "외야수" => 3];

try {
    // --- JSON 파일 처리 ---
    $jsonFilePath = 'update_log.json';
    if (!file_exists($jsonFilePath)) throw new Exception("JSON file not found");

    $jsonContent = file_get_contents($jsonFilePath);
    $updateData = json_decode($jsonContent, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON format");

    // 최신 날짜 추출 (첫 번째 키)
    $latestDate = array_key_first($updateData); 

    $result['update_history'] = $updateData;
    $result['latest_update_date'] = $latestDate;

    // --- 2. MySQLi 데이터베이스 조회 ---
    // $con은 mysqli 객체라고 가정 (예: $con = new mysqli("host", "user", "pw", "db");)
    $query = "SELECT `name`, `pos`, `team` FROM $playerlist ORDER BY `name`";
    $dbResult = $con->query($query);

    if (!$dbResult) {
        throw new Exception("Query failed: " . $con->error);
    }

    // 데이터 패치 (mysqli_fetch_assoc)
    while ($row = $dbResult->fetch_assoc()) {
        $name = $row['name'];
        $pos = $row['pos'];
        $team = $row['team'];

        if (isset($result[$team]) && isset($posMap[$pos])) {
            $idx = $posMap[$pos];
            $result[$team][$idx][] = $name;
        }
    }

    // 메모리 해제
    $dbResult->free();

} catch (Exception $e) {
    // 오류 발생 시
    $result = [
        'status' => 404,
        'message' => $e->getMessage()
    ];
}

// 4. 결과 리턴
header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE);

// 5. 연결 종료 (MySQLi 문법)
if (isset($con)) {
    $con->close();
}
    
?>
    
