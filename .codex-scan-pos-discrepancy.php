<?php
$c=require '/opt/bitnami/apache/conf/wesiper-db.php';
$p=new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// Position columns are unified; inspect missing active-player positions instead.
$sql="SELECT player_id,name,pos,img FROM kbo_player_data WHERE is_kbodle IN (1,2) AND (pos IS NULL OR pos='') ORDER BY player_id";
foreach($p->query($sql) as $r) echo json_encode($r,JSON_UNESCAPED_UNICODE),PHP_EOL;
