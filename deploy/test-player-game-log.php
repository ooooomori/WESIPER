<?php
if(PHP_SAPI!=='cli')exit;
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
require '/home/bitnami/wesiper-weather-preview/lib/player-records.php';
foreach([52605,77829,76849] as $id){
    $q=$pdo->prepare('SELECT player_id PlayerId,pos Pos,is_kbodle IsKbodle FROM kbo_player_data WHERE player_id=?');$q->execute([$id]);$player=$q->fetch(PDO::FETCH_ASSOC);
    $table=str_contains((string)$player['Pos'],'투수')?'kbo_season_pitch_records':'kbo_season_records';
    $q=$pdo->prepare("SELECT DISTINCT YEAR(game_date) FROM `$table` WHERE player_id=? ORDER BY YEAR(game_date) DESC");$q->execute([$id]);$years=$q->fetchAll(PDO::FETCH_COLUMN);
    $year=(int)$years[0];
    foreach(['regular','preseason','postseason'] as $season){
        $records=profileRecords($pdo,$player,getKBOSchedule(),$year,$season);
        foreach($records['games']??[] as $game){
            [$start,$end]=getKBOSchedule()[$year][$season];
            if($game['date']<$start||$game['date']>$end)throw new RuntimeException('Game outside season');
        }
        echo json_encode(['id'=>$id,'year'=>$year,'season'=>$season,'games'=>count($records['games']??[])],JSON_UNESCAPED_UNICODE)."\n";
    }
}
echo "PASS\n";
