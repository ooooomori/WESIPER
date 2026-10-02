<?php
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$rows=$db->query("SELECT player_id,game_date,team,league_level FROM kbo_season_pitch_records WHERE player_id IN (51454,60146,66145,51109) AND game_date BETWEEN '2021-01-01' AND '2026-12-31' ORDER BY game_date")->fetchAll();
file_put_contents(dirname(__DIR__).'/.contract-enrichment/ambiguous-games.json',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo count($rows).PHP_EOL;
