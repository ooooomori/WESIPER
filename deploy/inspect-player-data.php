<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$report = ['version' => $db->query('SELECT VERSION()')->fetchColumn(), 'tables' => []];
foreach (['player_data' => 'playerId', 'kbo_playerlist_20250613' => 'p_no', 'kbo_player_data' => 'p_no'] as $table => $id) {
    $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $exists->execute([$table]);
    if (!$exists->fetchColumn()) continue;
    if ($table === 'kbo_player_data' && in_array('player_id', $db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN), true)) $id = 'player_id';
    $report['tables'][$table] = [
        'ddl' => $db->query("SHOW CREATE TABLE `$table`")->fetch(),
        'counts' => $db->query("SELECT COUNT(*) total, COUNT(DISTINCT `$id`) unique_ids, SUM(`$id` IS NULL OR `$id`=0) invalid_ids FROM `$table`")->fetch(),
        'duplicates' => $db->query("SELECT `$id`, COUNT(*) total FROM `$table` GROUP BY `$id` HAVING COUNT(*)>1")->fetchAll(),
    ];
}
if (isset($report['tables']['player_data'], $report['tables']['kbo_playerlist_20250613'])) {
    $report['missing_ids'] = $db->query('SELECT d.playerId, d.name FROM player_data d LEFT JOIN kbo_playerlist_20250613 p ON p.p_no=d.playerId WHERE p.p_no IS NULL')->fetchAll();
    $report['kbodle_counts'] = $db->query('SELECT COUNT(*) total, SUM(draft IS NOT NULL AND draft<>\'\') searchable, SUM(isKbodle IS NOT NULL) flagged FROM player_data')->fetch();
    $report['differences'] = $db->query('SELECT p.p_no, p.p_name, d.name, p.p_oldname, d.oldname, p.p_pos, d.pos FROM kbo_playerlist_20250613 p JOIN player_data d ON d.playerId=p.p_no WHERE NOT (p.p_name <=> d.name) OR NOT (p.p_pos <=> d.pos) LIMIT 30')->fetchAll();
    $report['source_duplicate_rows'] = $db->query('SELECT * FROM player_data WHERE playerId IN (SELECT playerId FROM player_data GROUP BY playerId HAVING COUNT(*)>1)')->fetchAll();
    $report['target_duplicate_rows'] = $db->query('SELECT * FROM kbo_playerlist_20250613 WHERE p_no IN (SELECT p_no FROM kbo_playerlist_20250613 GROUP BY p_no HAVING COUNT(*)>1)')->fetchAll();
    $report['flags'] = $db->query('SELECT isKbodle, COUNT(*) total FROM player_data GROUP BY isKbodle')->fetchAll();
    $columns = $db->query('SHOW COLUMNS FROM player_data')->fetchAll();
    $columns = array_filter($columns, static fn(array $c): bool => $c['Field'] !== 'id');
    $fields = array_map(static fn(array $c): string => '`' . str_replace('`', '``', $c['Field']) . '`', $columns);
    $checks = array_map(static fn(string $field): string => "COUNT(DISTINCT $field)>1", $fields);
    $report['source_conflicts'] = $db->query('SELECT playerId FROM player_data GROUP BY playerId HAVING ' . implode(' OR ', $checks))->fetchAll();
}
$pattern = '%player%';
foreach (['VIEWS' => 'VIEW_DEFINITION', 'TRIGGERS' => 'ACTION_STATEMENT', 'ROUTINES' => 'ROUTINE_DEFINITION', 'EVENTS' => 'EVENT_DEFINITION'] as $table => $column) {
    $schemaColumn = $table === 'TRIGGERS' ? 'TRIGGER_SCHEMA' : ($table === 'ROUTINES' ? 'ROUTINE_SCHEMA' : ($table === 'EVENTS' ? 'EVENT_SCHEMA' : 'TABLE_SCHEMA'));
    $stmt = $db->prepare("SELECT * FROM information_schema.$table WHERE $schemaColumn=DATABASE() AND `$column` LIKE ?");
    $stmt->execute([$pattern]);
    $report['dependencies'][$table] = $stmt->fetchAll();
}
$report['event_ddl'] = $db->query('SHOW CREATE EVENT KBODLE_GEN')->fetch();
$report['scheduler'] = $db->query("SHOW VARIABLES LIKE 'event_scheduler'")->fetch();
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
