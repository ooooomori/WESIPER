<?php
declare(strict_types=1);
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$types=$db->query('SELECT event_type,COUNT(*) n FROM kbo_player_movements GROUP BY event_type ORDER BY n DESC')->fetchAll();
$rows=$db->query("SELECT id,event_date,event_type,team,player_id,player_name,note FROM kbo_player_movements WHERE event_type LIKE '%FA%' OR event_type LIKE '%계약%' ORDER BY event_date,id")->fetchAll();
file_put_contents(dirname(__DIR__).'/.movement-contracts.json',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents(dirname(__DIR__).'/.contract-enrichment/movements.json',json_encode($db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents(dirname(__DIR__).'/.contract-enrichment/players.json',json_encode($db->query('SELECT player_id,name,oldname,birth,pos,team,draft,school,img FROM kbo_player_data ORDER BY player_id')->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['types'=>$types,'contract_rows'=>count($rows),'unlinked'=>(int)$db->query('SELECT COUNT(*) FROM kbo_player_movements WHERE player_id IS NULL')->fetchColumn()],JSON_UNESCAPED_UNICODE).PHP_EOL;
