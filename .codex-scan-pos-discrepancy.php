<?php
$c=require '/opt/bitnami/apache/conf/wesiper-db.php';
$p=new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$sql="SELECT kl.p_no,kl.p_name,kl.p_pos AS list_pos,pd.pos AS data_pos,kl.p_img FROM kbo_playerlist_20250613 kl JOIN player_data pd ON pd.playerId=kl.p_no WHERE kl.p_pos<>pd.pos ORDER BY kl.p_no";
foreach($p->query($sql) as $r) echo json_encode($r,JSON_UNESCAPED_UNICODE),PHP_EOL;
