<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$c=require getenv('WESIPER_DB_CONFIG');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/output/retired-schools';$plan=json_decode(file_get_contents($dir.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
if($plan['collected']!==$plan['targets'] || count($plan['updates'])+count($plan['skipped'])!==$plan['targets'])throw new RuntimeException('Incomplete collection');
$keys=[];
foreach($plan['updates'] as $r){
 if(isset($keys[$r['player_id']]) || strlen((string)$r['player_id'])===4 || trim($r['school'])==='')throw new RuntimeException('Invalid school update');
 if(hash_file('sha256',$dir.'/'.$r['source_cache'])!==$r['source_sha256'])throw new RuntimeException('Source changed');
 $keys[$r['player_id']]=true;
}
if(!in_array('--apply',$argv,true)){echo json_encode(['dry_run'=>true,'targets'=>$plan['targets'],'updates'=>count($plan['updates']),'skipped'=>count($plan['skipped'])],JSON_UNESCAPED_UNICODE);exit;}
$db->beginTransaction();
try{
 $before=array_column($db->query('SELECT player_id,name,school,is_kbodle FROM kbo_player_data ORDER BY player_id FOR UPDATE')->fetchAll(),null,'player_id');$expected=$before;
 foreach($plan['updates'] as $r){
  $p=$before[$r['player_id']]??null;
  if(!$p || (string)$p['is_kbodle']!=='0' || $p['name']!==$r['name'] || $p['school']!==$r['old_school'])throw new RuntimeException('Concurrent player change: '.$r['player_id']);
  $expected[$r['player_id']]['school']=$r['school'];
 }
 file_put_contents($dir.'/before-'.gmdate('Ymd-His').'.json',json_encode(array_values($before),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 $affected=0;
 foreach(array_chunk($plan['updates'],200) as $chunk){
  $sql='UPDATE kbo_player_data SET school=CASE player_id ';$params=[];$ids=[];
  foreach($chunk as $r){$sql.='WHEN ? THEN ? ';$params[]=$r['player_id'];$params[]=$r['school'];$ids[]=$r['player_id'];}
  $sql.="ELSE school END WHERE is_kbodle=0 AND CHAR_LENGTH(CAST(player_id AS CHAR))<>4 AND (school IS NULL OR TRIM(school)='') AND player_id IN (".implode(',',array_fill(0,count($ids),'?')).')';
  $q=$db->prepare($sql);$q->execute(array_merge($params,$ids));$affected+=$q->rowCount();
 }
 $after=array_column($db->query('SELECT player_id,name,school,is_kbodle FROM kbo_player_data ORDER BY player_id')->fetchAll(),null,'player_id');
 if($after!==$expected || $affected!==count($plan['updates']))throw new RuntimeException('School verification failed');
 $db->commit();
 $out=['targets'=>$plan['targets'],'updated'=>$affected,'skipped'=>count($plan['skipped']),'existing_schools_preserved'=>true,'four_digit_ids_unchanged'=>true,'verified'=>true];file_put_contents($dir.'/verification.json',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
