<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__).'/backend';
require dirname(__DIR__).'/backend/api/kbocandle/common.php';
$apply=in_array('--apply',$argv,true);
$plan=json_decode(file_get_contents(dirname(__DIR__).'/data/player-career/national-countries.json'),true,512,JSON_THROW_ON_ERROR);
$overrides=[];
foreach($plan as $row){
    if(isset($overrides[$row['PK']])||!is_string($row['country'])||trim($row['country'])==='')throw new RuntimeException('Invalid country plan');
    $overrides[$row['PK']]=$row;
}
$validate=static function(array $rows)use($overrides):void {
    $byPk=array_column($rows,null,'PK');
    foreach($overrides as $pk=>$item){
        $r=$byPk[$pk]??null;
        if(!$r||$r['category']!=='national'||$r['type']!==$item['type']||(int)$r['player_id']!==$item['player_id']||(int)$r['year']!==$item['year'])throw new RuntimeException('Country plan identity mismatch: '.$pk);
    }
};
$rows=$pdo->query('SELECT * FROM kbo_player_career ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC);
$validate($rows);
$foreign=$pdo->query("SELECT c.PK FROM kbo_player_career c JOIN kbo_player_data p ON p.player_id=c.player_id WHERE c.category='national' AND p.is_foreign=1")->fetchAll(PDO::FETCH_COLUMN);
foreach($foreign as $pk)if(!isset($overrides[$pk]))throw new RuntimeException('Unreviewed foreign national career: '.$pk);
$counts=[];$nonNational=0;
foreach($rows as $r){if($r['category']!=='national'){$nonNational++;continue;}$country=$overrides[$r['PK']]['country']??'한국';$counts[$country]=($counts[$country]??0)+1;}
ksort($counts);
if(!$apply){echo json_encode(['mode'=>'preview','countryCounts'=>$counts,'nonNationalNull'=>$nonNational,'reviewedForeign'=>count($foreign),'reviewedOverrides'=>count($overrides)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit;}
$backupDir=dirname(__DIR__).'/.player-career';
if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true))throw new RuntimeException('Backup directory unavailable');
$backup=$backupDir.'/country-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
if(file_put_contents($backup,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Country backup failed');
$pdo->exec('SET SESSION lock_wait_timeout=15');
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$columns=$pdo->query('SHOW COLUMNS FROM kbo_player_career')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('country',$columns,true))$pdo->exec("ALTER TABLE kbo_player_career ADD COLUMN country VARCHAR(30) NULL DEFAULT NULL COMMENT '국가대표 출전 국가' AFTER team");
$stripCountry=static function(array $rows):array {foreach($rows as &$r)unset($r['country']);unset($r);return $rows;};
$pdo->beginTransaction();
try {
    $locked=$pdo->query('SELECT * FROM kbo_player_career ORDER BY PK FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if($stripCountry($locked)!==$stripCountry($rows))throw new RuntimeException('Career records changed during migration; retry');
    $validate($locked);
    $pdo->exec("UPDATE kbo_player_career SET country=CASE WHEN category='national' THEN '한국' ELSE NULL END");
    $q=$pdo->prepare("UPDATE kbo_player_career SET country=? WHERE PK=? AND player_id=? AND category='national' AND type=? AND year=?");
    foreach($overrides as $pk=>$r)$q->execute([$r['country'],$pk,$r['player_id'],$r['type'],$r['year']]);
    $after=$pdo->query('SELECT * FROM kbo_player_career ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC);
    if($stripCountry($after)!==$stripCountry($rows))throw new RuntimeException('Other career fields changed');
    foreach($after as $r){$expected=$r['category']==='national'?($overrides[$r['PK']]['country']??'한국'):null;if($r['country']!==$expected)throw new RuntimeException('Country verification failed: '.$r['PK']);}
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo json_encode(['mode'=>'applied','countryCounts'=>$counts,'nonNationalNull'=>$nonNational,'reviewedForeign'=>count($foreign),'otherFieldsUnchanged'=>true,'backup'=>basename($backup)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
