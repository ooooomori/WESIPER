<?php
    date_default_timezone_set('Asia/Seoul');

$dbConfig = require dirname(__DIR__, 2) . '/config/database.php';

/** MySQL 접속 */
$con = mysqli_connect(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database'],
    $dbConfig['port'] ?? 3306
);
    if(mysqli_error($con)) {
        echo mysqli_error();
        exit();
    } else {
        //
        
    }

    mysqli_set_charset($con, $dbConfig['charset']);
    $playerlist = "kbo_player_data";

    
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

    
?>
