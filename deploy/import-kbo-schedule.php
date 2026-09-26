<?php
// Run manually over SSH, outside the public document root.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 2 || !is_file($argv[1])) {
    throw new RuntimeException('Usage: php import-kbo-schedule.php schedule.sqlite3');
}
$source = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$columns = 'game_code,game_date,away_team,home_team,away_score,home_score,tv,stadium';
$rows = $source->query("SELECT $columns FROM kbo_schedule ORDER BY game_date,game_code")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) throw new RuntimeException('Empty source');
foreach ($rows as $row) {
    if ($row['game_date'] < '2026-03-01' || $row['game_date'] >= '2026-11-01'
        || !preg_match('/^2026[0-9]{4}[A-Z]{4}[0-9]$/', $row['game_code'])
        || substr($row['game_code'], 0, 8) !== str_replace('-', '', $row['game_date'])) {
        throw new RuntimeException('Invalid source row');
    }
}
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO("mysql:host={$config['host']};port=" . ($config['port'] ?? 3306) . ";dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $db->query("SHOW TABLES LIKE 'kbo_schedule'")->fetchColumn();
if ($exists) {
    $backup = __DIR__ . '/kbo_schedule-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $oldRows = $db->query('SELECT * FROM kbo_schedule')->fetchAll(PDO::FETCH_ASSOC);
    if (file_put_contents($backup, json_encode($oldRows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('Backup failed');
    }
    chmod($backup, 0600);
    echo 'Backup: ' . $backup . PHP_EOL;
}
$db->exec('CREATE TABLE IF NOT EXISTS kbo_schedule (
    game_code VARCHAR(32) PRIMARY KEY, game_date DATE NOT NULL,
    away_team VARCHAR(40) NOT NULL, home_team VARCHAR(40) NOT NULL,
    away_score INTEGER NULL, home_score INTEGER NULL,
    tv VARCHAR(255) NOT NULL, stadium VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kbo_schedule'")->fetchColumn();
if (strtoupper($engine) !== 'INNODB') throw new RuntimeException('Transactional table required');
$db->beginTransaction();
try {
    $db->exec("DELETE FROM kbo_schedule WHERE game_date >= '2026-03-01' AND game_date < '2026-11-01'");
    $insert = $db->prepare("INSERT INTO kbo_schedule ($columns) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($rows as $row) $insert->execute(array_values($row));
    $actual = $db->query("SELECT $columns FROM kbo_schedule WHERE game_date >= '2026-03-01' AND game_date < '2026-11-01' ORDER BY game_date,game_code")->fetchAll(PDO::FETCH_ASSOC);
    if ($actual != $rows) throw new RuntimeException('Stored data does not match source');
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
echo json_encode(['saved' => count($actual), 'without_scores' => count(array_filter($actual,
    fn($r) => $r['away_score'] === null && $r['home_score'] === null)),
    'first_date' => $actual[0]['game_date'], 'last_date' => $actual[count($actual)-1]['game_date']], JSON_THROW_ON_ERROR) . PHP_EOL;
