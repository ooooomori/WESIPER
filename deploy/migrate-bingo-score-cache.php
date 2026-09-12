<?php
// Run manually on the server; never deploy into the web document root.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE IF NOT EXISTS kbobingo_score_cache (
    grid_index INT NOT NULL PRIMARY KEY,
    calculated_at DATETIME NOT NULL
) ENGINE=InnoDB");
echo "Bingo score cache ready\n";
