<?php
include_once "common.php";
$schedule = getKBOSchedule();
echo json_encode($schedule, JSON_UNESCAPED_UNICODE);
?>