<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$c=require getenv('WESIPER_DB_CONFIG');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dir=dirname(__DIR__).'/output';
$plan=json_decode(file_get_contents($dir.'/trade-import-plan.json'),true,512,JSON_THROW_ON_ERROR);
$parents=array_fill_keys($db->query('SELECT player_id FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN),true);
$keys=[];$groups=[];
foreach($plan['events'] as $e){
 if(isset($keys[$e['source_key']]))throw new RuntimeException('Duplicate source key');
 if(hash_file('sha256',$dir.'/namu-trades-'.$e['decade'].'.html')!==$e['source_sha256'])throw new RuntimeException('Source hash mismatch');
 if((int)substr($e['trade_date'],0,4)!==$e['year'])throw new RuntimeException('Date mismatch');
 $keys[$e['source_key']]=true;
}
foreach($plan['assets'] as $a){
 if(!isset($keys[$a['source_key']]) || $a['from_team']===$a['to_team'])throw new RuntimeException('Invalid trade association');
 foreach(['player_id','linked_draftee_id'] as $f)if($a[$f]!==null && !isset($parents[$a[$f]]))throw new RuntimeException('Unknown player');
 if($a['asset_type']==='player' && $a['player_id']===null && $a['player_name']!=='김일중')throw new RuntimeException('Unexpected unresolved player');
 $groups[$a['source_key']][]=$a;
}
if(count($plan['events'])!==335 || count($plan['assets'])!==937 || count($groups)!==335)throw new RuntimeException('Incomplete source plan');
if(!in_array('--apply',$argv,true)){echo json_encode(['dry_run'=>true,'trades'=>335,'assets'=>937,'unresolved'=>$plan['unresolved']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
$movementBefore=$db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll();
$existing=$db->query("SHOW TABLES LIKE 'kbo_trade%'")->fetchAll(PDO::FETCH_COLUMN);
$backup=[];foreach(['kbo_trades','kbo_trade_assets'] as $t)if(in_array($t,$existing,true))$backup[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();
file_put_contents($dir.'/trades-before-'.gmdate('Ymd-His').'.json',json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
foreach(explode(';',file_get_contents(__DIR__.'/kbo-trades-schema.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
$db->beginTransaction();
try{
 $find=$db->prepare('SELECT * FROM kbo_trades WHERE source_key=?');
 $new=$db->prepare('INSERT INTO kbo_trades (source_key,trade_date,year,title,summary,source_url,source_section,source_date_text,source_sha256,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?)');
 $assetFields=['trade_id','item_order','asset_type','from_team','to_team','description','player_name','player_id','identity_status','identity_basis','cash_amount_krw','draft_year','draft_phase','draft_round','linked_draftee_id'];
 $insert=$db->prepare('INSERT INTO kbo_trade_assets (`'.implode('`,`',$assetFields).'`) VALUES ('.implode(',',array_fill(0,count($assetFields),'?')).')');
 $inserted=0;$skipped=0;$addedAssets=0;$tradeIds=[];
 foreach($plan['events'] as $e){
  $key=hash('sha256',$e['source_key']);$find->execute([$key]);$old=$find->fetch();
  $values=[$key,$e['trade_date'],$e['year'],preg_replace('/^[\d.]+\s*/u','',$e['heading']),$e['summary'],$e['source_url'],$e['heading'],$e['date_text'],$e['source_sha256'],json_encode($e,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
  if($old){
   $fields=['source_key','trade_date','year','title','summary','source_url','source_section','source_date_text','source_sha256','raw_json'];foreach($fields as $i=>$f)if((string)$old[$f] !== (string)$values[$i])throw new RuntimeException('Existing trade differs: '.$f);$id=(int)$old['id'];$skipped++;
  }else{$new->execute($values);$id=(int)$db->lastInsertId();$inserted++;}
  $tradeIds[$e['source_key']]=$id;
  $q=$db->prepare('SELECT * FROM kbo_trade_assets WHERE trade_id=? ORDER BY item_order');$q->execute([$id]);$oldAssets=$q->fetchAll();
  if($oldAssets && count($oldAssets)!==count($groups[$e['source_key']]))throw new RuntimeException('Existing asset count differs');
  foreach($groups[$e['source_key']] as $i=>$a){
   $a['trade_id']=$id;$a['item_order']=$i+1;$a['identity_status']=$a['asset_type']==='player'?($a['player_id']===null?'unresolved':'linked'):'not_applicable';
   $row=[];foreach($assetFields as $f)$row[$f]=$a[$f];
   if($oldAssets){foreach($row as $f=>$v)if($v===null ? $oldAssets[$i][$f]!==null : (string)$v!==(string)$oldAssets[$i][$f])throw new RuntimeException('Existing asset differs: '.$f);}else{$insert->execute(array_values($row));$addedAssets++;}
  }
 }
 if($db->query('SELECT * FROM kbo_player_movements ORDER BY id')->fetchAll()!==$movementBefore)throw new RuntimeException('Movements changed concurrently');
 $ids=implode(',',array_values($tradeIds));
 $actual=$db->query('SELECT a.*,t.trade_date,t.title FROM kbo_trade_assets a JOIN kbo_trades t ON t.id=a.trade_id WHERE t.id IN ('.$ids.') ORDER BY t.trade_date,t.id,a.item_order')->fetchAll();
 if(count($actual)!==937)throw new RuntimeException('Asset verification failed');
 $linked=count(array_filter($actual,fn($r)=>$r['asset_type']==='player' && $r['player_id']!==null));
 if($linked!==781)throw new RuntimeException('Player verification failed');
 $db->commit();
 file_put_contents($dir.'/trade-import-verified.json',json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
 $csv=fopen($dir.'/kbo-trade-players.csv','wb');fwrite($csv,"\xEF\xBB\xBF");$fields=['trade_id','trade_date','title','from_team','to_team','player_name','player_id','identity_status'];fputcsv($csv,$fields,',','"','');foreach($actual as $r)if($r['asset_type']==='player')fputcsv($csv,array_map(fn($f)=>$r[$f],$fields),',','"','');fclose($csv);
 $report=['trades'=>335,'inserted'=>$inserted,'already_present'=>$skipped,'assets'=>937,'inserted_assets'=>$addedAssets,'player_transfers'=>782,'linked_player_transfers'=>$linked,'unique_players'=>count(array_unique(array_column(array_filter($actual,fn($r)=>$r['player_id']!==null),'player_id'))),'unresolved'=>$plan['unresolved'],'movements_unchanged'=>true];
 file_put_contents($dir.'/trade-import-verification.json',json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
