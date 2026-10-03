<?php
declare(strict_types=1);
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/.contract-enrichment';
$plan=json_decode(file_get_contents($dir.'/contract-text-cleanup-plan.json'),true,512,JSON_THROW_ON_ERROR);
$db->beginTransaction();
try{
 $before=array_column($db->query('SELECT * FROM kbo_player_movements ORDER BY id FOR UPDATE')->fetchAll(),null,'id');$expected=$before;$changed=0;
 $snapshot=$dir.'/contract-text-before.json.gz';
 if(!file_exists($snapshot))file_put_contents($snapshot,gzencode(json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),9));
 $q=$db->prepare('UPDATE kbo_player_movements SET contract_details=?, note=?, contract_term=? WHERE id=? AND source_key=?');
 foreach($plan as $r){
  $old=$before[$r['id']]??throw new RuntimeException('Missing movement');
  if($old['source_key']!==$r['source_key'])throw new RuntimeException('Identity changed');
  if($old['contract_details']===$r['after_details']&&$old['note']===$r['after_note']&&$old['contract_term']===$r['after_term'])continue;
  if($old['contract_details']!==$r['before_details']||$old['note']!==$r['before_note']||$old['contract_term']!==$r['before_term'])throw new RuntimeException('Concurrent text change');
  $q->execute([$r['after_details'],$r['after_note'],$r['after_term'],$r['id'],$r['source_key']]);$changed++;
  $expected[$r['id']]['contract_details']=$r['after_details'];$expected[$r['id']]['note']=$r['after_note'];$expected[$r['id']]['contract_term']=$r['after_term'];
 }
 $after=array_column($db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll(),null,'id');
 if($after!==$expected)throw new RuntimeException('Unexpected non-text change');
 foreach($after as $r)if($r['contract_details']&&preg_match('/KBO|연감|미기재|원문|NULL|금액 기준|표기 기준|발표액 기준/u',$r['contract_details']))throw new RuntimeException('Technical text remains');
 foreach($after as $r)if($r['contract_term']&&preg_match('/년\s*\+/u',$r['contract_term']))throw new RuntimeException('Inconsistent contract term remains');
 $db->commit();echo json_encode(['updated'=>$changed,'non_text_columns_preserved'=>true,'technical_text_remaining'=>0],JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
