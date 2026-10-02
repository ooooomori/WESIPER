<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? '--export';
if (!in_array($mode, ['--export', '--apply', '--verify'], true)) throw new InvalidArgumentException('Invalid mode');
$config = require $argv[2];
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$fields = ['bat', 'throw', 'draft', 'birth', 'body'];
$missing = implode(' OR ', array_map(static fn($f)=>"`$f` IS NULL OR TRIM(`$f`)=''", $fields));
if ($mode === '--export') {
    $rows = $db->query('SELECT player_id,name,oldname,`bat`,`throw`,draft,birth,body FROM kbo_player_data WHERE '.$missing.' ORDER BY player_id')->fetchAll();
    file_put_contents($argv[3], json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['players'=>count($rows), 'missing'=>array_combine($fields, array_map(static fn($f)=>count(array_filter($rows, static fn($r)=>trim((string)$r[$f])==='')), $fields))], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit;
}
$rows = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
$changes = [];
$catalog = [];
foreach ($db->query('SELECT * FROM kbo_player_data') as $player) $catalog[$player['player_id']] = $player;
foreach ($rows as $row) {
    $current = $catalog[$row['player_id']] ?? null;
    if (!$current) throw new RuntimeException('Missing player');
    if ($current['name'] !== $row['original_name']) throw new RuntimeException('Player identity changed: '.json_encode([$row['player_id'], $current['name'], $row['original_name']]));
    foreach ($fields as $field) {
        $value = $row['values'][$field] ?? null;
        if ($value !== null && $mode === '--apply' && (($field === 'bat' && !preg_match('/^[우좌양]타$/u', $value)) || ($field === 'throw' && !preg_match('/^[우좌양][투언사]$/u', $value)))) throw new RuntimeException('Invalid hand format: '.$row['player_id'].' '.$field);
        if ($value !== null && trim((string)$current[$field]) === '') $changes[] = [$field, $value, $row['player_id']];
        if ($mode === '--verify' && $value !== null && $current[$field] !== $value) throw new RuntimeException('Verification mismatch: '.$row['player_id'].' '.$field);
    }
}
$backup = null; $updated = 0;
if ($mode === '--verify' && isset($argv[4])) {
    $verifyBackup = $argv[4];
    if (!preg_match('/^kbo_player_data_backup_profile_[0-9_]+$/', $verifyBackup)) throw new RuntimeException('Invalid backup');
    $columns = $db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN);
    $unexpected = [];
    foreach ($columns as $column) {
        $different = "NOT (p.`$column` <=> b.`$column`)";
        if (in_array($column, $fields, true)) $different = "($different AND b.`$column` IS NOT NULL AND TRIM(b.`$column`)<>'')";
        $unexpected[] = $different;
    }
    $count = (int)$db->query("SELECT COUNT(*) FROM kbo_player_data p JOIN `$verifyBackup` b ON p.player_id=b.player_id WHERE ".implode(' OR ', $unexpected))->fetchColumn();
    if ($count) throw new RuntimeException('Unexpected changes to existing values: '.$count);
    if ($db->query('SELECT COUNT(*) FROM kbo_player_data')->fetchColumn() != $db->query("SELECT COUNT(*) FROM `$verifyBackup`")->fetchColumn()) throw new RuntimeException('Player count changed');
}
if ($mode === '--apply' && $changes) {
    $backup = 'kbo_player_data_backup_profile_'.gmdate('Ymd_His');
    $db->exec("CREATE TABLE `$backup` LIKE kbo_player_data");
    $db->exec("INSERT INTO `$backup` SELECT * FROM kbo_player_data");
    $db->beginTransaction();
    try {
        foreach ($fields as $field) {
            $fieldChanges = array_values(array_filter($changes, static fn($c)=>$c[0]===$field));
            foreach (array_chunk($fieldChanges, 300) as $chunk) {
                $cases = []; $params = []; $ids = [];
                foreach ($chunk as [, $value, $pid]) {
                    $cases[] = 'WHEN ? THEN ?'; $params[] = $pid; $params[] = $value; $ids[] = $pid;
                }
                $stmt = $db->prepare("UPDATE kbo_player_data SET `$field`=CASE player_id ".implode(' ', $cases)." ELSE `$field` END WHERE player_id IN (".implode(',',array_fill(0,count($ids),'?')).") AND (`$field` IS NULL OR TRIM(`$field`)='')");
                $stmt->execute(array_merge($params, $ids)); $updated += $stmt->rowCount();
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
echo json_encode(['mode'=>$mode, 'planned_cells'=>count($changes), 'updated_cells'=>$updated, 'backup'=>$backup, 'remaining_players'=>(int)$db->query('SELECT COUNT(*) FROM kbo_player_data WHERE '.$missing)->fetchColumn()], JSON_UNESCAPED_UNICODE).PHP_EOL;
