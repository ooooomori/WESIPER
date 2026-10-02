<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$mode=$argv[1]??'--check';
if (!in_array($mode,['--check','--apply','--verify'],true)) throw new InvalidArgumentException('Invalid mode');
$root=dirname(__DIR__);$input=$root.'/supplement-allstars';
$load=static fn($path)=>json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$foreign=$load($root.'/data/player-career/foreign-players.json');
$before=$load($root.'/data/player-career/careers.json');
$final=$load($input.'/careers.json');$people=$load($input.'/allstar-user-2024-2026.json');
$statePath=__DIR__.'/player-career-state.json';$state=$load($statePath);
$hash=static fn($careers)=>hash('sha256',json_encode([$foreign,$careers],JSON_THROW_ON_ERROR));
if ($state['phase']!=='complete' || $state['payload_hash']!==$hash($before)) throw new RuntimeException('Original migration state/input mismatch');
if (count($final)!==2904 || count($people)!==160) throw new RuntimeException('Unexpected supplement size');
$keys=[];
foreach ($people as $p) {
    if (!is_int($p['player_id']) || !in_array($p['year'],[2024,2025,2026],true) || !in_array($p['team'],['삼성','SSG','두산','롯데','KT','한화','KIA','키움','LG','NC'],true)) throw new RuntimeException('Invalid roster row');
    $key=$p['player_id'].':'.$p['year'];if (isset($keys[$key])) throw new RuntimeException('Duplicate input occurrence');$keys[$key]=$p;
}
if (array_slice($final,0,2744)!==array_slice($before,0,2744)) throw new RuntimeException('Original career rows changed');
foreach (array_slice($final,2744) as $row) {
    $key=$row['player_id'].':'.$row['year'];$p=$keys[$key]??null;
    if (!$p || $row!==['player_id'=>$p['player_id'],'category'=>'award','type'=>'올스타','team'=>$p['team'],'year'=>$p['year'],'month'=>null,'pos'=>null,'note'=>null]) throw new RuntimeException('Supplement contains another change');
}
$config=require $root.'/backend/config/database.php';
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$db->exec('SET SESSION lock_wait_timeout=10');
function asRows(PDO $db):array {
    $rows=$db->query('SELECT PK,player_id,category,type,team,year,month,pos,note FROM kbo_player_career ORDER BY PK')->fetchAll();
    foreach ($rows as $index=>&$row) {
        if ((int)$row['PK']!==$index+1) throw new RuntimeException('PK order changed');unset($row['PK']);$row['player_id']=(int)$row['player_id'];
        foreach (['year','month'] as $column) if ($row[$column]!==null) $row[$column]=(int)$row[$column];
    }unset($row);return $rows;
}
function asSave(string $path,array $value,int $permissions):void {
    if (file_put_contents($path.'.tmp',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL)===false) throw new RuntimeException('Write failed');
    chmod($path.'.tmp',$permissions);if (!rename($path.'.tmp',$path)) throw new RuntimeException('Rename failed');
}
if ((int)$db->query("SELECT GET_LOCK('wesiper-player-data-migration',0)")->fetchColumn()!==1) throw new RuntimeException('Another migration is running');
try {
    $lookup=$db->prepare('SELECT name FROM kbo_player_data WHERE player_id=?');
    foreach ($people as $p) {$lookup->execute([$p['player_id']]);if ($lookup->fetchColumn()!==$p['name']) throw new RuntimeException('Player name/ID changed: '.$p['player_id']);}
    $actual=asRows($db);
    if ($actual!==$final && $actual!==array_slice($final,0,2744)) throw new RuntimeException('Existing career data differs');
    if ($mode==='--verify') {
        if ($actual!==$final || $before!==$final || $state['payload_hash']!==$hash($final)) throw new RuntimeException('Supplement not fully applied');
        if ((int)$db->query('SELECT COUNT(*) FROM kbo_player_data')->fetchColumn()!==6002 || (int)$db->query('SELECT COUNT(*) FROM kbo_player_titleholder')->fetchColumn()!==660) throw new RuntimeException('Player/titleholder count changed');
        if ($db->query('SHOW CREATE EVENT KBODLE_GEN')->fetch()['Create Event']!==$state['event']) throw new RuntimeException('Event changed');
        echo 'Verified: 160 requested occurrences, 2904 careers, existing 2744 PKs unchanged.'.PHP_EOL;exit;
    }
    if ($mode==='--check') {echo 'Preflight passed: 160 additions; all player IDs/names and original records match.'.PHP_EOL;exit;}
    $backupDir=$root.'/backups';if (!is_dir($backupDir)) mkdir($backupDir,0700,true);
    $checkpoint=$input.'/allstar-state.json';
    if (is_file($checkpoint)) $change=$load($checkpoint);
    else {
        $change=['phase'=>'prepared','before_hash'=>$hash($before),'after_hash'=>$hash($final),'backup'=>'kbo_player_career_backup_allstar_'.date('Ymd_His')];
        if (count($before)!==2744 || $actual!==$before) throw new RuntimeException('Unexpected first application');
        if (!copy($statePath,$backupDir.'/player-career-state.before-allstars.json')) throw new RuntimeException('State backup failed');
        if (!copy($root.'/data/player-career/careers.json',$backupDir.'/careers.before-allstars.json')) throw new RuntimeException('Input backup failed');
        $quoted='`'.$change['backup'].'`';$db->exec('CREATE TABLE '.$quoted.' LIKE kbo_player_career');$db->exec('INSERT INTO '.$quoted.' SELECT * FROM kbo_player_career');
        asSave($checkpoint,$change,0600);
    }
    if ($change['after_hash']!==$hash($final)) throw new RuntimeException('Supplement changed after preparation');
    if ($actual!==$final) {
        $db->beginTransaction();
        try {
            $insert=$db->prepare('INSERT INTO kbo_player_career(player_id,category,type,team,year,month,pos,note) VALUES(?,?,?,?,?,?,?,?)');
            foreach (array_slice($final,2744) as $row) $insert->execute(array_values($row));
            if (asRows($db)!==$final) throw new RuntimeException('Inserted rows differ');
            $db->commit();
        } catch (Throwable $e) {if ($db->inTransaction()) $db->rollBack();throw $e;}
    }
    asSave($root.'/data/player-career/careers.json',$final,0644);
    asSave($root.'/data/player-career/sources.json',$load($input.'/sources.json'),0644);
    asSave($root.'/data/player-career/allstar-user-2024-2026.json',$people,0644);
    $state['payload_hash']=$hash($final);
    $state['amendments']['user_allstars_2024_2026']=['backup'=>$change['backup'],'added'=>160,'year_counts'=>[2024=>52,2025=>59,2026=>49]];
    asSave($statePath,$state,0600);$change['phase']='complete';asSave($checkpoint,$change,0600);
    echo json_encode(['added'=>160,'career_total'=>count($final),'backup'=>$change['backup']],JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {$db->query("SELECT RELEASE_LOCK('wesiper-player-data-migration')");}
