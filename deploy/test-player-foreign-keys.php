<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$config=require($argv[1]??__DIR__.'/../backend/config/database.php');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$invalid=2147483647;
if((int)$db->query("SELECT COUNT(*) FROM kbo_player_data WHERE player_id=$invalid")->fetchColumn()!==0)throw new RuntimeException('Test ID exists');
$queries=[
    'kbo_player_nicknames'=>"INSERT INTO kbo_player_nicknames (player_id,nickname) VALUES ($invalid,'FK_TEST_ONLY')",
    'kbo_season_records'=>"INSERT INTO kbo_season_records (player_id) VALUES ($invalid)",
    'kbo_season_pitch_records'=>"INSERT INTO kbo_season_pitch_records (game_id,game_date,team,player_id,inning,pitched) VALUES ('FK_TEST_ONLY','2026-09-30','TEST',$invalid,'0',0)",
];
foreach($queries as $table=>$sql){
    $db->beginTransaction();$rejected=false;
    try{$db->exec($sql);}catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1452)throw $e;$rejected=true;}finally{$db->rollBack();}
    if(!$rejected)throw new RuntimeException('Foreign key did not reject invalid player: '.$table);
    echo "PASS: $table rejects a nonexistent player ID\n";
}
