<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$c=require dirname(__DIR__).'/config/database.php';
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require dirname(__DIR__).'/lib/player-search.php';
// Temporary fixtures shadow live tables only on this connection.
$db->exec('CREATE TEMPORARY TABLE kbo_player_data (player_id INT,name VARCHAR(64),fullname VARCHAR(64),oldname VARCHAR(64),img VARCHAR(64),pos VARCHAR(64),mainPos VARCHAR(64),draft VARCHAR(64),retire INT,team VARCHAR(64),backNo VARCHAR(8),is_kbodle INT,is_number_retired INT)');
$db->exec('CREATE TEMPORARY TABLE kbo_player_nicknames (player_id INT,nickname VARCHAR(64))');
foreach(['kbo_season_records','kbo_season_pitch_records'] as $t)$db->exec("CREATE TEMPORARY TABLE $t (player_id INT,game_id VARCHAR(20),game_date DATE,league_level INT)");
foreach(['kbo_player_season_batting_totals','kbo_player_season_pitching_totals'] as $t)$db->exec("CREATE TEMPORARY TABLE $t (player_id INT,year INT,games INT,league_level INT,row_scope VARCHAR(8),series_id INT)");
$q=$db->prepare('INSERT INTO kbo_player_data (player_id,name,fullname,oldname,pos,is_kbodle) VALUES (?,?,?,?,?,1)');
foreach([[1,'검색대상','다른이름','옛이름','코치'],[2,'다른이름','검색대상','옛이름','코치'],[3,'다른이름','다른이름','검색대상','코치'],[4,'다른이름','다른이름','옛이름','코치'],[5,'검색대상선수',null,null,'투수'],[6,'검색대상미확정',null,null,null]] as $r)$q->execute($r);
$db->exec("INSERT INTO kbo_player_nicknames VALUES (4,'검색 대상')");
$rows=searchKboPlayers($db,'검색대상',false);$ids=array_map('intval',array_column($rows,'player_id'));sort($ids);
if($ids!==[5,6])throw new RuntimeException('Coach exclusion or NULL position handling failed');
echo "PASS: coaches excluded from name/fullname/oldname/nickname matches; players including NULL positions retained\n";
