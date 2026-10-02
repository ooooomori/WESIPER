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
    
    function image_exists($p_no) {
        return file_exists($_SERVER["DOCUMENT_ROOT"]."/assets/images/player/kbo/$p_no.png");
    }
?>
