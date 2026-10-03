<?php
declare(strict_types=1);
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/.contract-enrichment';
$plan=json_decode(file_get_contents($dir.'/historical-fa-plan.json'),true,512,JSON_THROW_ON_ERROR);
if($plan['unresolved'] || $plan['source_rows']!==193 || count($plan['unsigned'])!==4)throw new RuntimeException('Incomplete FA plan');
$parents=array_fill_keys($db->query('SELECT player_id FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN),true);
$hash=hash_file('sha256',$dir.'/kbo-2026.pdf');$keys=[];
foreach($plan['inserts'] as $r){
 if(!isset($parents[$r['player_id']]) || $r['source_sha256']!==$hash || $r['event_date']!==null || $r['year']<2000 || $r['year']>2017)throw new RuntimeException('Invalid historical source or player');
 if(isset($keys[$r['source_key']]))throw new RuntimeException('Duplicate plan');$keys[$r['source_key']]=true;
}
if(($argv[2]??'')!=='--write'){echo json_encode(['dry_run'=>true,'contracts'=>count($plan['inserts']),'duplicates'=>count($plan['duplicates']),'unsigned'=>count($plan['unsigned']),'all_players_linked'=>true]).PHP_EOL;exit;}
$snapshot=$dir.'/historical-fa-before.json.gz';
if(!file_exists($snapshot))file_put_contents($snapshot,gzencode(json_encode(['schema'=>$db->query('SHOW CREATE TABLE kbo_player_movements')->fetch(),'rows'=>$db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),9));
$columns=array_column($db->query('SHOW COLUMNS FROM kbo_player_movements')->fetchAll(),null,'Field');
if($columns['event_date']['Null']!=='YES')$db->exec('ALTER TABLE kbo_player_movements MODIFY COLUMN event_date DATE NULL');
$db->beginTransaction();
try{
 $before=array_column($db->query('SELECT * FROM kbo_player_movements ORDER BY id FOR UPDATE')->fetchAll(),null,'id');
 $byKey=array_column($before,null,'source_key');$added=[];$skipped=0;
 foreach($plan['inserts'] as $r){
  if(isset($byKey[$r['source_key']])){foreach($r as $f=>$v)if($byKey[$r['source_key']][$f]!==$v)throw new RuntimeException('Existing import differs: '.$f);$skipped++;continue;}
  foreach($before as $old){
   if($old['player_id']!==$r['player_id'] || $old['team']!==$r['team'] || !in_array($old['event_type'],['FA 계약','해외 복귀 FA 계약'],true))continue;
   $season=$old['event_date']===null?$old['year']:((int)substr($old['event_date'],0,4)+((int)substr($old['event_date'],5,2)>=10?1:0));
   if($season===$r['year'])throw new RuntimeException('Concurrent semantic duplicate');
  }
  $r['contract_verified_at']=gmdate('Y-m-d H:i:s');$fields=array_keys($r);
  $q=$db->prepare('INSERT INTO kbo_player_movements (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');$q->execute(array_values($r));
  $id=(int)$db->lastInsertId();$q=$db->prepare('SELECT * FROM kbo_player_movements WHERE id=?');$q->execute([$id]);$actual=$q->fetch();
  foreach($r as $f=>$v)if($actual[$f]!==$v)throw new RuntimeException('Inserted value mismatch: '.$f);
  $added[$id]=$actual;
 }
 $after=array_column($db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll(),null,'id');
 if($after!==$before+$added)throw new RuntimeException('Unexpected existing-row change');
 $rows=array_values(array_filter($after,fn($r)=>isset($keys[$r['source_key']])));
 if(count($rows)!==count($plan['inserts']))throw new RuntimeException('Missing imported rows');
 foreach($rows as $r)if($r['player_id']===null)throw new RuntimeException('Unlinked historical player');
 $db->commit();
 $years=[];$currencies=[];foreach($rows as $r){$years[$r['year']]=($years[$r['year']]??0)+1;$currencies[$r['contract_currency']]=($currencies[$r['contract_currency']]??0)+1;}ksort($years);
 $result=['source_rows'=>193,'inserted'=>count($added),'already_present'=>$skipped,'unsigned_excluded'=>count($plan['unsigned']),'year_counts'=>$years,'currencies'=>$currencies,'unknown_total'=>count(array_filter($rows,fn($r)=>$r['contract_total_amount']===null)),'unknown_term'=>count(array_filter($rows,fn($r)=>$r['contract_term']===null)),'existing_rows_unchanged'=>true,'all_players_linked'=>true,'total_movements'=>count($after)];
 file_put_contents($dir.'/historical-fa-verification.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
