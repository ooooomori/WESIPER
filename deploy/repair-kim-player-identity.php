<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=require $argv[2];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(($argv[1]??'')==='--apply'){
 $db->beginTransaction();
 try{
  $parents=$db->query('SELECT * FROM kbo_player_data WHERE player_id IN (62349,67768) ORDER BY player_id FOR UPDATE')->fetchAll();
  if(count($parents)!==2||$parents[0]['birth']!=='1979-01-19'||$parents[1]['birth']!=='1998-04-15'||$parents[0]['name']!=='김태욱'||$parents[1]['name']!=='김태욱')throw new RuntimeException('Parent identity changed');
  $scope="player_id=62349 AND league_level=2 AND team='한화' AND game_date BETWEEN '2017-01-01' AND '2020-12-31'";
  $pitch=$db->query("SELECT * FROM kbo_season_pitch_records WHERE $scope ORDER BY id FOR UPDATE")->fetchAll();
  if(count($pitch)!==53)throw new RuntimeException('Unexpected pitcher repair scope');
  $games=array_values(array_unique(array_column($pitch,'game_id')));$gameSql=implode(',',array_map(static fn($s)=>$db->quote($s),$games));
  if($db->query("SELECT COUNT(*) FROM kbo_season_pitch_records WHERE league_level=2 AND player_id=67768 AND game_id IN ($gameSql)")->fetchColumn())throw new RuntimeException('Target pitcher record already exists');
  $bat=$db->query("SELECT * FROM kbo_season_records WHERE $scope ORDER BY PK FOR UPDATE")->fetchAll();
  $linkScope="league_level=2 AND pitcher_id=62349 AND LEFT(game_id,13) IN ($gameSql)";
  $links=$db->query("SELECT * FROM kbo_season_records WHERE $linkScope ORDER BY PK FOR UPDATE")->fetchAll();
  $movementScope="player_id=62349 AND team='한화' AND player_name='김병현' AND event_date BETWEEN '2017-01-01' AND '2020-12-31'";
  $movements=$db->query("SELECT * FROM kbo_player_movements WHERE $movementScope ORDER BY id FOR UPDATE")->fetchAll();
  if(count($bat)!==1||count($movements)!==2)throw new RuntimeException('Unexpected batting/movement repair scope');
  $before=['parents'=>$parents,'pitchers'=>$pitch,'batting'=>$bat,'pitcher_links'=>$links,'movements'=>$movements];
  $dir=dirname(__DIR__).'/.identity-repairs';if(!is_dir($dir))mkdir($dir,0700,true);
  $archive="$dir/kim-62349-67768-before-".(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Ymd_His').'.json.gz';
  $json=json_encode($before,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$compressed=gzencode($json,9);
  if(file_put_contents($archive,$compressed)!==strlen($compressed)||gzdecode(file_get_contents($archive))!==$json)throw new RuntimeException('Repair evidence verification failed');
  $db->exec("UPDATE kbo_player_data SET name='김병현',oldname=NULL WHERE player_id=62349");
  $db->exec("UPDATE kbo_player_data SET oldname='김병현' WHERE player_id=67768");
  $counts=[];
  $counts['pitchers']=$db->exec("UPDATE kbo_season_pitch_records SET player_id=67768 WHERE $scope");
  $counts['batting']=$db->exec("UPDATE kbo_season_records SET player_id=67768 WHERE $scope");
  $counts['pitcher_links']=$db->exec("UPDATE kbo_season_records SET pitcher_id=67768 WHERE $linkScope");
  $counts['movements']=$db->exec("UPDATE kbo_player_movements SET player_id=67768 WHERE $movementScope");
  if($counts!==['pitchers'=>count($pitch),'batting'=>count($bat),'pitcher_links'=>count($links),'movements'=>count($movements)])throw new RuntimeException('Affected row count mismatch');
  // Verify every original column; only the intended identity fields may change.
  $verify=static function(string $table,string $key,array $rows,array $replace)use($db):void{
   if(!$rows)return;$ids=implode(',',array_map('intval',array_column($rows,$key)));
   $actual=$db->query("SELECT * FROM `$table` WHERE `$key` IN ($ids) ORDER BY `$key`")->fetchAll();
   foreach($rows as &$row)foreach($replace as $field=>$value)$row[$field]=$value;unset($row);
   if($actual!==$rows)throw new RuntimeException('Unexpected field change in '.$table);
  };
  $verify('kbo_season_pitch_records','id',$pitch,['player_id'=>67768]);
  $verify('kbo_season_records','PK',$bat,['player_id'=>67768]);
  $verify('kbo_season_records','PK',$links,['pitcher_id'=>67768]);
  $verify('kbo_player_movements','id',$movements,['player_id'=>67768]);
  $verify('kbo_player_data','player_id',[$parents[0]],['name'=>'김병현','oldname'=>null]);
  $verify('kbo_player_data','player_id',[$parents[1]],['oldname'=>'김병현']);
  $db->commit();
  // Expire derived local summaries so the corrected identities show immediately.
  foreach(['wesiper-player-search-web','wesiper-profile-overview-web','wesiper-profile-rankings-web'] as $cacheDir){
   $path=sys_get_temp_dir().DIRECTORY_SEPARATOR.$cacheDir;
   if(is_dir($path))foreach(glob($path.'/*.json')?:[] as $cacheFile)unlink($cacheFile);
  }
  echo json_encode(['passed'=>true,'updated'=>$counts,'evidence'=>$archive],JSON_UNESCAPED_UNICODE).PHP_EOL;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 exit;
}
echo json_encode(['players'=>$db->query('SELECT * FROM kbo_player_data WHERE player_id IN (62349,67768)')->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
foreach(['kbo_season_records','kbo_season_pitch_records'] as $table){
    $columns=$db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $name=in_array('player_name',$columns,true)?',player_name':'';
    echo json_encode([$table=>$db->query("SELECT player_id,league_level,YEAR(game_date) year,team$name,COUNT(*) rows_count FROM `$table` WHERE player_id IN (62349,67768) GROUP BY player_id,league_level,YEAR(game_date),team$name ORDER BY player_id,year")->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
echo json_encode(['movements'=>$db->query("SELECT id,player_id,event_date,event_type,team,player_name,note FROM kbo_player_movements WHERE player_id IN (62349,67768) ORDER BY event_date")->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
echo json_encode(['careers'=>$db->query('SELECT * FROM kbo_player_career WHERE player_id IN (62349,67768)')->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
echo json_encode(['nicknames'=>$db->query('SELECT * FROM kbo_player_nicknames WHERE player_id IN (62349,67768)')->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
echo json_encode(['id_tables'=>$db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('player_id','pitcher_id') AND TABLE_NAME NOT LIKE '%backup%' ORDER BY TABLE_NAME")->fetchAll()]).PHP_EOL;
echo json_encode(['bad_pitcher_links'=>$db->query("SELECT YEAR(game_date) year,team,pitcher_name,COUNT(*) rows_count FROM kbo_season_records WHERE league_level=2 AND pitcher_id=62349 AND game_date>='2017-01-01' GROUP BY YEAR(game_date),team,pitcher_name")->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
echo json_encode(['sample_games'=>$db->query("SELECT game_id,game_date,team,inning FROM kbo_season_pitch_records WHERE player_id=62349 AND team='한화' AND league_level=2 ORDER BY game_date LIMIT 3")->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
foreach(['kbo_player_game_stats','kbo_player_predictions','kbo_player_titleholder'] as $table){
 echo json_encode([$table.' columns'=>$db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN)]).PHP_EOL;
 echo json_encode([$table.' rows'=>$db->query("SELECT * FROM `$table` WHERE player_id IN (62349,67768) LIMIT 3")->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
