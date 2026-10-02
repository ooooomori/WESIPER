<?php
// 운영 서버에서 수동 실행. 기존 행은 모두 1군(league_level=1)으로 보존한다.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function tableColumns(PDO $db, string $table): array
{
    return array_column($db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC), null, 'Field');
}

function tableIndexes(PDO $db, string $table): array
{
    $result = [];
    foreach ($db->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['Key_name']][] = $row;
    }
    return $result;
}

function indexColumns(array $rows): array
{
    usort($rows, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    return array_column($rows, 'Column_name');
}

$backupDir = '/home/bitnami/wesiper/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Schema backup directory could not be created.');
}
$backupPath = $backupDir . '/kbo-league-level-schema-before-' . gmdate('Ymd-His') . '.sql';
$schema = '';
foreach (['kbo_season_records', 'kbo_season_pitch_records', 'kbo_schedule'] as $table) {
    $row = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    $schema .= ($row[1] ?? '') . ";\n\n";
}
if (file_put_contents($backupPath, $schema, LOCK_EX) === false || !chmod($backupPath, 0600)) {
    throw new RuntimeException('Schema backup could not be written.');
}

foreach (['kbo_season_records', 'kbo_season_pitch_records', 'kbo_schedule'] as $table) {
    $columns = tableColumns($db, $table);
    if (!isset($columns['league_level'])) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `league_level` TINYINT UNSIGNED NOT NULL DEFAULT 1");
    }
    $db->exec("UPDATE `{$table}` SET `league_level`=1 WHERE `league_level` IS NULL");
    $db->exec("ALTER TABLE `{$table}` MODIFY COLUMN `league_level` TINYINT UNSIGNED NOT NULL DEFAULT 1");
}

// 퓨처스 외국인 선수명과 상세 타석 결과도 잘리지 않게 넓힌다.
$db->exec('ALTER TABLE `kbo_season_records` MODIFY COLUMN `player_name` VARCHAR(40) NULL');
$db->exec('ALTER TABLE `kbo_season_records` MODIFY COLUMN `pa_result` VARCHAR(20) NULL DEFAULT NULL');

$indexes = tableIndexes($db, 'kbo_season_records');
if (!isset($indexes['idx_league_game_team_batting_index'])) {
    $db->exec('ALTER TABLE `kbo_season_records` ADD INDEX `idx_league_game_team_batting_index` (`league_level`,`game_id`,`team`,`batting_index`)');
}
if (!isset($indexes['idx_league_player_date'])) {
    $db->exec('ALTER TABLE `kbo_season_records` ADD INDEX `idx_league_player_date` (`league_level`,`player_id`,`game_date`)');
}

$indexes = tableIndexes($db, 'kbo_season_pitch_records');
if (isset($indexes['uq_pitch_game_player'])
    && indexColumns($indexes['uq_pitch_game_player']) !== ['league_level', 'game_id', 'player_id']) {
    $db->exec('ALTER TABLE `kbo_season_pitch_records` DROP INDEX `uq_pitch_game_player`');
    unset($indexes['uq_pitch_game_player']);
}
if (!isset($indexes['uq_pitch_game_player'])) {
    $db->exec('ALTER TABLE `kbo_season_pitch_records` ADD UNIQUE KEY `uq_pitch_game_player` (`league_level`,`game_id`,`player_id`)');
}
if (!isset($indexes['idx_pitch_league_player_date'])) {
    $db->exec('ALTER TABLE `kbo_season_pitch_records` ADD INDEX `idx_pitch_league_player_date` (`league_level`,`player_id`,`game_date`)');
}
if (!isset($indexes['idx_pitch_league_game'])) {
    $db->exec('ALTER TABLE `kbo_season_pitch_records` ADD INDEX `idx_pitch_league_game` (`league_level`,`game_id`)');
}

$indexes = tableIndexes($db, 'kbo_schedule');
$primary = isset($indexes['PRIMARY']) ? indexColumns($indexes['PRIMARY']) : [];
if ($primary !== ['league_level', 'game_code']) {
    $db->exec('ALTER TABLE `kbo_schedule` DROP PRIMARY KEY, ADD PRIMARY KEY (`league_level`,`game_code`)');
}
if (!isset($indexes['idx_schedule_league_date'])) {
    $db->exec('ALTER TABLE `kbo_schedule` ADD INDEX `idx_schedule_league_date` (`league_level`,`game_date`)');
}
$columns = tableColumns($db, 'kbo_schedule');
if (!isset($columns['is_allstar'])) {
    $db->exec('ALTER TABLE `kbo_schedule` ADD COLUMN `is_allstar` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `league_level`');
}
$indexes = tableIndexes($db, 'kbo_schedule');
if (!isset($indexes['idx_schedule_league_allstar_date'])) {
    $db->exec('ALTER TABLE `kbo_schedule` ADD INDEX `idx_schedule_league_allstar_date` (`league_level`,`is_allstar`,`game_date`)');
}

foreach (['kbo_season_records', 'kbo_season_pitch_records', 'kbo_schedule'] as $table) {
    $bad = (int)$db->query("SELECT COUNT(*) FROM `{$table}` WHERE `league_level` NOT IN (1,2)")->fetchColumn();
    if ($bad !== 0) {
        throw new RuntimeException("{$table}: invalid league_level rows={$bad}");
    }
}

echo "KBO league-level schema ready; backup={$backupPath}\n";
