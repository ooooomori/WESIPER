<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require __DIR__ . '/../backend/lib/player-school.php';
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port'] ?? 3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$mode=$argv[1] ?? '--check';
if (!in_array($mode,['--check','--apply','--verify'],true)) throw new InvalidArgumentException('Use --check, --apply or --verify.');
$root=__DIR__ . '/../data/player-history/';
$load=static fn(string $file): array => json_decode(file_get_contents($root.$file),true,512,JSON_THROW_ON_ERROR);
$titles=$load('titleholders.json');
$roster=$load('ulsan-roster.json');
$overseas=$load('overseas-players.json');
$payloadHash=hash('sha256',json_encode([$titles,$roster,$overseas],JSON_THROW_ON_ERROR));
$statePath=__DIR__.'/player-history-state.json';
function hsSave(string $path,array $data):void {
    if (file_put_contents($path.'.tmp',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false || !rename($path.'.tmp',$path)) throw new RuntimeException('Cannot save migration state.');
}
function hsQuote(string $identifier):string { return '`'.str_replace('`','``',$identifier).'`'; }
function hsExists(PDO $db,string $table):bool {
    $query=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$query->execute([$table]);return (bool)$query->fetchColumn();
}
function hsEvent(PDO $db):string { return $db->query("SHOW CREATE EVENT KBODLE_GEN")->fetch()['Create Event']; }
function hsSummary(PDO $db):void {
    echo json_encode(['players'=>$db->query('SELECT is_kbodle,COUNT(*) total FROM kbo_player_data GROUP BY is_kbodle ORDER BY is_kbodle')->fetchAll(),'titles'=>$db->query('SELECT type,COUNT(*) total,MIN(year) first_year,MAX(year) last_year FROM kbo_player_titleholder GROUP BY type ORDER BY type')->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}
function hsValidateInput(array $titles,array $roster,array $overseas):void {
    $types=['다승','평균자책점','탈삼진','세이브','홀드','승률','세이브포인트','타율','안타','홈런','타점','득점','도루','출루율','장타율','승리타점'];
    $keys=[];$years=[];
    foreach ($titles as $row) {
        if (!in_array($row['type'],$types,true) || !is_int($row['year']) || $row['year']<1982 || $row['year']>2025 || !is_int($row['player_id']) || $row['player_id']<=0 || !preg_match('/^\d{1,9}\.\d{3}$/D',$row['record'])) throw new RuntimeException('Invalid title row.');
        $key=$row['player_id'].'/'.$row['type'].'/'.$row['year'];
        if (isset($keys[$key])) throw new RuntimeException('Duplicate title key.');
        $keys[$key]=true;$years[$row['type']][$row['year']]=true;
    }
    foreach ($types as $type) {
        $first=$type==='홀드'?2000:1982;
        $last=$type==='세이브포인트'?2003:($type==='승리타점'?1989:2025);
        $actual=array_keys($years[$type]??[]);sort($actual);
        if ($actual!==range($first,$last)) throw new RuntimeException("Missing/extra years for $type.");
    }
    if (count($titles)!==660 || count($roster)!==43 || count($overseas)!==4) throw new RuntimeException('Unexpected import counts.');
    $ids=[];
    foreach (array_merge($roster,$overseas) as $row) {
        if (isset($ids[$row['player_id']])) throw new RuntimeException('Player requested twice.');
        $ids[$row['player_id']]=true;
        if (!in_array($row['is_kbodle'],[3,4],true) || $row['team']!==($row['is_kbodle']===3?'키움':'울산')) throw new RuntimeException('Invalid team/status.');
    }
}
function hsVerify(PDO $db,array $titles,array $roster,array $overseas,array $state):void {
    $lookup=$db->prepare('SELECT * FROM kbo_player_data WHERE player_id=?');
    foreach (array_merge($roster,$overseas) as $row) {
        $lookup->execute([$row['player_id']]);$player=$lookup->fetch();
        if (!$player || $player['name']!==$row['name'] || $player['team']!==$row['team'] || (int)$player['is_kbodle']!==$row['is_kbodle']) throw new RuntimeException('Player status/name mismatch: '.$row['player_id']);
        if (($row['new']??false) && ($player['birth']!==$row['birth'] || $player['pos']!==$row['pos'] || $player['school']!==playerSchoolOnly($row['school_source']))) throw new RuntimeException('New player profile mismatch.');
    }
    $expected=[];
    foreach ($titles as $row) $expected[$row['player_id'].'/'.$row['type'].'/'.$row['year']]=$row['record'];
    $actual=$db->query('SELECT PK,player_id,type,year,record FROM kbo_player_titleholder ORDER BY PK')->fetchAll();
    if (count($actual)!==count($expected)) throw new RuntimeException('Title count mismatch.');
    foreach ($actual as $index=>$row) {
        $key=$row['player_id'].'/'.$row['type'].'/'.$row['year'];
        if ((int)$row['PK']!==$index+1 || ($expected[$key]??null)!==$row['record']) throw new RuntimeException('Title identity/value mismatch.');
        unset($expected[$key]);
    }
    if ($expected) throw new RuntimeException('Missing titles.');
    $before=(int)$db->query('SELECT COUNT(*) FROM '.hsQuote($state['backup']))->fetchColumn();
    $after=(int)$db->query('SELECT COUNT(*) FROM kbo_player_data')->fetchColumn();
    $added=count(array_filter($roster,static fn($row)=>$row['new']));
    if ($after!==$before+$added) throw new RuntimeException('Unexpected player count change.');
    // No player outside the explicit list is changed, apart from mutable images.
    $ids=implode(',',array_map(static fn($row)=>(string)(int)$row['player_id'],array_merge($roster,$overseas)));
    $fields=$db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN);
    $comparisons=[];
    foreach ($fields as $field) if ($field!=='img') $comparisons[]='NOT(BINARY p.'.hsQuote($field).' <=> BINARY b.'.hsQuote($field).')';
    $sql='SELECT COUNT(*) FROM kbo_player_data p JOIN '.hsQuote($state['backup']).' b ON b.player_id=p.player_id WHERE p.player_id NOT IN ('.$ids.') AND ('.implode(' OR ',$comparisons).')';
    if ((int)$db->query($sql)->fetchColumn()!==0) throw new RuntimeException('Unrequested player changed.');
    if (hsEvent($db)!==$state['event']) throw new RuntimeException('Event changed during import.');
}
hsValidateInput($titles,$roster,$overseas);
if ((int)$db->query("SELECT GET_LOCK('wesiper-player-data-migration',0)")->fetchColumn()!==1) throw new RuntimeException('Another player migration is running.');
try {
    $state=is_file($statePath)?json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR):null;
    if ($state && $state['payload_hash']!==$payloadHash) throw new RuntimeException('Import payload changed; do not overwrite state.');
    if ($mode==='--verify' || ($state['phase']??'')==='complete') {
        if (!$state) throw new RuntimeException('Import state missing.');
        hsVerify($db,$titles,$roster,$overseas,$state);hsSummary($db);exit;
    }
    $lookup=$db->prepare('SELECT * FROM kbo_player_data WHERE player_id=?');
    foreach ($titles as $row) {
        $lookup->execute([$row['player_id']]);if (!$lookup->fetch()) throw new RuntimeException('Title winner missing: '.$row['player_id']);
    }
    foreach (array_merge($roster,$overseas) as $row) {
        $lookup->execute([$row['player_id']]);$player=$lookup->fetch();
        if ($row['new']??false) { if ($player) throw new RuntimeException('New ID already exists.');continue; }
        if (!$player || !in_array($player['name'],[$row['name'],$row['previous_name']??$row['name']],true)) throw new RuntimeException('Player identity changed.');
        if (isset($row['birth']) && $player['birth'] && $player['birth']!==$row['birth']) throw new RuntimeException('Player birth changed.');
    }
    if (hsExists($db,'kbo_player_titleholder') && !$state) throw new RuntimeException('Existing titleholder table requires review.');
    echo 'Preflight passed: 4 overseas players, 43 Ulsan players (21 new), 660 titles.'.PHP_EOL;
    if ($mode==='--check') exit;
    if (!$state) {
        $state=['phase'=>'preparing','payload_hash'=>$payloadHash,'backup'=>'kbo_player_data_backup_history_'.date('Ymd_His'),'ddl'=>$db->query('SHOW CREATE TABLE kbo_player_data')->fetch()['Create Table'],'event'=>hsEvent($db)];
        hsSave($statePath,$state);
        $db->exec('CREATE TABLE '.hsQuote($state['backup']).' LIKE kbo_player_data');
        $db->exec('INSERT INTO '.hsQuote($state['backup']).' SELECT * FROM kbo_player_data');
        $db->exec("ALTER TABLE kbo_player_data DROP CONSTRAINT chk_player_kbodle,MODIFY is_kbodle TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0: retired; 1: KBODLE_GEN; 2: domestic active excluded; 3: overseas active; 4: Ulsan Whales active',ADD CONSTRAINT chk_player_kbodle CHECK (is_kbodle IN (0,1,2,3,4))");
        $db->exec("CREATE TABLE kbo_player_titleholder (PK BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,player_id INT NOT NULL,type VARCHAR(20) NOT NULL,year SMALLINT UNSIGNED NOT NULL,record DECIMAL(12,3) NOT NULL,PRIMARY KEY(PK),UNIQUE KEY uq_title_player_type_year(player_id,type,year),KEY idx_title_year_type(year,type),CONSTRAINT fk_title_player FOREIGN KEY(player_id) REFERENCES kbo_player_data(player_id),CONSTRAINT chk_title_year CHECK(year>=1982),CONSTRAINT chk_title_record CHECK(record>=0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $state['phase']='prepared';hsSave($statePath,$state);
    }
    if ($state['phase']!=='prepared' || (int)$db->query('SELECT COUNT(*) FROM kbo_player_titleholder')->fetchColumn()!==0) throw new RuntimeException('Unexpected partial import state.');
    $db->exec('ALTER TABLE kbo_player_titleholder AUTO_INCREMENT=1');
    $db->beginTransaction();
    try {
        $insert=$db->prepare('INSERT INTO kbo_player_data (player_id,name,team,is_kbodle,pos,birth,backNo,bat,`throw`,body,school,draft) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $update=$db->prepare('UPDATE kbo_player_data SET name=?,oldname=?,team=?,is_kbodle=?,birth=? WHERE player_id=?');
        foreach ($roster as $row) {
            if ($row['new']) $insert->execute([$row['player_id'],$row['name'],$row['team'],$row['is_kbodle'],$row['pos'],$row['birth'],$row['backNo'],$row['bat'],$row['throw'],$row['body'],playerSchoolOnly($row['school_source']),$row['draft']]);
            else {
                $lookup->execute([$row['player_id']]);$old=$lookup->fetch();
                $oldname=$old['name']===$row['name']?$old['oldname']:($old['oldname']?:$old['name']);
                $update->execute([$row['name'],$oldname,$row['team'],$row['is_kbodle'],$old['birth']?:$row['birth'],$row['player_id']]);
            }
        }
        $status=$db->prepare('UPDATE kbo_player_data SET team=?,is_kbodle=? WHERE player_id=?');
        foreach ($overseas as $row) $status->execute([$row['team'],$row['is_kbodle'],$row['player_id']]);
        $title=$db->prepare('INSERT INTO kbo_player_titleholder (player_id,type,year,record) VALUES (?,?,?,?)');
        foreach ($titles as $row) $title->execute([$row['player_id'],$row['type'],$row['year'],$row['record']]);
        hsVerify($db,$titles,$roster,$overseas,$state);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack();throw $e; }
    $state['phase']='complete';hsSave($statePath,$state);
    hsSummary($db);
    echo 'Complete. Backup: '.$state['backup'].'. Event was not edited.'.PHP_EOL;
} finally { $db->query("SELECT RELEASE_LOCK('wesiper-player-data-migration')"); }
