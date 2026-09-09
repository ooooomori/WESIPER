<?php
    date_default_timezone_set('Asia/Seoul');

$dbConfig = require dirname(__DIR__, 2) . '/config/database.php';

/** MySQL 접속 */
$con = mysqli_connect(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database']
);
    if(mysqli_error($con)) {
        echo mysqli_error();
        exit();
    } else {
        //
        
    }

    $playerlist = "player_data";

    function getPosition($posNo) {
        switch($posNo) {
            case 1:
            case "P":
                return "투수"; break;
            case 2: 
            case "C":
                return "포수"; break;
            case 3: 
            case "1B":
                return "1루수"; break;
            case 4:
            case "2B":
                return "2루수"; break;
            case 5: 
            case "3B":
                return "3루수"; break;
            case 7: 
            case "LF":
                return "좌익수"; break;
            case 8: 
            case "CF":
                return "중견수"; break;
            case 9: 
            case "RF":
                return "우익수"; break;
            case 6: 
            case "SS":
                return "유격수"; break;
            default: return "지명타자";

        }
    }
    
    function curlFetch($url, $proxy = null, $port = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0"); // Snoopy 기본 UA 비슷하게
    
        if ($proxy && $port) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYPORT, $port);
        }
    
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // HTTPS 에러 방지
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    
    function searchSubPos($p_no) {
        $url = "https://statiz.sporki.com/stats/?m=main&m2=fielding&m3=default&so=G&ob=DESC&year=2024&sy=&ey=&te=&po=&lt=10100&reg=A&pe=&ds=&de=&we=&hr=&ha=&ct=&st=&vp=&bo=&pt=&pp=&ii=&vc=&um=&oo=&rr=&sc=&bc=&ba=&li=&as=&ae=&pl=&gc=&lr=&pr=50&ph=$p_no&hs=&us=&na=&ls=0&sf1=G&sk1=&sv1=&sf2=G&sk2=&sv2=-25";
        $html = curlFetch($url);
    
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
    
        $elements = $xpath->query("//div[@class='teams']//span[3]//text()");

        $pos_array = array();
        foreach ($elements as $element) {
            $pos_array[] = getPosition($element->nodeValue);
        }
    
        return $pos_array;
    }
    
    function shortenDraft($draft) {
        $draft = str_replace('라운드', 'R', $draft);
        if(strpos($draft, "1차") !== false) {
            return "1차 지명";
        } else if (strpos($draft, "2차") !== false) {
            return "2차 ".explode(" ", $draft)[3];
        }  else if (strpos($draft, "육성") !== false || strpos($draft, "신고") !== false) {
            return "육성선수";
        } else if (strpos($draft, "부상") !== false) {
            return "부상 대체";
        } else {
            return explode(" ", $draft)[2];
        }
    }

    function getPlayerData($p_no, $type) {
        $url = "https://statiz.sporki.com/player/?m=playerinfo&p_no=$p_no";
        $html = curlFetch($url);
    
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);        
    
        $elements = $xpath->query("//ul[@class='profile']//li//em");
    
        $playerBirth = trim($elements[0]->nodeValue);
        $playerBirth = str_replace(array("년 ", "월 ", "일"), "", $playerBirth);
        $playerHand = $elements[1]->nodeValue;
        $playerYear = trim($elements[3]->nodeValue);
        $playerDraft = $elements[5]->nodeValue;
        $playerTeam = $elements[6]->nodeValue;
        $playerPos = $elements[7]->nodeValue;
    
        $backNo = $xpath->query("(//span[@class='number'])[last()]");
        $backNo = @$backNo->item(0)->nodeValue;
    
        switch($p_no) {
            case 14108: $playerHand = "우투우타"; break;
            case 11394: $playerTeam = "KT"; break;
            case 14196: $playerTeam = "한화"; break;
            case 11415: $playerPos = "구원"; break;
            case 14133: $playerPos = "우익수"; break;
        }
        
        $peYear = explode(' ', $playerYear)[2];
    
        if ($peYear !== "2024년" && $peYear !== "2025년") {
            return array("error" => $playerYear);
        } else {
            $currentDay = new DateTime();
            $birthDay = new Datetime($playerBirth);
            $age = $birthDay->diff($currentDay)->y;
    
            $draft = str_replace('라운드', 'R', $playerDraft);
            $draft = trim($draft);
    
            if($playerTeam === "") $playerTeam = explode(" ", $draft)[1];
    
            if(strpos($draft, "1차") !== false) {
                $draft = "1차 지명";
            } else if (strpos($draft, "2차") !== false) {
                $draft = "2차 ".explode(" ", $draft)[3];
            }  else if (strpos($draft, "육성") !== false || strpos($draft, "신고") !== false) {
                $draft = "육성선수";
            } else if (strpos($draft, "부상") !== false) {
                $draft = "부상 대체";
            } else {
                $draft = explode(" ", $draft)[2];
            }
    
            $subPos = array();
            if($playerPos === "투수") {
                $stats = $xpath->query("//div[@class='box_cont'][1]//tr[1]//td");
                @$g = (int)$stats[5]->nodeValue;
                @$gs = (int)$stats[6]->nodeValue;
                @$gr = (int)$stats[7]->nodeValue;
                if($g === 0) $playerPos = "구원";
                else if($gs >= $gr) {
                    $playerPos = "선발";
                    if($type === "answer" && $gr >= $g * 0.15) $subPos[] = "구원";
                } else {
                    $playerPos = "구원";
                    if($type === "answer" && $gs >= $g * 0.15) $subPos[] = "선발";
                }
            }
    
            $searchResult = array(
                "SporkId" => $p_no,
                "Pos" => $playerPos,
                "SubPos" => $subPos,
                "Age" => $age,
                "Pit" => mb_substr($playerHand, 0, 2, 'utf-8'),
                "Bat" => mb_substr($playerHand, 2, 2, 'utf-8'),
                "Draft" => $draft,
                "Team" => $playerTeam,
                "BackNo" => $backNo,
                "Debut" => explode(' ', $playerYear)[0],
                "Retire" => $peYear
            );
    
            if($type === "answer") {
                $searchResult["SubPos"] = array_merge($subPos, searchSubPos($p_no));
            }
    
            return $searchResult;
        }
    }
    
?>
