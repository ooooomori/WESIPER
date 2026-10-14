<?php
// 2026년 개인 타이틀 수상자를 kbo_player_titleholder에 넣는다. --check(기본) | --apply. 마지막 인자는 DB 설정 파일 경로.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$args = array_slice($argv, 1);
$mode = in_array('--apply', $args, true) ? '--apply' : '--check';
$configPath = end($args);
$config = require (is_string($configPath) && str_ends_with($configPath, '.php') ? $configPath : __DIR__ . '/../backend/config/database.php');
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

const YEAR = 2026;
// [선수 ID, 이름, 타이틀, 기록]
$titles = [
    [62404, '구자욱', '타율', '0.371'],
    [54529, '레이예스', '안타', '211.000'],
    [52605, '김도영', '홈런', '45.000'],
    [56034, '힐리어드', '타점', '139.000'],
    [66606, '최원준', '득점', '125.000'],
    [50500, '황성빈', '도루', '55.000'],
    [62404, '구자욱', '출루율', '0.458'],
    [52605, '김도영', '장타율', '0.647'],
    [64001, '고영표', '다승', '15.000'],
    [61101, '임찬규', '다승', '15.000'],
    [68220, '곽빈', '평균자책점', '2.240'],
    [68220, '곽빈', '탈삼진', '197.000'],
    [65062, '김재윤', '세이브', '35.000'],
    [56011, '스기모토', '홀드', '25.000'],
    [50030, '소형준', '승률', '0.769'],
];

$name = $db->prepare('SELECT name FROM kbo_player_data WHERE player_id=?');
foreach ($titles as [$id, $expected]) {
    $name->execute([$id]);
    $actual = (string)$name->fetchColumn();
    if ($actual === '' || !str_contains($actual, $expected)) throw new RuntimeException("Player $id is '$actual', expected $expected.");
}
$existing = $db->prepare('SELECT player_id,type,record FROM kbo_player_titleholder WHERE year=? ORDER BY PK');
$existing->execute([YEAR]);
$before = $existing->fetchAll();
echo json_encode(['mode' => $mode, 'existing2026' => $before, 'total' => (int)$db->query('SELECT COUNT(*) FROM kbo_player_titleholder')->fetchColumn()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
if ($before) throw new RuntimeException('2026 rows already exist; review before inserting.');
if ($mode !== '--apply') exit;

$db->beginTransaction();
$insert = $db->prepare('INSERT INTO kbo_player_titleholder (player_id,type,year,record) VALUES (?,?,?,?)');
foreach ($titles as [$id, , $type, $record]) $insert->execute([$id, $type, YEAR, $record]);
$db->commit();
$existing->execute([YEAR]);
echo json_encode(['inserted' => $existing->fetchAll(), 'total' => (int)$db->query('SELECT COUNT(*) FROM kbo_player_titleholder')->fetchColumn()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
