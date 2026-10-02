<?php
// 운영 서버에서 수동 실행. 웹 문서 루트에 배포하지 않습니다.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function columnsByName(PDO $db, string $table): array
{
    $columns = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    return array_column($columns, null, 'Field');
}

function indexesByName(PDO $db, string $table): array
{
    $indexes = $db->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    return array_column($indexes, null, 'Key_name');
}

$columns = columnsByName($db, 'kbo_season_records');
$additions = [
    'league_level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
    'pitcher_id' => 'INT UNSIGNED NULL',
    'pitcher_name' => 'VARCHAR(20) NULL',
    'team' => 'VARCHAR(10) NULL',
    'rbi' => 'SMALLINT UNSIGNED NULL',
    'r' => 'SMALLINT UNSIGNED NULL',
    'run_out' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    'order' => 'TINYINT UNSIGNED NULL',
    'is_gs' => 'TINYINT(1) UNSIGNED NULL',
    'batting_index' => 'INT UNSIGNED NULL',
];

foreach ($additions as $name => $definition) {
    if (!isset($columns[$name])) {
        $db->exec("ALTER TABLE `kbo_season_records` ADD COLUMN `{$name}` {$definition}");
    }
}

// 대주자처럼 타석이 없는 출장 기록은 두 값을 모두 NULL로 저장한다.
$columns = columnsByName($db, 'kbo_season_records');
if (!isset($columns['pa_result'])) {
    throw new RuntimeException('kbo_season_records.pa_result 컬럼이 없습니다.');
}
if (strtoupper((string) $columns['pa_result']['Null']) !== 'YES') {
    $paResultType = $columns['pa_result']['Type'];
    $db->exec("ALTER TABLE `kbo_season_records` MODIFY COLUMN `pa_result` {$paResultType} NULL DEFAULT NULL");
}
if (strtoupper((string) $columns['batting_index']['Null']) !== 'YES') {
    $db->exec('ALTER TABLE `kbo_season_records` MODIFY COLUMN `batting_index` INT UNSIGNED NULL DEFAULT NULL');
}

$indexes = indexesByName($db, 'kbo_season_records');
if (!isset($indexes['idx_game_team_batting_index'])) {
    $db->exec(
        'ALTER TABLE `kbo_season_records` '
        . 'ADD INDEX `idx_game_team_batting_index` (`game_id`, `team`, `batting_index`)'
    );
}

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `kbo_season_pitch_records` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` VARCHAR(20) NOT NULL,
    `game_date` DATE NOT NULL,
    `team` VARCHAR(10) NOT NULL,
    `player_id` INT UNSIGNED NOT NULL,
    `inning` VARCHAR(10) NOT NULL,
    `record` VARCHAR(4) NULL,
    `pitched` SMALLINT UNSIGNED NOT NULL,
    `order` TINYINT UNSIGNED NULL,
    `er` SMALLINT UNSIGNED NULL,
    `r` SMALLINT UNSIGNED NULL,
    `league_level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pitch_game_player` (`league_level`, `game_id`, `player_id`),
    KEY `idx_pitch_player_date` (`player_id`, `game_date`),
    KEY `idx_pitch_game` (`game_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$pitchColumns = columnsByName($db, 'kbo_season_pitch_records');
$pitchAdditions = [
    'league_level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
    'order' => 'TINYINT UNSIGNED NULL',
    'er' => 'SMALLINT UNSIGNED NULL',
    'r' => 'SMALLINT UNSIGNED NULL',
];
foreach ($pitchAdditions as $name => $definition) {
    if (!isset($pitchColumns[$name])) {
        $db->exec("ALTER TABLE `kbo_season_pitch_records` ADD COLUMN `{$name}` {$definition}");
    }
}

$columns = columnsByName($db, 'kbo_season_records');
foreach (array_keys($additions) as $name) {
    if (!isset($columns[$name])) {
        throw new RuntimeException("kbo_season_records.{$name} 컬럼 생성에 실패했습니다.");
    }
}
foreach (['pa_result', 'batting_index'] as $name) {
    if (strtoupper((string) $columns[$name]['Null']) !== 'YES') {
        throw new RuntimeException("kbo_season_records.{$name} 컬럼이 NULL을 허용하지 않습니다.");
    }
}

$indexes = indexesByName($db, 'kbo_season_records');
if (!isset($indexes['idx_game_team_batting_index'])) {
    throw new RuntimeException('idx_game_team_batting_index 인덱스 생성에 실패했습니다.');
}

$pitchColumns = columnsByName($db, 'kbo_season_pitch_records');
foreach (['id', 'game_id', 'game_date', 'team', 'player_id', 'inning', 'record', 'pitched', 'order', 'er', 'r', 'league_level'] as $name) {
    if (!isset($pitchColumns[$name])) {
        throw new RuntimeException("kbo_season_pitch_records.{$name} 컬럼 생성에 실패했습니다.");
    }
}

echo "KBO season record schemas ready\n";
