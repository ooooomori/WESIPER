<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require __DIR__ . '/../backend/lib/player-school.php';
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$path = __DIR__ . '/player-columns-state.json';
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check','--prepare','--cutover','--verify'], true)) throw new InvalidArgumentException('Use --check, --prepare, --cutover or --verify.');
function pq(string $name): string { return '`' . str_replace('`','``',$name) . '`'; }
function pc(PDO $db, string $table): array { return array_column($db->query('SHOW FULL COLUMNS FROM ' . pq($table))->fetchAll(), null, 'Field'); }
function psave(string $path, array $state): void {
    if (file_put_contents($path . '.tmp', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !rename($path . '.tmp', $path)) throw new RuntimeException('Cannot save normalization state.');
}
function normalizedPlayer(array $row): array {
    foreach (['name','oldname','pos','body','birth','career'] as $field) $row[$field] = longerPlayerValue($row[$field], $row['p_' . $field]);
    $row['school'] = playerSchoolOnly($row['career']);
    unset($row['career']);
    $row['player_id'] = $row['p_no'];
    $row['img'] = $row['p_img'];
    foreach (array_keys($row) as $field) if (strpos($field,'p_') === 0) unset($row[$field]);
    return $row;
}
function validatePlayers(PDO $db, array $state, string $table, bool $bridge = false): void {
    $columns = pc($db,$table);
    if (!$bridge && (isset($columns['career']) || array_filter(array_keys($columns), static fn($c) => strpos($c,'p_') === 0))) throw new RuntimeException('Obsolete columns remain.');
    $current = $db->query('SELECT * FROM ' . pq($table) . ' ORDER BY id')->fetchAll();
    $original = $db->query('SELECT * FROM ' . pq($state['backup']) . ' ORDER BY id')->fetchAll();
    if (count($current) !== count($original)) throw new RuntimeException('Player count changed.');
    foreach ($original as $index => $old) {
        $expected = normalizedPlayer($old);
        $actual = $current[$index];
        foreach ($expected as $field => $value) {
            // img is maintained by live bingo requests during deployment.
            if ($field === 'img') continue;
            if ($actual[$field] !== $value) throw new RuntimeException("Player {$old['p_no']} field $field does not match normalization.");
        }
        if ($actual['school'] !== playerSchoolOnly($actual['school'])) throw new RuntimeException('School still contains non-school history.');
    }
    if ((int)$db->query('SELECT COUNT(*)-COUNT(DISTINCT player_id) FROM ' . pq($table))->fetchColumn() !== 0) throw new RuntimeException('Duplicate player IDs.');
}
function psummary(PDO $db, string $table): void {
    echo json_encode($db->query('SELECT is_kbodle, COUNT(*) total, COUNT(school) school_filled FROM ' . pq($table) . ' GROUP BY is_kbodle ORDER BY is_kbodle')->fetchAll(), JSON_PRETTY_PRINT) . PHP_EOL;
}
if ((int)$db->query("SELECT GET_LOCK('wesiper-player-data-migration',0)")->fetchColumn() !== 1) throw new RuntimeException('Another player migration is running.');
try {
    if ($mode === '--verify') {
        $state = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        validatePlayers($db,$state,'kbo_player_data');
        psummary($db,'kbo_player_data');
        exit;
    }
    if ($mode === '--cutover') {
        $state = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if ($state['phase'] === 'complete') { validatePlayers($db,$state,'kbo_player_data'); echo "Already complete.\n"; exit; }
        if ($state['phase'] !== 'prepared') throw new RuntimeException('Prepare and validate APIs first.');
        $events = $db->query("SELECT EVENT_NAME,EVENT_DEFINITION FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() AND EVENT_DEFINITION LIKE '%kbo_player_data%'")->fetchAll();
        foreach ($events as $event) if (preg_match('/\bp_[A-Za-z0-9_]+\b|\bcareer\b/i',$event['EVENT_DEFINITION'])) throw new RuntimeException("User must update {$event['EVENT_NAME']} to canonical columns before cutover. The migration does not edit events.");
        validatePlayers($db,$state,'kbo_player_data',true);
        $fields = array_keys(pc($db,$state['stage']));
        $projection = implode(',',array_map('pq',$fields));
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM ' . pq($state['stage']));
            $db->exec('INSERT INTO ' . pq($state['stage']) . " ($projection) SELECT $projection FROM kbo_player_data");
            validatePlayers($db,$state,$state['stage']);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        // Both definitions work before and after the atomic table exchange.
        $db->exec('CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW kbo_playerlist_20250613 AS SELECT id,name AS p_name,oldname AS p_oldname,player_id AS p_no,img AS p_img,pos AS p_pos,is_WBC,is_MLB,is_AS,is_GG,body AS p_body,birth AS p_birth,school AS p_career FROM kbo_player_data');
        $db->exec('CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW player_data AS SELECT kbodle_source_id AS id,backNo,name,oldname,team,pos,bat,`throw`,birth,body,school AS career,draft,is_kbodle AS isKbodle,mainPos,subPos,hs,hsLoc,player_id AS playerId,player_id AS p_no,is_kbodle FROM kbo_player_data WHERE is_kbodle IN (1,2)');
        $db->exec('RENAME TABLE kbo_player_data TO ' . pq($state['bridge_backup']) . ', ' . pq($state['stage']) . ' TO kbo_player_data');
        $state['phase'] = 'complete';
        psave($path,$state);
        validatePlayers($db,$state,'kbo_player_data');
        psummary($db,'kbo_player_data');
        echo "Complete. Raw backup: {$state['backup']}; transition backup: {$state['bridge_backup']}. Events were not edited.\n";
        exit;
    }
    $columns = pc($db,'kbo_player_data');
    if (!isset($columns['p_no'],$columns['p_name'],$columns['p_career'],$columns['career'])) throw new RuntimeException('Expected the pre-normalization schema.');
    $expectedPrefixes = ['p_name','p_oldname','p_no','p_img','p_pos','p_body','p_birth','p_career'];
    foreach (array_keys($columns) as $field) if (strpos($field,'p_') === 0 && !in_array($field,$expectedPrefixes,true)) throw new RuntimeException("Unexpected prefixed column: $field");
    if ($db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='kbo_player_data'")->fetchColumn()
        || $db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='kbo_player_data'")->fetchColumn()) throw new RuntimeException('Foreign keys/triggers require a separate migration.');
    $views = $db->query("SELECT TABLE_NAME,VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND VIEW_DEFINITION LIKE '%kbo_player_data%'")->fetchAll();
    foreach ($views as $view) if (!in_array($view['TABLE_NAME'],['player_data','kbo_playerlist_20250613'],true)) throw new RuntimeException('Unexpected dependent view.');
    echo "Preflight passed: 8 prefixed columns, 6 duplicate pairs. Events remain user-managed.\n";
    if ($mode === '--check') exit;
    if (is_file($path)) throw new RuntimeException('Normalization state already exists; do not overwrite it.');
    $stamp = date('Ymd_His');
    $state = ['phase'=>'preparing','backup'=>'kbo_player_data_backup_columns_' . $stamp,'bridge_backup'=>'kbo_player_data_backup_bridge_' . $stamp,'stage'=>'kbo_player_data_columns_stage','views'=>$views,'ddl'=>$db->query('SHOW CREATE TABLE kbo_player_data')->fetch()['Create Table'],'event'=>$db->query('SHOW CREATE EVENT KBODLE_GEN')->fetch()['Create Event']];
    psave($path,$state);
    $db->exec('CREATE TABLE ' . pq($state['backup']) . ' LIKE kbo_player_data');
    $db->exec('INSERT INTO ' . pq($state['backup']) . ' SELECT * FROM kbo_player_data');
    $db->exec('CREATE TABLE ' . pq($state['stage']) . ' LIKE kbo_player_data');
    $db->exec('ALTER TABLE ' . pq($state['stage']) . ' DROP COLUMN name,DROP COLUMN oldname,DROP COLUMN pos,DROP COLUMN body,DROP COLUMN birth,DROP COLUMN career,CHANGE p_name name VARCHAR(6) NULL DEFAULT NULL,CHANGE p_oldname oldname VARCHAR(11) NULL DEFAULT NULL,CHANGE p_no player_id INT NOT NULL,CHANGE p_img img VARCHAR(12) NULL DEFAULT NULL,CHANGE p_pos pos VARCHAR(3) NOT NULL,CHANGE p_body body VARCHAR(255) NULL DEFAULT NULL,CHANGE p_birth birth VARCHAR(32) NULL DEFAULT NULL,CHANGE p_career school TEXT NULL DEFAULT NULL,RENAME INDEX uq_player_no TO uq_player_id');
    $fields = array_keys(pc($db,$state['stage']));
    $stmt = $db->prepare('INSERT INTO ' . pq($state['stage']) . ' (' . implode(',',array_map('pq',$fields)) . ') VALUES (' . implode(',',array_fill(0,count($fields),'?')) . ')');
    $rows = $db->query('SELECT * FROM ' . pq($state['backup']) . ' ORDER BY id')->fetchAll();
    $db->beginTransaction();
    try {
        foreach ($rows as $row) { $row = normalizedPlayer($row); $stmt->execute(array_map(static fn($field) => $row[$field],$fields)); }
        validatePlayers($db,$state,$state['stage']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    // Temporarily support both schema versions so callers can deploy first.
    $db->exec('ALTER TABLE kbo_player_data MODIFY name VARCHAR(6) NULL DEFAULT NULL,MODIFY body VARCHAR(255) NULL DEFAULT NULL,MODIFY birth VARCHAR(32) NULL DEFAULT NULL,ADD COLUMN player_id INT NULL DEFAULT NULL,ADD COLUMN img VARCHAR(12) NULL DEFAULT NULL,ADD COLUMN school TEXT NULL DEFAULT NULL');
    $set = ['name','oldname','pos','body','birth','player_id','school'];
    $assignments = array_map(static fn($field) => 'p.' . pq($field) . '=n.' . pq($field),$set);
    $assignments[] = 'p.img=p.p_img';
    $db->exec('UPDATE kbo_player_data p JOIN ' . pq($state['stage']) . ' n ON n.id=p.id SET ' . implode(',',$assignments));
    $db->exec('ALTER TABLE kbo_player_data MODIFY player_id INT NOT NULL,ADD UNIQUE KEY uq_player_id (player_id)');
    validatePlayers($db,$state,'kbo_player_data',true);
    $state['phase'] = 'prepared';
    psave($path,$state);
    psummary($db,$state['stage']);
    echo "Prepared. Update the event to SELECT player_id,name; validate and deploy canonical APIs before --cutover.\n";
} finally { $db->query("SELECT RELEASE_LOCK('wesiper-player-data-migration')"); }
