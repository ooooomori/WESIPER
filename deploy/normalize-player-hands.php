<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=require $argv[2];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$scope="`bat` IN ('우','좌','양') OR `throw` IN ('우','좌','양')";
if(($argv[1]??'')==='--apply'){
 $db->beginTransaction();
 try{
  $rows=$db->query("SELECT * FROM kbo_player_data WHERE $scope ORDER BY player_id FOR UPDATE")->fetchAll();
  $counts=['bat'=>0,'throw'=>0];
  if($rows){
   $dir=dirname(__DIR__).'/.identity-repairs';if(!is_dir($dir))mkdir($dir,0700,true);
   $archive=$dir.'/hands-before-'.gmdate('Ymd_His').'.json.gz';
   $json=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$compressed=gzencode($json,9);
   if(file_put_contents($archive,$compressed)!==strlen($compressed)||gzdecode(file_get_contents($archive))!==$json)throw new RuntimeException('Evidence verification failed');
   foreach(['bat'=>'타','throw'=>'투'] as $field=>$suffix){
    $counts[$field]=$db->exec("UPDATE kbo_player_data SET `$field`=CONCAT(`$field`,'$suffix') WHERE `$field` IN ('우','좌','양')");
    foreach($rows as &$row)if(in_array($row[$field],['우','좌','양'],true))$row[$field].=$suffix;unset($row);
   }
   $actual=$db->query('SELECT * FROM kbo_player_data WHERE player_id IN ('.implode(',',array_map('intval',array_column($rows,'player_id'))).') ORDER BY player_id')->fetchAll();
   if($actual!==$rows)throw new RuntimeException('Unexpected field changes');
  }
  if($db->query("SELECT COUNT(*) FROM kbo_player_data WHERE $scope")->fetchColumn())throw new RuntimeException('Short values remain');
  $db->commit();
  foreach(['wesiper-player-search-web','wesiper-profile-overview-web','wesiper-profile-rankings-web'] as $cacheDir){
   $path=sys_get_temp_dir().DIRECTORY_SEPARATOR.$cacheDir;
   if(is_dir($path))foreach(glob($path.'/*.json')?:[] as $cacheFile)unlink($cacheFile);
  }
  echo json_encode(['passed'=>true,'players'=>count($rows),'updated_cells'=>$counts,'evidence'=>$archive??null],JSON_UNESCAPED_UNICODE).PHP_EOL;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
foreach(['bat','throw'] as $field)echo json_encode([$field=>$db->query("SELECT `$field` value,COUNT(*) players FROM kbo_player_data GROUP BY `$field` ORDER BY `$field`")->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
echo json_encode(['remaining_short_players'=>(int)$db->query("SELECT COUNT(*) FROM kbo_player_data WHERE $scope")->fetchColumn(),'identities'=>$db->query('SELECT player_id,name,oldname,bat,`throw`,birth FROM kbo_player_data WHERE player_id IN (62349,67768)')->fetchAll()],JSON_UNESCAPED_UNICODE).PHP_EOL;
