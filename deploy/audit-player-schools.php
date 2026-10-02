<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../backend/lib/player-school.php';
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$table = 'kbo_player_data';
if (!in_array('p_no', $db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN),true)) {
    $state = json_decode(file_get_contents(__DIR__ . '/player-columns-state.json'),true,512,JSON_THROW_ON_ERROR);
    $table = $state['backup'];
}
$rows = $db->query('SELECT * FROM `' . str_replace('`','``',$table) . '`')->fetchAll();
$removed = [];
$changed = [];
$pairs = array_fill_keys(['name','oldname','pos','body','birth','career'], 0);
foreach ($rows as $row) {
    foreach (array_keys($pairs) as $field) if (longerPlayerValue($row[$field], $row['p_'.$field]) !== $row[$field]) $pairs[$field]++;
    $career = longerPlayerValue($row['career'], $row['p_career']);
    $school = playerSchoolOnly($career);
    if ($school !== playerSchoolOnly($school)) throw new RuntimeException('Non-idempotent school cleanup.');
    if ($school !== $career) $changed[] = $row['p_no'];
    foreach (explode('-', $career ?? '') as $token) {
        if ($token !== '' && ($school === null || strpos($school,$token) === false)) $removed[$token] = true;
    }
}
echo json_encode(['players'=>count($rows),'pair_changes'=>$pairs,'school_changed'=>count($changed),'removed_tokens'=>array_keys($removed)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
