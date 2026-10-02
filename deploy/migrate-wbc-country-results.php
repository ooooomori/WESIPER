<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__).'/backend';require dirname(__DIR__).'/backend/api/kbocandle/common.php';
$apply=in_array('--apply',$argv,true);
$plan=json_decode(file_get_contents(dirname(__DIR__).'/data/player-career/wbc-country-results.json'),true,512,JSON_THROW_ON_ERROR);
$byPk=[];foreach($plan as $item)foreach($item['rows'] as $pk){if(isset($byPk[$pk]))throw new RuntimeException('Duplicate result mapping');$byPk[$pk]=$item;}
$before=$pdo->query('SELECT * FROM kbo_player_career ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC);
$updates=0;$targets=0;
foreach($before as $row){
    if($row['category']!=='national'||$row['type']!=='WBC'||$row['country']==='한국')continue;
    $item=$byPk[$row['PK']]??null;
    if(!$item||($row['country']!==null&&$row['country']!==$item['country'])||(int)$row['year']!==$item['year'])throw new RuntimeException('Unknown WBC country/result identity: '.$row['PK']);
    $targets++;$updates+=(int)($row['note']!==$item['note']);
}
if($targets!==count($byPk))throw new RuntimeException('WBC result mapping count mismatch');
if(!$apply){echo json_encode(['mode'=>'preview','checked'=>$targets,'changed'=>$updates],JSON_UNESCAPED_UNICODE).PHP_EOL;exit;}
$backup=dirname(__DIR__).'/.player-career/wbc-results-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
if(file_put_contents($backup,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Result backup failed');
$pdo->exec('SET SESSION lock_wait_timeout=15');$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$q=$pdo->prepare("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='kbo_player_career' AND CONSTRAINT_NAME='chk_career_note'");$q->execute();$clause=$q->fetchColumn();
// note is free text; do not recreate the retired allow-list constraint.
if(is_string($clause)&&$clause!=='')$pdo->exec('ALTER TABLE kbo_player_career DROP CONSTRAINT chk_career_note');
$pdo->beginTransaction();
try {
    $locked=$pdo->query('SELECT * FROM kbo_player_career ORDER BY PK FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if($locked!==$before)throw new RuntimeException('Career records changed during result migration');
    $q=$pdo->prepare("UPDATE kbo_player_career SET note=?,country=? WHERE PK=? AND category='national' AND type='WBC' AND year=? AND (country=? OR country IS NULL)");
    foreach($byPk as $pk=>$item)$q->execute([$item['note'],$item['country'],$pk,$item['year'],$item['country']]);
    $after=$pdo->query('SELECT * FROM kbo_player_career ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC);
    foreach($before as $i=>$expected){if(isset($byPk[$expected['PK']])){$expected['note']=$byPk[$expected['PK']]['note'];$expected['country']=$byPk[$expected['PK']]['country'];}if(($after[$i]??null)!==$expected)throw new RuntimeException('WBC result verification failed');}
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo json_encode(['mode'=>'applied','checked'=>$targets,'changed'=>$updates,'otherRecordsUnchanged'=>true,'backup'=>basename($backup)],JSON_UNESCAPED_UNICODE).PHP_EOL;
