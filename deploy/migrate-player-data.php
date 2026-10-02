<?php
declare(strict_types=1);

// CLI only. Deploy outside the document root, with backend/config/database.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$statePath = __DIR__ . '/player-data-migration-state.json';
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--prepare', '--cutover', '--verify'], true)) {
    throw new InvalidArgumentException('Use --check, --prepare, --cutover or --verify.');
}

function qi(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function tableType(PDO $db, string $table): ?string {
    $stmt = $db->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() ?: null;
}
function fields(PDO $db, string $table): array {
    return array_column($db->query('SHOW FULL COLUMNS FROM ' . qi($table))->fetchAll(), null, 'Field');
}
function fingerprint(PDO $db, string $table): string {
    $hash = hash_init('sha256');
    $stmt = $db->query('SELECT * FROM ' . qi($table) . ' ORDER BY id');
    while ($row = $stmt->fetch()) hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
    return hash_final($hash);
}
function identicalDuplicates(PDO $db, string $table, string $key): void {
    $checks = [];
    foreach (fields($db, $table) as $name => $_) {
        if ($name === 'id') continue;
        $field = qi($name);
        $checks[] = "COUNT(DISTINCT BINARY $field) + IF(COUNT($field)<COUNT(*),1,0)>1";
    }
    $ids = $db->query('SELECT ' . qi($key) . ' FROM ' . qi($table) . ' GROUP BY ' . qi($key) . ' HAVING ' . implode(' OR ', $checks))->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) throw new RuntimeException("Conflicting duplicate IDs in $table: " . implode(',', $ids));
    if ($db->query('SELECT COUNT(*) FROM ' . qi($table) . ' WHERE ' . qi($key) . ' IS NULL OR ' . qi($key) . '<=0')->fetchColumn()) {
        throw new RuntimeException("Invalid player IDs in $table.");
    }
}
function assertZero(PDO $db, string $sql, string $message): void {
    if ((int)$db->query($sql)->fetchColumn() !== 0) throw new RuntimeException($message);
}
function verifyMerge(PDO $db, string $base, string $source): array {
    $baseFields = array_keys(fields($db, $base));
    $baseChecks = [];
    foreach ($baseFields as $field) {
        if ($field !== 'id') $baseChecks[] = 'NOT (BINARY p.' . qi($field) . ' <=> BINARY n.' . qi($field) . ')';
    }
    assertZero($db, 'SELECT COUNT(*) FROM ' . qi($base) . ' p LEFT JOIN kbo_player_data n ON n.p_no=p.p_no WHERE n.p_no IS NULL OR ' . implode(' OR ', $baseChecks), 'Generic player data did not survive the merge.');
    $sourceChecks = [];
    foreach (fields($db, $source) as $field => $_) {
        if (!in_array($field, ['id', 'playerId', 'isKbodle'], true)) $sourceChecks[] = 'NOT (BINARY d.' . qi($field) . ' <=> BINARY n.' . qi($field) . ')';
    }
    $sourceChecks[] = 'n.is_kbodle <> CASE WHEN d.isKbodle=\'1\' THEN 1 ELSE 2 END';
    assertZero($db, 'SELECT COUNT(*) FROM ' . qi($source) . ' d LEFT JOIN kbo_player_data n ON n.p_no=d.playerId WHERE n.p_no IS NULL OR ' . implode(' OR ', $sourceChecks), 'KBODLE fields or flags did not survive the merge.');
    assertZero($db, 'SELECT COUNT(*) FROM kbo_player_data n LEFT JOIN ' . qi($source) . ' d ON d.playerId=n.p_no WHERE d.playerId IS NULL AND n.is_kbodle<>0', 'Retired players must have is_kbodle=0.');
    assertZero($db, 'SELECT COUNT(*) FROM kbo_player_data n LEFT JOIN ' . qi($base) . ' p ON p.p_no=n.p_no WHERE p.p_no IS NULL', 'Unexpected players in merged table.');
    $expected = (int)$db->query('SELECT COUNT(DISTINCT p_no) FROM ' . qi($base))->fetchColumn();
    $actual = (int)$db->query('SELECT COUNT(*) FROM kbo_player_data')->fetchColumn();
    if ($expected !== $actual) throw new RuntimeException('Merged player count does not match unique base IDs.');
    return $db->query('SELECT is_kbodle, COUNT(*) total FROM kbo_player_data GROUP BY is_kbodle ORDER BY is_kbodle')->fetchAll();
}
function saveState(string $path, array $state): void {
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false || !rename($temporary, $path)) throw new RuntimeException('Cannot persist migration state.');
}
function reportEvent(PDO $db): void {
    $event = $db->query("SELECT * FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() AND EVENT_NAME='KBODLE_GEN'")->fetch();
    if (!$event) throw new RuntimeException('KBODLE_GEN event not found.');
    echo 'Event is user-managed; migration did not alter it. Current definition: ' . $event['EVENT_DEFINITION'] . PHP_EOL;
}

$locked = (int)$db->query("SELECT GET_LOCK('wesiper-player-data-migration', 0)")->fetchColumn();
if ($locked !== 1) throw new RuntimeException('Another player migration is running.');
try {
    if (tableType($db, 'kbo_player_data') === 'BASE TABLE' && !isset(fields($db, 'kbo_player_data')['p_no'])) {
        throw new RuntimeException('Column normalization superseded this historical migration. Use normalize-player-columns.php --verify.');
    }
    if ($mode === '--verify') {
        $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        echo json_encode(verifyMerge($db, $state['base_backup'], $state['source_backup']), JSON_PRETTY_PRINT) . PHP_EOL;
        reportEvent($db);
        exit;
    }
    if ($mode === '--cutover') {
        $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        if (($state['phase'] ?? '') === 'complete') {
            verifyMerge($db, $state['base_backup'], $state['source_backup']);
            reportEvent($db);
            echo "Already complete. Use --verify.\n";
            exit;
        }
        if (($state['phase'] ?? '') !== 'prepared') throw new RuntimeException('Prepare and validate before cutover.');
        foreach (['player_data' => 'source_fingerprint', 'kbo_playerlist_20250613' => 'base_fingerprint'] as $table => $key) {
            if (fingerprint($db, $table) !== $state[$key]) throw new RuntimeException("$table changed after preparation; refresh and revalidate before cutover.");
        }
        verifyMerge($db, 'kbo_playerlist_20250613', 'player_data');
        $sourceProjection = [];
        foreach ($state['source_fields'] as $field) {
            $sourceProjection[] = $field === 'id' ? 'kbodle_source_id AS id' : ($field === 'playerId' ? 'p_no AS playerId' : ($field === 'isKbodle' ? 'is_kbodle AS isKbodle' : qi($field)));
        }
        // Both old and new KBODLE queries work during the file deployment.
        $sourceProjection[] = 'p_no';
        $sourceProjection[] = 'is_kbodle';
        $compatibilityViews = [
            'player_data_merge_compat' => ['SELECT ' . implode(',', $sourceProjection) . ' FROM kbo_player_data WHERE is_kbodle IN (1,2)', array_merge($state['source_fields'], ['p_no', 'is_kbodle'])],
            'kbo_playerlist_merge_compat' => ['SELECT ' . implode(',', array_map('qi', $state['base_fields'])) . ' FROM kbo_player_data', $state['base_fields']],
        ];
        foreach ($compatibilityViews as $view => [$select, $expectedFields]) {
            $type = tableType($db, $view);
            if ($type === null) {
                $db->exec('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW ' . qi($view) . ' AS ' . $select);
            } else {
                // Resume after a failed event change only for our own matching
                // view, without dropping or replacing any existing object.
                $stmt = $db->prepare('SELECT DEFINER,VIEW_DEFINITION,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $stmt->execute([$view]);
                $definition = $stmt->fetch();
                if ($type !== 'VIEW' || array_keys(fields($db, $view)) !== $expectedFields || !$definition
                    || $definition['DEFINER'] !== $db->query('SELECT CURRENT_USER()')->fetchColumn()
                    || $definition['SECURITY_TYPE'] !== 'INVOKER'
                    || strpos($definition['VIEW_DEFINITION'], '`kbo_player_data`') === false) {
                    throw new RuntimeException("Reserved compatibility name belongs to another object: $view");
                }
            }
        }
        // The user owns event editing. The original SQL also remains valid
        // through player_data's filtered compatibility view and old aliases.
        $state['event_managed_by'] = 'user';
        saveState($statePath, $state);
        // Atomically replace the old names with compatibility views. No query
        // sees a missing table while the old physical tables become backups.
        $db->exec('RENAME TABLE player_data TO ' . qi($state['source_backup'])
            . ', kbo_playerlist_20250613 TO ' . qi($state['base_backup'])
            . ', player_data_merge_compat TO player_data'
            . ', kbo_playerlist_merge_compat TO kbo_playerlist_20250613');
        $state['phase'] = 'complete';
        saveState($statePath, $state);
        echo json_encode(verifyMerge($db, $state['base_backup'], $state['source_backup']), JSON_PRETTY_PRINT) . PHP_EOL;
        reportEvent($db);
        echo "Complete. Backups: {$state['source_backup']}, {$state['base_backup']}\n";
        exit;
    }
    if (tableType($db, 'player_data') !== 'BASE TABLE' || tableType($db, 'kbo_playerlist_20250613') !== 'BASE TABLE') throw new RuntimeException('Expected original physical tables. Use --verify for an existing migration.');
    identicalDuplicates($db, 'player_data', 'playerId');
    identicalDuplicates($db, 'kbo_playerlist_20250613', 'p_no');
    assertZero($db, 'SELECT COUNT(*) FROM player_data d LEFT JOIN kbo_playerlist_20250613 p ON p.p_no=d.playerId WHERE p.p_no IS NULL', 'Source player IDs are missing in the base table.');
    assertZero($db, "SELECT COUNT(*) FROM player_data WHERE isKbodle IS NOT NULL AND isKbodle NOT IN ('','0','1','2')", 'Unrecognized source flags.');
    assertZero($db, "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('player_data','kbo_playerlist_20250613')", 'Foreign keys require a separate migration.');
    assertZero($db, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('player_data','kbo_playerlist_20250613')", 'Triggers require a separate migration.');
    $event = $db->query("SELECT * FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() AND EVENT_NAME='KBODLE_GEN'")->fetch();
    if (!$event) throw new RuntimeException('KBODLE_GEN event not found.');
    foreach (['VIEWS' => ['TABLE_SCHEMA', 'VIEW_DEFINITION', 'TABLE_NAME'], 'ROUTINES' => ['ROUTINE_SCHEMA', 'ROUTINE_DEFINITION', 'ROUTINE_NAME'], 'EVENTS' => ['EVENT_SCHEMA', 'EVENT_DEFINITION', 'EVENT_NAME'], 'TRIGGERS' => ['TRIGGER_SCHEMA', 'ACTION_STATEMENT', 'TRIGGER_NAME']] as $type => [$schema, $definition, $name]) {
        $stmt = $db->query("SELECT `$name` FROM information_schema.$type WHERE `$schema`=DATABASE() AND (`$definition` LIKE '%player_data%' OR `$definition` LIKE '%kbo_playerlist_20250613%')" . ($type === 'EVENTS' ? " AND EVENT_NAME<>'KBODLE_GEN'" : ''));
        if ($stmt->fetchColumn()) throw new RuntimeException("Additional $type dependencies require review.");
    }
    $sourceFields = fields($db, 'player_data');
    $baseFields = fields($db, 'kbo_playerlist_20250613');
    foreach ($sourceFields as $field => $_) {
        if ($field !== 'id' && isset($baseFields[$field])) throw new RuntimeException("Unexpected overlapping column: $field");
    }
    echo "Preflight passed. Only identical duplicate rows will be collapsed.\n";
    if ($mode === '--check') exit;
    if (tableType($db, 'kbo_player_data') !== null || is_file($statePath)) throw new RuntimeException('A merged table or migration state already exists; do not overwrite it.');
    $stamp = date('Ymd_His');
    $state = [
        'phase' => 'preparing', 'prepared_at' => date(DATE_ATOM),
        'base_backup' => 'kbo_playerlist_backup_merge_' . $stamp,
        'source_backup' => 'player_data_backup_merge_' . $stamp,
        'base_fingerprint' => fingerprint($db, 'kbo_playerlist_20250613'),
        'source_fingerprint' => fingerprint($db, 'player_data'),
        'base_fields' => array_keys($baseFields), 'source_fields' => array_keys($sourceFields),
        'base_ddl' => $db->query('SHOW CREATE TABLE kbo_playerlist_20250613')->fetch()['Create Table'],
        'source_ddl' => $db->query('SHOW CREATE TABLE player_data')->fetch()['Create Table'],
        'event' => $event,
        'event_ddl' => $db->query('SHOW CREATE EVENT KBODLE_GEN')->fetch()['Create Event'],
    ];
    saveState($statePath, $state);
    $db->exec('CREATE TABLE kbo_player_data LIKE kbo_playerlist_20250613');
    $newFields = [];
    foreach ($sourceFields as $field => $definition) {
        if (in_array($field, ['id', 'playerId', 'isKbodle'], true)) continue;
        $sql = 'ALTER TABLE kbo_player_data ADD COLUMN ' . qi($field) . ' ' . $definition['Type'];
        if ($definition['Collation']) $sql .= ' COLLATE ' . $definition['Collation'];
        $db->exec($sql . ' NULL DEFAULT NULL');
        $newFields[] = $field;
    }
    $db->exec('ALTER TABLE kbo_player_data ADD COLUMN kbodle_source_id ' . $sourceFields['id']['Type'] . ' NULL DEFAULT NULL, ADD COLUMN is_kbodle TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0: retired; 1: KBODLE_GEN; 2: active, excluded from KBODLE_GEN\', ADD CONSTRAINT chk_player_kbodle CHECK (is_kbodle IN (0,1,2))');
    $db->beginTransaction();
    try {
        $db->exec('INSERT INTO kbo_player_data (' . implode(',', array_map('qi', array_keys($baseFields))) . ') SELECT p.* FROM kbo_playerlist_20250613 p JOIN (SELECT MIN(id) id FROM kbo_playerlist_20250613 GROUP BY p_no) chosen ON chosen.id=p.id');
        $set = array_map(static fn(string $field): string => 'p.' . qi($field) . '=d.' . qi($field), $newFields);
        $set[] = "p.is_kbodle=CASE WHEN d.isKbodle='1' THEN 1 ELSE 2 END";
        $set[] = 'p.kbodle_source_id=d.id';
        $db->exec('UPDATE kbo_player_data p JOIN player_data d ON d.playerId=p.p_no JOIN (SELECT MIN(id) id FROM player_data GROUP BY playerId) chosen ON chosen.id=d.id SET ' . implode(',', $set));
        verifyMerge($db, 'kbo_playerlist_20250613', 'player_data');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $db->exec('ALTER TABLE kbo_player_data MODIFY p_no ' . $baseFields['p_no']['Type'] . ' NOT NULL, ADD UNIQUE KEY uq_player_no (p_no), ADD KEY idx_player_kbodle (is_kbodle,p_no)');
    foreach (['player_data' => 'source_fingerprint', 'kbo_playerlist_20250613' => 'base_fingerprint'] as $table => $key) {
        if (fingerprint($db, $table) !== $state[$key]) throw new RuntimeException("$table changed while preparing; revalidate before deployment.");
    }
    $state['phase'] = 'prepared';
    saveState($statePath, $state);
    echo json_encode(verifyMerge($db, 'kbo_playerlist_20250613', 'player_data'), JSON_PRETTY_PRINT) . PHP_EOL;
    echo "Prepared. Validate APIs against kbo_player_data, run --cutover, publish KBODLE endpoints before common.php, then publish other API changes.\n";
} finally {
    $db->query("SELECT RELEASE_LOCK('wesiper-player-data-migration')");
}
