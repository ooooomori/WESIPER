<?php
// Add the per-plate-appearance baserunning-out count without changing existing rows.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$columns = array_column(
    $db->query('SHOW COLUMNS FROM `kbo_season_records`')->fetchAll(PDO::FETCH_ASSOC),
    null,
    'Field'
);
if (!isset($columns['run_out'])) {
    $db->exec(
        'ALTER TABLE `kbo_season_records` '
        . 'ADD COLUMN `run_out` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `cs`'
    );
}

$columns = array_column(
    $db->query('SHOW COLUMNS FROM `kbo_season_records`')->fetchAll(PDO::FETCH_ASSOC),
    null,
    'Field'
);
if (!isset($columns['run_out'])
    || strtoupper((string) $columns['run_out']['Null']) !== 'NO'
    || (string) $columns['run_out']['Default'] !== '0') {
    throw new RuntimeException('kbo_season_records.run_out migration verification failed.');
}

echo "kbo_season_records.run_out is ready.\n";
