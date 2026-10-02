<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$report = ['columns' => $db->query('SHOW FULL COLUMNS FROM kbo_player_data')->fetchAll()];
if (in_array('p_career', array_column($report['columns'], 'Field'), true)) {
$report['career_values'] = $db->query("SELECT DISTINCT COALESCE(NULLIF(p_career,''),NULLIF(career,'')) career FROM kbo_player_data WHERE COALESCE(NULLIF(p_career,''),NULLIF(career,'')) IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$report['career_differences'] = $db->query("SELECT p_no,p_name,p_career,career FROM kbo_player_data WHERE COALESCE(p_career,'')<>COALESCE(career,'') AND career IS NOT NULL AND career<>'' LIMIT 30")->fetchAll();
} else {
    $report['counts'] = $db->query('SELECT COUNT(*) total,COUNT(DISTINCT player_id) unique_ids,COUNT(school) school_filled FROM kbo_player_data')->fetch();
    $report['schools'] = $db->query("SELECT player_id,name,school FROM kbo_player_data WHERE player_id IN (72234,54843,52295) OR school LIKE '%글로벌선진학교%' LIMIT 8")->fetchAll();
}
$report['event'] = $db->query("SELECT EVENT_NAME,DEFINER,EVENT_DEFINITION,STATUS,STARTS,INTERVAL_VALUE,INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() AND EVENT_NAME='KBODLE_GEN'")->fetch();
if (($argv[1] ?? '') === '--event') { echo json_encode($report['event'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL; exit; }
$report['foreign_keys'] = $db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='kbo_player_data'")->fetchAll();
$report['views'] = $db->query("SELECT TABLE_NAME,VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND VIEW_DEFINITION LIKE '%kbo_player_data%'")->fetchAll();
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
