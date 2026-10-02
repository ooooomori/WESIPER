<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true))throw new InvalidArgumentException('Invalid mode');
$c=require $argv[2];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET SESSION lock_wait_timeout=10');
foreach(['kbo_season_records','kbo_season_pitch_records'] as $table){
    $indexes=[];
    foreach($db->query("SHOW INDEX FROM `$table`") as $r)$indexes[$r['Key_name']][(int)$r['Seq_in_index']]=$r['Column_name'];
    $wanted=['league_level','player_id','game_date','game_id'];
    $present=false;
    foreach($indexes as $columns){ksort($columns);if(array_slice(array_values($columns),0,4)===$wanted)$present=true;}
    if(!$present&&$mode==='--apply'){
        // Cover the per-player game count without fetching every plate-appearance row.
        // Explicit online DDL fails instead of falling back to a blocking table copy.
        $db->exec("ALTER TABLE `$table` ADD INDEX idx_search_league_player_game (league_level,player_id,game_date,game_id), ALGORITHM=INPLACE, LOCK=NONE");
        $present=true;
    }
    echo json_encode(['table'=>$table,'covering_index'=>$present,'mode'=>$mode]).PHP_EOL;
}
