<?php
// 일정표와 경기 기록에서 통째로 빠진 1군 경기 하나를 넣는다. 공식 KBO 박스스코어로 만든 계획 파일(data/missing-games/*.json)을 읽는다.
// 사용: php import-missing-game.php <DB 설정 파일> <계획 파일> [--apply]   (--apply 없이는 확인만 하고 쓰지 않는다)
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 3 || !is_file($argv[1]) || !is_file($argv[2])) {
    throw new RuntimeException('Usage: php import-missing-game.php DB_CONFIG PLAN.json [--apply]');
}
$apply = in_array('--apply', $argv, true);
$plan = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$schedule = $plan['schedule']; $batters = $plan['batters']; $pitchers = $plan['pitchers'];
$code = (string)$schedule['game_code'];
if (!preg_match('/^\d{8}[A-Z]{4}\d$/D', $code) || !$batters || !$pitchers) throw new RuntimeException('Invalid plan');
$gameId = (string)$batters[0]['game_id'];
foreach ([...$batters, ...$pitchers] as $row) {
    if ($row['game_id'] !== $gameId || !str_starts_with($gameId, $code) || $row['game_date'] !== $schedule['game_date'] || (int)$row['league_level'] !== 1 || (int)$row['player_id'] <= 0) {
        throw new RuntimeException('Plan row outside the game');
    }
}
// 타석의 득점 합계가 일정표 점수와 같아야 한다.
foreach (['away', 'home'] as $side) {
    $runs = array_sum(array_map(static fn($row) => $row['team'] === $schedule[$side . '_team'] ? (int)$row['r'] : 0, $batters));
    if ($runs !== (int)$schedule[$side . '_score']) throw new RuntimeException("Run total mismatch: $side");
}
$c = require $argv[1];
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['database']), $c['username'], $c['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$count = static function (string $table, string $column) use ($db, $code): int {
    $q = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE league_level=1 AND `$column` LIKE ?"); $q->execute([$code . '%']);
    return (int)$q->fetchColumn();
};
$existing = ['kbo_schedule' => $count('kbo_schedule', 'game_code'), 'kbo_season_records' => $count('kbo_season_records', 'game_id'), 'kbo_season_pitch_records' => $count('kbo_season_pitch_records', 'game_id')];
if (array_sum($existing) > 0) throw new RuntimeException('Game already present: ' . json_encode($existing));
$ids = array_values(array_unique(array_map('intval', [...array_column($batters, 'player_id'), ...array_column($pitchers, 'player_id'), ...array_filter(array_column($batters, 'pitcher_id'))])));
$known = $db->query('SELECT player_id FROM kbo_player_data WHERE player_id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN);
if ($missing = array_diff($ids, array_map('intval', $known))) throw new RuntimeException('Unknown players: ' . implode(',', $missing));
echo sprintf("%s %s %s %d - %d %s: schedule 1, batter rows %d, pitcher rows %d\n", $gameId, $schedule['game_date'], $schedule['away_team'], $schedule['away_score'], $schedule['home_score'], $schedule['home_team'], count($batters), count($pitchers));
if (!$apply) { echo "Dry run: nothing written (pass --apply to write)\n"; exit(0); }
$insert = static function (string $table, array $columns, array $rows) use ($db): void {
    $q = $db->prepare("INSERT INTO `$table` (" . implode(',', array_map(static fn($column) => "`$column`", $columns)) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach ($rows as $row) $q->execute(array_map(static fn($column) => $row[$column], $columns));
};
$db->beginTransaction();
try {
    $insert('kbo_schedule', ['league_level', 'game_code', 'game_date', 'away_team', 'home_team', 'away_score', 'home_score', 'tv', 'stadium', 'away_inning_scores', 'home_inning_scores', 'is_allstar'], [$schedule]);
    $insert('kbo_season_records', ['game_id', 'game_date', 'player_id', 'player_name', 'inning', 'pa_result', 'sb', 'cs', 'run_out', 'pitcher_id', 'pitcher_name', 'team', 'pos', 'rbi', 'r', 'is_gwrbi', 'order', 'is_gs', 'batting_index', 'league_level'], $batters);
    $insert('kbo_season_pitch_records', ['game_id', 'game_date', 'team', 'player_id', 'inning', 'record', 'pitched', 'order', 'er', 'r', 'league_level'], $pitchers);
    $written = ['kbo_schedule' => $count('kbo_schedule', 'game_code'), 'kbo_season_records' => $count('kbo_season_records', 'game_id'), 'kbo_season_pitch_records' => $count('kbo_season_pitch_records', 'game_id')];
    if ($written !== ['kbo_schedule' => 1, 'kbo_season_records' => count($batters), 'kbo_season_pitch_records' => count($pitchers)]) throw new RuntimeException('Verification failed: ' . json_encode($written));
    $db->commit();
    echo 'Written: ' . json_encode($written) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
