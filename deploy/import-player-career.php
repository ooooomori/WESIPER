<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
umask(0077);
$config=require __DIR__.'/../backend/config/database.php';
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$db->exec('SET SESSION lock_wait_timeout=10');
$mode=$argv[1]??'--check';
if (!in_array($mode,['--check','--prepare','--cutover','--verify'],true)) throw new InvalidArgumentException('Invalid mode');
$load=static fn($name)=>json_decode(file_get_contents(__DIR__.'/../data/player-career/'.$name),true,512,JSON_THROW_ON_ERROR);
$foreign=$load('foreign-players.json');$careers=$load('careers.json');
$payloadHash=hash('sha256',json_encode([$foreign,$careers],JSON_THROW_ON_ERROR));
$statePath=__DIR__.'/player-career-state.json';
function crQuote(string $name):string { return '`'.str_replace('`','``',$name).'`'; }
function crSave(string $path,array $state):void {
    if (file_put_contents($path.'.tmp',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false || !rename($path.'.tmp',$path)) throw new RuntimeException('State save failed');
}
function crEvent(PDO $db):string {return $db->query('SHOW CREATE EVENT KBODLE_GEN')->fetch()['Create Event'];}
function crColumns(PDO $db):array {return $db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN);}
function crKey(array $r):string {return json_encode(array_map(static fn($k)=>$r[$k],['player_id','category','type','year','month','pos']),JSON_UNESCAPED_UNICODE);}
function crSummary(PDO $db):void {
    echo json_encode(['players'=>(int)$db->query('SELECT COUNT(*) FROM kbo_player_data')->fetchColumn(),'foreign'=>(int)$db->query('SELECT COUNT(*) FROM kbo_player_data WHERE is_foreign=1')->fetchColumn(),'fullnames'=>(int)$db->query('SELECT COUNT(*) FROM kbo_player_data WHERE fullname IS NOT NULL')->fetchColumn(),'careers'=>$db->query('SELECT category,type,COUNT(*) total,MIN(year) first_year,MAX(year) last_year FROM kbo_player_career GROUP BY category,type ORDER BY category,type')->fetchAll(),'wbc_unknown'=>$db->query("SELECT p.player_id,p.name FROM kbo_player_data p JOIN kbo_player_career c ON c.player_id=p.player_id WHERE c.category='national' AND c.type='WBC' AND c.year IS NULL")->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}
function crValidate(array $foreign,array $careers):void {
    $seen=[];
    foreach ($foreign as $p) {
        if (!is_int($p['player_id']) || $p['player_id']<=0 || isset($seen[$p['player_id']])) throw new RuntimeException('Invalid foreign identity');
        $seen[$p['player_id']]=true;
    }
    $keys=[];$wbc=[];
    foreach ($careers as $r) {
        if (!is_int($r['player_id']) || $r['player_id']<=0 || !in_array($r['category'],['award','national'],true)) throw new RuntimeException('Invalid career identity/category');
        if ($r['category']==='national') {
            if ($r['type']!=='WBC' || $r['team']!==null || $r['pos']!==null || $r['month']!==null || $r['note']!==null || ($r['year']!==null && !in_array($r['year'],[2006,2009,2013,2017,2023,2026],true))) throw new RuntimeException('Invalid national row');
            $wbc[$r['player_id']]=true;
        } else {
            if (!in_array($r['type'],['골든글러브','MVP','올스타','신인왕','수비상','월간 MVP','한국시리즈 MVP'],true) || !is_int($r['year']) || $r['year']<1982 || $r['year']>2026 || !$r['team']) throw new RuntimeException('Invalid award row');
            if (($r['type']==='월간 MVP')!==($r['month']!==null) || ($r['month']!==null && (!is_int($r['month']) || $r['month']<1 || $r['month']>12))) throw new RuntimeException('Invalid month');
            if (in_array($r['type'],['골든글러브','수비상'],true)!==($r['pos']!==null)) throw new RuntimeException('Invalid position');
            if ($r['note']!==null && !($r['type']==='올스타' && $r['note']==='MVP')) throw new RuntimeException('Invalid note');
        }
        $key=crKey($r);if (isset($keys[$key])) throw new RuntimeException('Duplicate same occurrence');$keys[$key]=true;
    }
    if (count($wbc)!==159 || count($careers)<2700 || count($foreign)<500) throw new RuntimeException('Incomplete prepared data');
}
function crVerify(PDO $db,array $foreign,array $careers,array $state):void {
    $actual=$db->query('SELECT PK,player_id,category,type,team,year,month,pos,note FROM kbo_player_career ORDER BY PK')->fetchAll();
    if (count($actual)!==count($careers)) throw new RuntimeException('Career count mismatch');
    foreach ($actual as $index=>$row) {
        if ((int)$row['PK']!==$index+1) throw new RuntimeException('PK sequence mismatch');
        unset($row['PK']);$row['player_id']=(int)$row['player_id'];
        foreach (['year','month'] as $k) if ($row[$k]!==null) $row[$k]=(int)$row[$k];
        if ($row!==$careers[$index]) throw new RuntimeException('Career occurrence mismatch at '.($index+1));
    }
    $expected=array_fill_keys(array_column($foreign,'player_id'),true);
    $backup=crQuote($state['backup']);
    $players=$db->query("SELECT p.player_id,p.is_foreign,p.fullname,p.oldname,b.oldname before_oldname FROM kbo_player_data p JOIN $backup b ON b.player_id=p.player_id ORDER BY p.player_id")->fetchAll();
    if (count($players)!==6002 || (int)$db->query('SELECT COUNT(*) FROM kbo_player_data')->fetchColumn()!==6002) throw new RuntimeException('Player count changed');
    foreach ($players as $p) {
        $isForeign=isset($expected[$p['player_id']]);$move=$isForeign && $p['before_oldname']!==null && trim($p['before_oldname'])!=='';
        if ($p['is_foreign']!==($isForeign?1:null) || $p['fullname']!==($move?$p['before_oldname']:null) || $p['oldname']!==($move?null:$p['before_oldname'])) throw new RuntimeException('Foreign/fullname mismatch '.$p['player_id']);
    }
    $comparisons=[];
    foreach (crColumns($db) as $k) if (!in_array($k,['oldname','img','is_foreign','fullname'],true)) $comparisons[]='NOT(BINARY p.'.crQuote($k).' <=> BINARY b.'.crQuote($k).')';
    if ((int)$db->query("SELECT COUNT(*) FROM kbo_player_data p JOIN $backup b ON b.player_id=p.player_id WHERE ".implode(' OR ',$comparisons))->fetchColumn()!==0) throw new RuntimeException('Unrequested profile field changed');
    $oldWbc=array_map('intval',$db->query("SELECT player_id FROM $backup WHERE is_WBC=1 ORDER BY player_id")->fetchAll(PDO::FETCH_COLUMN));
    $newWbc=array_map('intval',$db->query("SELECT DISTINCT player_id FROM kbo_player_career WHERE category='national' AND type='WBC' ORDER BY player_id")->fetchAll(PDO::FETCH_COLUMN));
    if ($oldWbc!==$newWbc) throw new RuntimeException('WBC targets changed');
    if ((int)$db->query('SELECT COUNT(*) FROM kbo_player_titleholder')->fetchColumn()!==660 || crEvent($db)!==$state['event']) throw new RuntimeException('Title table/event changed');
}
crValidate($foreign,$careers);
if ((int)$db->query("SELECT GET_LOCK('wesiper-player-data-migration',0)")->fetchColumn()!==1) throw new RuntimeException('Another migration is running');
try {
    $state=is_file($statePath)?json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR):null;
    if ($state && $state['payload_hash']!==$payloadHash) throw new RuntimeException('Payload changed after preparation');
    if ($mode==='--verify' || ($state['phase']??'')==='complete') {
        if (!$state) throw new RuntimeException('Missing state');
        crVerify($db,$foreign,$careers,$state);crSummary($db);exit;
    }
    if ($mode==='--cutover') {
        if (!$state || $state['phase']!=='prepared' || !is_file(__DIR__.'/player-career-api-deployed')) throw new RuntimeException('Prepare and deploy callers first');
        crVerify($db,$foreign,$careers,$state);
        foreach (['kbobingo/search.php','kbodle/get_player_list.php','kbocandle/get_player_list.php','playerProfile.php'] as $file) if (hash_file('sha256',__DIR__.'/../backend/api/'.$file)!==hash_file('sha256','/opt/bitnami/apache/htdocs/api/'.$file)) throw new RuntimeException('Caller deployment mismatch');
        if (is_file('/opt/bitnami/apache/htdocs/api/kbobingo/search_test.php')) throw new RuntimeException('Deleted search_test URL still exists');
        if (hash_file('sha256',__DIR__.'/../backend/lib/player-career.php')!==hash_file('sha256','/opt/bitnami/apache/htdocs/lib/player-career.php')) throw new RuntimeException('Helper deployment mismatch');
        $team="CASE c.team WHEN '삼성' THEN 'sam' WHEN '롯데' THEN 'lot' WHEN 'MBC' THEN 'lg' WHEN 'LG' THEN 'lg' WHEN 'OB' THEN 'doo' WHEN '두산' THEN 'doo' WHEN '해태' THEN 'kia' WHEN 'KIA' THEN 'kia' WHEN '빙그레' THEN 'han' WHEN '한화' THEN 'han' WHEN 'SK' THEN 'ssg' WHEN 'SSG' THEN 'ssg' WHEN '삼미' THEN 'hyd' WHEN '청보' THEN 'hyd' WHEN '태평양' THEN 'hyd' WHEN '현대' THEN 'hyd' WHEN '쌍방울' THEN 'sbw' WHEN '우리' THEN 'kiw' WHEN '히어로즈' THEN 'kiw' WHEN '넥센' THEN 'kiw' WHEN '키움' THEN 'kiw' WHEN 'NC' THEN 'nc' WHEN 'KT' THEN 'kt' END";
        $db->exec("CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW kbo_playerlist_20250613 AS SELECT p.id,p.name p_name,COALESCE(p.oldname,p.fullname) p_oldname,p.player_id p_no,p.img p_img,p.pos p_pos,CASE WHEN EXISTS(SELECT 1 FROM kbo_player_career c WHERE c.player_id=p.player_id AND c.category='national' AND c.type='WBC') THEN 1 ELSE NULL END is_WBC,p.is_MLB,(SELECT GROUP_CONCAT(DISTINCT $team ORDER BY $team SEPARATOR ',') FROM kbo_player_career c WHERE c.player_id=p.player_id AND c.category='award' AND c.type='올스타') is_AS,(SELECT GROUP_CONCAT(DISTINCT $team ORDER BY $team SEPARATOR ',') FROM kbo_player_career c WHERE c.player_id=p.player_id AND c.category='award' AND c.type='골든글러브') is_GG,p.body p_body,p.birth p_birth,p.school p_career FROM kbo_player_data p");
        $db->exec('CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW player_data AS SELECT kbodle_source_id id,backNo,name,COALESCE(oldname,fullname) oldname,team,pos,bat,`throw`,birth,body,school career,draft,is_kbodle isKbodle,mainPos,subPos,hs,hsLoc,player_id playerId,player_id p_no,is_kbodle FROM kbo_player_data WHERE is_kbodle IN (1,2)');
        $db->exec('ALTER TABLE kbo_player_data DROP COLUMN is_WBC,DROP COLUMN is_GG,DROP COLUMN is_AS');
        $state['phase']='complete';crSave($statePath,$state);crVerify($db,$foreign,$careers,$state);crSummary($db);exit;
    }
    if ($state) {
        if ($state['phase']!=='prepared') throw new RuntimeException('Partial preparation requires review');
        crVerify($db,$foreign,$careers,$state);crSummary($db);exit;
    }
    $cols=crColumns($db);
    if (!in_array('is_WBC',$cols,true) || in_array('fullname',$cols,true) || in_array('is_foreign',$cols,true)) throw new RuntimeException('Expected old flag schema');
    if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kbo_player_career'")->fetchColumn()!==0) throw new RuntimeException('Career table already exists');
    $lookup=$db->prepare('SELECT name,oldname FROM kbo_player_data WHERE player_id=?');
    foreach ($foreign as $p) { $lookup->execute([$p['player_id']]);$actual=$lookup->fetch();if (!$actual || $actual!==['name'=>$p['name'],'oldname'=>$p['oldname']]) throw new RuntimeException('Foreign identity changed '.$p['player_id']); }
    $ids=$db->query('SELECT player_id FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN);$ids=array_fill_keys($ids,true);
    foreach ($careers as $r) if (!isset($ids[$r['player_id']])) throw new RuntimeException('Career player missing');
    $oldWbc=array_map('intval',$db->query('SELECT player_id FROM kbo_player_data WHERE is_WBC=1 ORDER BY player_id')->fetchAll(PDO::FETCH_COLUMN));
    $newWbc=array_unique(array_column(array_filter($careers,static fn($r)=>$r['category']==='national'),'player_id'));sort($newWbc);
    if ($oldWbc!==$newWbc || count($ids)!==6002) throw new RuntimeException('Catalog changed');
    echo 'Preflight passed: '.count($foreign).' foreign players, '.count($careers).' individual career occurrences.'.PHP_EOL;
    if ($mode==='--check') exit;
    $state=['phase'=>'preparing','payload_hash'=>$payloadHash,'backup'=>'kbo_player_data_backup_career_'.date('Ymd_His'),'ddl'=>$db->query('SHOW CREATE TABLE kbo_player_data')->fetch()['Create Table'],'event'=>crEvent($db),'views'=>[]];
    $views=$db->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND VIEW_DEFINITION LIKE '%kbo_player_data%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($views as $view) {
        if (!in_array($view,['player_data','kbo_playerlist_20250613'],true)) throw new RuntimeException('Unexpected dependent view '.$view);
        $state['views'][$view]=$db->query('SHOW CREATE VIEW '.crQuote($view))->fetch()['Create View'];
    }
    crSave($statePath,$state);
    $db->exec('CREATE TABLE '.crQuote($state['backup']).' LIKE kbo_player_data');
    $db->exec('INSERT INTO '.crQuote($state['backup']).' SELECT * FROM kbo_player_data');
    $db->exec("ALTER TABLE kbo_player_data ADD COLUMN is_foreign TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1: KBO foreign-player registration; NULL: others',ADD COLUMN fullname VARCHAR(255) NULL DEFAULT NULL,ADD CONSTRAINT chk_player_foreign CHECK(is_foreign IS NULL OR is_foreign=1)");
    $db->exec("CREATE TABLE kbo_player_career (PK BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,player_id INT NOT NULL,category VARCHAR(10) NOT NULL,type VARCHAR(30) NOT NULL,team VARCHAR(20) NULL,year SMALLINT UNSIGNED NULL,month TINYINT UNSIGNED NULL,pos VARCHAR(10) NULL,note VARCHAR(100) NULL,PRIMARY KEY(PK),KEY idx_career_player(player_id,category,type),KEY idx_career_year(year,type),CONSTRAINT fk_career_player FOREIGN KEY(player_id) REFERENCES kbo_player_data(player_id),CONSTRAINT chk_career_category CHECK(category IN ('award','national')),CONSTRAINT chk_career_year CHECK(year IS NULL OR year>=1982),CONSTRAINT chk_career_month CHECK((type='월간 MVP' AND month BETWEEN 1 AND 12) OR (type<>'월간 MVP' AND month IS NULL)),CONSTRAINT chk_career_position CHECK((type IN ('골든글러브','수비상') AND pos IS NOT NULL) OR (type NOT IN ('골든글러브','수비상') AND pos IS NULL)),CONSTRAINT chk_career_national CHECK((category='national' AND type='WBC' AND team IS NULL AND month IS NULL AND pos IS NULL AND note IS NULL) OR (category='award' AND type IN ('골든글러브','MVP','올스타','신인왕','수비상','월간 MVP','한국시리즈 MVP') AND team IS NOT NULL AND year IS NOT NULL)),CONSTRAINT chk_career_note CHECK(note IS NULL OR (category='award' AND type='올스타' AND note='MVP'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->beginTransaction();
    try {
        $update=$db->prepare("UPDATE kbo_player_data SET is_foreign=1,fullname=CASE WHEN oldname IS NOT NULL AND TRIM(oldname)<>'' THEN oldname ELSE NULL END,oldname=CASE WHEN oldname IS NOT NULL AND TRIM(oldname)<>'' THEN NULL ELSE oldname END WHERE player_id=?");
        foreach ($foreign as $p) $update->execute([$p['player_id']]);
        $insert=$db->prepare('INSERT INTO kbo_player_career(player_id,category,type,team,year,month,pos,note) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($careers as $r) $insert->execute(array_values($r));
        crVerify($db,$foreign,$careers,$state);$db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack();throw $e; }
    $state['phase']='prepared';crSave($statePath,$state);crSummary($db);
} finally {$db->query("SELECT RELEASE_LOCK('wesiper-player-data-migration')");}
