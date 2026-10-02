<?php
declare(strict_types=1);
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/.contract-enrichment';
$names=array_unique(array_column(json_decode(file_get_contents($dir.'/movements.json'),true),'player_name'));
$players=json_decode(file_get_contents($dir.'/players.json'),true);
$ids=[];foreach($players as $p)if(in_array($p['name'],$names,true)||($p['oldname']&&in_array($p['oldname'],$names,true)))$ids[]=(int)$p['player_id'];
$result=[];
foreach(['kbo_season_records','kbo_season_pitch_records'] as $table){
 $name=$table==='kbo_season_records'?'player_name':'NULL';
 $groupName=$table==='kbo_season_records'?',player_name':'';
 $cache=$dir.'/'.$table.'-identities.json';
 $rows=file_exists($cache)?json_decode(file_get_contents($cache),true):$db->query("SELECT player_id,$name player_name,team,YEAR(game_date) year,MIN(game_date) first_date,MAX(game_date) last_date,COUNT(*) n FROM `$table` WHERE league_level IN (1,2) AND player_id IN (".implode(',',$ids).") AND game_date BETWEEN '2015-01-01' AND '2026-12-31' GROUP BY player_id$groupName,team,YEAR(game_date)")->fetchAll();
 file_put_contents($cache,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 foreach($rows as $row)$result[]=$row+['source_table'=>$table];
 echo $table.' '.count($rows).PHP_EOL;
}
file_put_contents($dir.'/identity-records.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
