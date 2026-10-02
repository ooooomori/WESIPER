<?php
// kbo_schedule에서 퓨처스 올스타전(남부/북부)을 일반 경기와 구분한다.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$columns = array_column(
    $db->query('SHOW COLUMNS FROM `kbo_schedule`')->fetchAll(PDO::FETCH_ASSOC),
    null,
    'Field'
);
if (!isset($columns['is_allstar'])) {
    $db->exec(
        'ALTER TABLE `kbo_schedule` '
        . 'ADD COLUMN `is_allstar` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `league_level`'
    );
}
$db->exec(
    "UPDATE `kbo_schedule` SET `is_allstar` = CASE "
    . "WHEN `league_level`=2 AND (`away_team` IN ('남부','북부') OR `home_team` IN ('남부','북부')) THEN 1 "
    . "ELSE 0 END"
);

$indexes = $db->query('SHOW INDEX FROM `kbo_schedule`')->fetchAll(PDO::FETCH_ASSOC);
$indexNames = array_unique(array_column($indexes, 'Key_name'));
if (!in_array('idx_schedule_league_allstar_date', $indexNames, true)) {
    $db->exec(
        'ALTER TABLE `kbo_schedule` '
        . 'ADD INDEX `idx_schedule_league_allstar_date` (`league_level`,`is_allstar`,`game_date`)'
    );
}

$bad = (int)$db->query(
    "SELECT COUNT(*) FROM `kbo_schedule` WHERE `is_allstar` NOT IN (0,1)"
)->fetchColumn();
if ($bad !== 0) {
    throw new RuntimeException("kbo_schedule: invalid is_allstar rows={$bad}");
}

echo "kbo_schedule.is_allstar ready\n";
