<?php
// Manual deployment migration, outside the web document root.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE IF NOT EXISTS kbobingo_player_cache (
    p_no INT NOT NULL PRIMARY KEY,
    payload LONGTEXT NULL,
    attempted_date DATE NOT NULL,
    fetched_date DATE NULL
) ENGINE=InnoDB');
echo "Bingo player cache ready\n";
