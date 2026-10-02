<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$mode = $argv[1] ?? '--check';
$online = ($argv[4] ?? '') === '--online';
if (!in_array($mode, ['--check','--apply','--verify'], true)) throw new InvalidArgumentException('Use --check, --apply or --verify');
$config = require ($argv[2] ?? __DIR__ . '/../backend/config/database.php');
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tables = ['kbo_player_nicknames','kbo_season_records','kbo_season_pitch_records'];
if (isset($argv[3])) {
    $requested=explode(',',$argv[3]);
    if (!$requested||array_diff($requested,$tables)||count(array_unique($requested))!==count($requested)) throw new InvalidArgumentException('Invalid table selection');
    $tables=$requested;
}
$report = [];
foreach (array_merge(['kbo_player_data'], $tables) as $table) {
    $columns = array_column($db->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(), null, 'Field');
    $report[$table] = ['player_id'=>$columns['player_id'], 'ddl'=>$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1]];
    if ($table !== 'kbo_player_data') {
        $report[$table]['orphans'] = $db->query("SELECT ids.* FROM (SELECT player_id,COUNT(*) AS record_count FROM `$table` WHERE player_id IS NOT NULL GROUP BY player_id) ids LEFT JOIN kbo_player_data p ON p.player_id=ids.player_id WHERE p.player_id IS NULL ORDER BY ids.player_id")->fetchAll();
    }
}
$foreignKeys = $db->query("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
if ($mode === '--check') { echo json_encode(['tables'=>$report,'foreign_keys'=>$foreignKeys], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); exit; }
$parentType = $report['kbo_player_data']['player_id']['Type'];
$normalizeType = static fn($type)=>preg_replace('/\(\d+\)/','',strtolower($type));
$modify = [];
foreach ($tables as $table) {
    if ($report[$table]['orphans']) throw new RuntimeException('Orphan player IDs prevent foreign key: ' . $table);
    if ($normalizeType($report[$table]['player_id']['Type']) !== $normalizeType($parentType)) {
        if ($normalizeType($parentType)!=='int'||$normalizeType($report[$table]['player_id']['Type'])!=='int unsigned') throw new RuntimeException('Unsupported player ID type mismatch: ' . $table);
        if ((int)$db->query("SELECT COUNT(*) FROM `$table` WHERE player_id>2147483647")->fetchColumn()!==0) throw new RuntimeException('Player ID would overflow signed INT');
        $modify[$table]="MODIFY COLUMN player_id INT ".($report[$table]['player_id']['Null']==='NO'?'NOT NULL':'NULL DEFAULT NULL').', ';
    }
    if (!str_contains($report[$table]['ddl'], 'ENGINE=InnoDB')) throw new RuntimeException('InnoDB required: ' . $table);
}
$present = [];
foreach ($foreignKeys as $key) {
    if (in_array($key['TABLE_NAME'], $tables, true) && $key['COLUMN_NAME'] === 'player_id') {
        if ($key['REFERENCED_TABLE_NAME'] !== 'kbo_player_data' || $key['REFERENCED_COLUMN_NAME'] !== 'player_id') throw new RuntimeException('Conflicting player foreign key');
        $present[$key['TABLE_NAME']] = $key;
    }
}
if ($mode === '--apply') {
    $backup = __DIR__ . '/player-foreign-keys-before-' . gmdate('Ymd-His') . '.sql';
    if (file_put_contents($backup, implode(";\n\n", array_column($report, 'ddl')) . ";\n", LOCK_EX) === false) throw new RuntimeException('Could not save schema backup');
    $db->exec('SET SESSION lock_wait_timeout=120');
    $db->exec('SET SESSION innodb_lock_wait_timeout=120');
    foreach ($tables as $table) {
        if (isset($present[$table])) continue;
        $name = 'fk_' . $table . '_player';
        $prefix=$modify[$table]??'';
        if (!$online) {
            $db->exec("ALTER TABLE `$table` {$prefix}ADD CONSTRAINT `$name` FOREIGN KEY (player_id) REFERENCES kbo_player_data (player_id) ON UPDATE RESTRICT ON DELETE RESTRICT");
            continue;
        }
        // Type conversion is validated and copied with referential checking on.
        // Adding an FK via NOCOPY requires checks off on this connection only.
        // Hold child WRITE and parent READ locks and validate every existing ID
        // first, so neither side can change during registration.
        if ($prefix !== '') $db->exec("ALTER TABLE `$table` ".rtrim($prefix,', '));
        $db->exec("LOCK TABLES `$table` WRITE, kbo_player_data READ, kbo_player_data AS p READ");
        try {
            $orphan = $db->query("SELECT COUNT(*) FROM (SELECT DISTINCT player_id FROM `$table` WHERE player_id IS NOT NULL) ids LEFT JOIN kbo_player_data p ON p.player_id=ids.player_id WHERE p.player_id IS NULL")->fetchColumn();
            if ((int)$orphan !== 0) throw new RuntimeException('New orphan IDs detected under lock');
            $db->exec('SET SESSION foreign_key_checks=0');
            $db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (player_id) REFERENCES kbo_player_data (player_id) ON UPDATE RESTRICT ON DELETE RESTRICT, ALGORITHM=NOCOPY");
            $db->exec('SET SESSION foreign_key_checks=1');
            $orphan = $db->query("SELECT COUNT(*) FROM (SELECT DISTINCT player_id FROM `$table` WHERE player_id IS NOT NULL) ids LEFT JOIN kbo_player_data p ON p.player_id=ids.player_id WHERE p.player_id IS NULL")->fetchColumn();
            if ((int)$orphan !== 0) throw new RuntimeException('Post-registration orphan check failed');
        } finally {
            $db->exec('SET SESSION foreign_key_checks=1');
            $db->exec('UNLOCK TABLES');
        }
    }
}
$q = $db->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND COLUMN_NAME='player_id' AND REFERENCED_TABLE_NAME='kbo_player_data' AND REFERENCED_COLUMN_NAME='player_id'");
$verified = array_values(array_filter($q->fetchAll(), static fn($row)=>in_array($row['TABLE_NAME'],$tables,true)));
if (count($verified) !== count($tables)) throw new RuntimeException('Foreign key verification failed');
echo json_encode(['verified'=>$verified],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
