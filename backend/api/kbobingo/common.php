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

    $playerlist = "kbo_playerlist_20250613";
    
    function image_exists($p_no) {
        return file_exists($_SERVER["DOCUMENT_ROOT"]."/assets/images/player/kbo/$p_no.png");
    }
?>
