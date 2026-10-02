<?php
if(PHP_SAPI!=='cli')exit;
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
require '/home/bitnami/wesiper-weather-preview/lib/player-rankings.php';
$ranks=profileRankings($pdo,2026,getKBOSchedule(),true);
if(!$ranks)throw new RuntimeException('Empty career rankings');
foreach([76849,76325,76232,76300] as $id){
    $s=$pdo->prepare('SELECT name FROM kbo_player_data WHERE player_id=?');$s->execute([$id]);$name=$s->fetchColumn();
    echo json_encode(['id'=>$id,'name'=>$name,'ranks'=>$ranks[$id]??null],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
echo 'PASS '.count($ranks).' players'.PHP_EOL;
