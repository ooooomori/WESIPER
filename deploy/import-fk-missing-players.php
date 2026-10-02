<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$mode=$argv[1]??'--check';
if (!in_array($mode,['--check','--apply','--verify'],true)) throw new InvalidArgumentException('Invalid mode');
$config=require ($argv[2]??__DIR__.'/../backend/config/database.php');
$rows=json_decode(file_get_contents($argv[3]??__DIR__.'/../data/player-fk-missing-20260930.json'),true,512,JSON_THROW_ON_ERROR);
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$fields=['player_id','name','pos','team','birth','body','backNo','throw','bat','draft','is_kbodle','is_foreign'];
$missing=[];
$lookup=$db->prepare('SELECT player_id,name,birth FROM kbo_player_data WHERE player_id=?');
$batter=$db->prepare('SELECT DISTINCT player_name FROM kbo_season_records WHERE player_id=? AND player_name IS NOT NULL');
$pitcher=$db->prepare('SELECT COUNT(*) FROM kbo_season_pitch_records WHERE player_id=?');
foreach($rows as $row) {
    if(!is_int($row['player_id'])||$row['player_id']<=0||!preg_match('/^[가-힣]+$/u',$row['name'])||!str_ends_with($row['source'],'playerId='.$row['player_id'])) throw new RuntimeException('Invalid player fixture');
    $lookup->execute([$row['player_id']]);$existing=$lookup->fetch();
    if($existing) {
        if($existing['name']!==$row['name']||$existing['birth']!==$row['birth'])throw new RuntimeException('Conflicting existing player');
        continue;
    }
    if($mode==='--verify')throw new RuntimeException('Missing imported player');
    $batter->execute([$row['player_id']]);$names=$batter->fetchAll(PDO::FETCH_COLUMN);
    $pitcher->execute([$row['player_id']]);$pitchCount=(int)$pitcher->fetchColumn();
    if(!$names&&!$pitchCount)throw new RuntimeException('Player has no orphan records');
    if(array_filter($names,static fn($name)=>trim($name)!==$row['name']))throw new RuntimeException('Batter identity mismatch');
    $missing[]=$row;
}
if($mode==='--apply'&&$missing) {
    // Add only confirmed missing parents. Existing players and game records are never updated.
    $db->beginTransaction();
    try {
        $sql='INSERT INTO kbo_player_data (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')';
        $insert=$db->prepare($sql);
        foreach($missing as $row)$insert->execute(array_map(static fn($field)=>$row[$field],$fields));
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
echo json_encode(['mode'=>$mode,'missing'=>count($missing),'players'=>array_map(static fn($row)=>['player_id'=>$row['player_id'],'name'=>$row['name']],$missing)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
