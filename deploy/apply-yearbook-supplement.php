<?php
/*
 * KBO 기록대백과(2015)에서 읽은 1982~2000년 숫자를 기존 시즌 합계 표의 빈 항목에 보충한다.
 *   타자: 득점·루타·희타·희비·고의4구가 NULL이면 채운다. 정규시즌 고의4구는 수집 단계에서 0으로 들어간 값만 덮어쓴다.
 *   투수: 표에 없던 gs·gf·rw·ab·sh·sf·ibb·wp·bk 열을 더하고 채운다.
 *   포지션별 출장수: kbo_player_season_position_games 표(1993~2000 정규시즌 타자)를 만들어 넣는다.
 * 이미 값이 있는 열은 건드리지 않는다. 같은 파일로 다시 실행해도 결과가 같다.
 * 사용법: php apply-yearbook-supplement.php --check|--apply <data.json> [database-config.php]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = '--check'; $file = null; $config = null;
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--check', '--apply'], true)) $mode = $arg;
    elseif ($file === null) $file = $arg;
    else $config = $arg;
}
if ($file === null) { fwrite(STDERR, "data.json required\n"); exit(2); }
$root = dirname(__DIR__);
$data = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$c = require ($config ?? (getenv('WESIPER_DB_CONFIG') ?: "$root/backend/config/database.php"));
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['database']), $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('SET SESSION lock_wait_timeout=10');
$apply = $mode === '--apply';
$report = ['mode' => $mode, 'source' => $data['source'] ?? ''];

$hasColumn = static fn(string $table, string $column): bool => (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$table' AND column_name='$column'")->fetchColumn();
$hasTable = static fn(string $table): bool => (bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$table'")->fetchColumn();

$bat = 'kbo_player_season_batting_totals';
$pit = 'kbo_player_season_pitching_totals';
$pos = 'kbo_player_season_position_games';
$stamp = date('Ymd');

// 1. 백업
foreach ([$bat, $pit] as $table) {
    $backup = "{$table}_bak_$stamp";
    $report['backup'][$table] = $hasTable($backup) ? 'exists' : ($apply ? 'created' : 'would create');
    if ($apply && !$hasTable($backup)) $db->exec("CREATE TABLE `$backup` AS SELECT * FROM `$table`");
}

// 2. 투수 표 열 추가
$newPitchCols = ['gs', 'gf', 'rw', 'ab', 'sh', 'sf', 'ibb', 'wp', 'bk'];
$missing = array_values(array_filter($newPitchCols, static fn($col) => !$hasColumn($pit, $col)));
$report['pitching_columns_to_add'] = $missing;
if ($apply && $missing) {
    $db->exec("ALTER TABLE `$pit` " . implode(', ', array_map(static fn($col) => "ADD COLUMN `$col` INT UNSIGNED NULL", $missing)));
}

// 3. 타자: 빈 값 채우기
$counts = ['rows' => 0, 'no_row' => 0, 'filled' => [], 'ibb_overwrite' => 0];
$find = $db->prepare("SELECT r, tb, sh, sf, ibb FROM `$bat` WHERE league_level=1 AND year=? AND series_id=? AND player_id=? AND row_scope='total'");
foreach ($data['batting'] as [$year, $series, $pid, $values]) {
    $find->execute([$year, $series, $pid]);
    $row = $find->fetch();
    if (!$row) { $counts['no_row']++; continue; }
    $counts['rows']++;
    $set = [];
    foreach (['r', 'tb', 'sh', 'sf', 'ibb'] as $col) {
        if (!array_key_exists($col, $values)) continue;
        $current = $row[$col];
        if ($current === null) { $set[$col] = $values[$col]; $counts['filled'][$col] = ($counts['filled'][$col] ?? 0) + 1; }
        elseif ($col === 'ibb' && $series === 0 && (int)$current === 0 && (int)$values[$col] > 0) { $set[$col] = $values[$col]; $counts['ibb_overwrite']++; }
    }
    if ($apply && $set) {
        $sql = "UPDATE `$bat` SET " . implode(', ', array_map(static fn($col) => "`$col`=?", array_keys($set))) . " WHERE league_level=1 AND year=? AND series_id=? AND player_id=? AND row_scope='total'";
        $db->prepare($sql)->execute([...array_values($set), $year, $series, $pid]);
    }
}
$report['batting'] = $counts;

// 4. 투수: 새 열 채우기(열이 없으면 건수만 센다)
$counts = ['rows' => 0, 'no_row' => 0, 'would_fill_rows' => 0];
$find = $db->prepare("SELECT 1 FROM `$pit` WHERE league_level=1 AND year=? AND series_id=? AND player_id=? AND row_scope='total'");
foreach ($data['pitching'] as [$year, $series, $pid, $values]) {
    $find->execute([$year, $series, $pid]);
    if (!$find->fetch()) { $counts['no_row']++; continue; }
    $counts['rows']++;
    if (!$apply) continue;
    $cols = array_keys($values);
    $sql = "UPDATE `$pit` SET " . implode(', ', array_map(static fn($col) => "`$col`=COALESCE(`$col`, ?)", $cols)) . " WHERE league_level=1 AND year=? AND series_id=? AND player_id=? AND row_scope='total'";
    $db->prepare($sql)->execute([...array_values($values), $year, $series, $pid]);
}
$report['pitching'] = $counts;

// 5. 포지션별 출장수 표
$playerIdType = $db->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='kbo_player_data' AND column_name='player_id'")->fetchColumn();
if (!$playerIdType) throw new RuntimeException('kbo_player_data.player_id not found');
$exists = $hasTable($pos);
$report['positions'] = ['table_exists' => $exists, 'rows' => count($data['positions'])];
if ($apply) {
    if (!$exists) {
        $db->exec("CREATE TABLE `$pos` (
            year SMALLINT UNSIGNED NOT NULL COMMENT '시즌(정규시즌)',
            player_id $playerIdType NOT NULL COMMENT '선수',
            position VARCHAR(3) NOT NULL COMMENT '수비 위치(C, 1B, 2B, 3B, SS, OF)',
            games SMALLINT UNSIGNED NOT NULL COMMENT '해당 위치 출장 경기 수',
            source VARCHAR(40) NOT NULL COMMENT '출처',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (year, player_id, position),
            KEY idx_position_games_player (player_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='1993~2000 타자의 위치별 출장수(기록대백과). 경기 기록이 없는 시즌용'");
    }
    $insert = $db->prepare("INSERT INTO `$pos` (year, player_id, position, games, source) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE games=VALUES(games), source=VALUES(source)");
    $db->beginTransaction();
    foreach ($data['positions'] as [$year, $pid, $position, $games]) $insert->execute([$year, $pid, $position, $games, 'KBO 기록대백과 2015']);
    $db->commit();
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
