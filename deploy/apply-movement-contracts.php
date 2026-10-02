<?php
declare(strict_types=1);
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/.contract-enrichment';
$plan=json_decode(file_get_contents($dir.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
if($plan['unresolved'])throw new RuntimeException('Unresolved contract plan');
$definitions=[
 'contract_years'=>"SMALLINT UNSIGNED NULL COMMENT 'Maximum years including conditional extensions'",
 'contract_term'=>"VARCHAR(64) NULL COMMENT 'Original term preserving extension options'",
 'contract_total_amount'=>"BIGINT UNSIGNED NULL COMMENT 'Announced maximum total in contract currency, options included when announced'",
 'contract_registered_amount'=>"BIGINT UNSIGNED NULL COMMENT 'Separate KBO registered amount when available'",
 'contract_currency'=>'CHAR(3) NULL',
 'contract_details'=>'TEXT NULL',
 'contract_source_url'=>'TEXT NULL',
 'contract_verified_at'=>'DATETIME NULL',
];
$columns=array_column($db->query('SHOW COLUMNS FROM kbo_player_movements')->fetchAll(),null,'Field');
$add=[];foreach($definitions as $field=>$definition)if(!isset($columns[$field]))$add[]="ADD COLUMN `$field` $definition";
$beforeSchema=$db->query('SHOW CREATE TABLE kbo_player_movements')->fetch();
$before=$db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll();
$snapshotPath=$dir.'/contracts-before.json.gz';
if(!file_exists($snapshotPath))file_put_contents($snapshotPath,gzencode(json_encode(['schema'=>$beforeSchema,'rows'=>$before],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),9));
if($add)$db->exec('ALTER TABLE kbo_player_movements '.implode(', ',$add));
$before=$db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll();
$byId=array_column($before,null,'id');$expected=$byId;
$byKey=array_column($before,null,'source_key');
$fields=array_keys($definitions);$dataFields=array_values(array_diff($fields,['contract_verified_at']));
$parents=array_fill_keys($db->query('SELECT player_id FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN),true);
$stamp=gmdate('Y-m-d H:i:s');$updates=[];$inserts=[];
foreach($plan['updates'] as $item){
 $row=$byId[$item['id']]??throw new RuntimeException('Missing contract movement');
 foreach($item['identity'] as $key=>$value)if($row[$key]!==$value)throw new RuntimeException('Contract identity changed: '.$item['id'].' '.$key);
 $diff=false;foreach($dataFields as $f)if($row[$f]!==$item[$f])$diff=true;
 if(!$diff)continue;
 foreach($dataFields as $f){if($row[$f]!==null&&$row[$f]!==$item[$f])throw new RuntimeException('Existing enrichment conflict: '.$item['id'].' '.$f);$expected[$item['id']][$f]=$item[$f];}
 $expected[$item['id']]['contract_verified_at']=$stamp;$updates[]=$item;
}
foreach($plan['inserts'] as $item){
 if(!isset($parents[$item['player_id']]))throw new RuntimeException('Missing new contract player');
 if(isset($byKey[$item['source_key']])){
  foreach($item as $f=>$v)if($byKey[$item['source_key']][$f]!==$v)throw new RuntimeException('New contract changed: '.$f);
  continue;
 }
 foreach($before as $r)if($r['player_id']===$item['player_id']&&$r['team']===$item['team']&&str_contains($r['event_type'],'계약')&&abs(strtotime($r['event_date'])-strtotime($item['event_date']))<32*86400)throw new RuntimeException('Potential duplicate contract');
 $inserts[]=$item;
}
$db->beginTransaction();
try{
 foreach(array_chunk($updates,80) as $batch){
  $assignments=[];$ids=array_column($batch,'id');
  foreach($dataFields as $f){$cases=[];foreach($batch as $u)$cases[]='WHEN '.(int)$u['id'].' THEN '.($u[$f]===null?'NULL':$db->quote((string)$u[$f]));$assignments[]="`$f`=CASE id ".implode(' ',$cases).' END';}
  $assignments[]='contract_verified_at='.$db->quote($stamp);
  $n=$db->exec('UPDATE kbo_player_movements SET '.implode(',',$assignments).' WHERE id IN ('.implode(',',$ids).') AND contract_verified_at IS NULL');
  if($n!==count($batch))throw new RuntimeException('Concurrent enrichment change');
 }
 foreach($inserts as $item){
  $item['contract_verified_at']=$stamp;$keys=array_keys($item);
  $q=$db->prepare('INSERT INTO kbo_player_movements (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute(array_values($item));
  $id=(int)$db->lastInsertId();$q=$db->prepare('SELECT * FROM kbo_player_movements WHERE id=?');$q->execute([$id]);$row=$q->fetch();
  foreach($item as $f=>$v)if($row[$f]!==$v)throw new RuntimeException('Inserted contract mismatch: '.$f);
  $expected[$id]=$row;
 }
 $after=array_column($db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll(),null,'id');
 if($after!==$expected)throw new RuntimeException('Unexpected movement column change');
 $missing=(int)$db->query("SELECT COUNT(*) FROM kbo_player_movements WHERE event_type IN ('FA 계약','해외 복귀 FA 계약','비FA 다년계약','자유계약') AND (player_id IS NULL OR contract_years IS NULL OR contract_total_amount IS NULL)")->fetchColumn();
 if($missing)throw new RuntimeException('Contract enrichment missing');
 $db->commit();
 $result=['existing_contracts'=>count($plan['updates']),'existing_updated'=>count($updates),'new_contracts'=>count($plan['inserts']),'inserted'=>count($inserts),'total_movements'=>count($after),'contract_amount_currency'=>'KRW','original_columns_preserved'=>true,'incomplete_contracts'=>$missing];
 file_put_contents($dir.'/contract-verification.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
 echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
