<?php
declare(strict_types=1);
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/.contract-enrichment';
$plan=json_decode(file_get_contents($dir.'/identity-final.json'),true,512,JSON_THROW_ON_ERROR);
$before=$db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll();
$byId=array_column($before,null,'id');$expected=$byId;
$parentIds=array_fill_keys($db->query('SELECT player_id FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN),true);
$changed=0;
foreach($plan['updates'] as $u){
 $row=$byId[$u['id']]??throw new RuntimeException('Missing movement');
 if($row['player_id']!==null&&(int)$row['player_id']===(int)$u['player_id'])continue;
 foreach($u['before_identity'] as $k=>$v)if($row[$k]!==$v)throw new RuntimeException('Identity changed: '.$u['id'].' '.$k);
 if(!isset($parentIds[$u['player_id']]))throw new RuntimeException('Missing player');
 $expected[$u['id']]['player_id']=$u['player_id'];$changed++;
}
if($changed){
 $path=$dir.'/identity-before-'.gmdate('Ymd_His').'.json.gz';
 file_put_contents($path,gzencode(json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),9));
}
$db->beginTransaction();
try{
 foreach(array_chunk($plan['updates'],200) as $batch){
  $cases=[];$ids=[];foreach($batch as $u){if($byId[$u['id']]['player_id']!==null)continue;$ids[]=(int)$u['id'];$cases[]='WHEN '.(int)$u['id'].' THEN '.(int)$u['player_id'];}
  if(!$ids)continue;
  $n=$db->exec('UPDATE kbo_player_movements SET player_id=CASE id '.implode(' ',$cases).' END WHERE player_id IS NULL AND id IN ('.implode(',',$ids).')');
  if($n!==count($ids))throw new RuntimeException('Concurrent identity change');
 }
 $after=array_column($db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll(),null,'id');
 if($after!==$expected)throw new RuntimeException('Unexpected movement column change');
 $remaining=$db->query('SELECT id,player_text FROM kbo_player_movements WHERE player_id IS NULL')->fetchAll();
 foreach($remaining as $r)if($r['player_text']!=='신인(지명권)')throw new RuntimeException('Player identity still missing');
 $db->commit();
 $result=['updated'=>$changed,'verified'=>count($plan['updates']),'remaining_non_player_draft_picks'=>count($remaining),'non_target_columns_unchanged'=>true];
 file_put_contents($dir.'/identity-verification.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
 echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
