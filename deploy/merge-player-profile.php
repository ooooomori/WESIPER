<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$helper = __DIR__ . '/../backend/lib/player-school.php';
require is_file($helper) ? $helper : '/opt/bitnami/apache/htdocs/lib/player-school.php';
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// Historic duplicates are usable only when their nonempty values agree.
$aggregate = static function (string $field): string {
    $value = "NULLIF(TRIM(`$field`),'')";
    if ($field === 'Body') $value = "NULLIF($value,'cm, kg')";
    return "CASE WHEN COUNT(DISTINCT $value)=1 THEN MAX($value) ELSE NULL END";
};
$historic = 'SELECT ID,' . $aggregate('Body') . ' body,' . $aggregate('Birth') . ' birth FROM kbo_playerlist_20240123_2 WHERE ID IS NOT NULL GROUP BY ID';
$rows = $pdo->query("SELECT p.player_id,p.body,p.birth,p.school,h.body historic_body,h.birth historic_birth FROM kbo_player_data p LEFT JOIN ($historic) h ON h.ID=p.player_id")->fetchAll();
$changes = [];
foreach ($rows as $row) {
    $body = longerPlayerValue($row['body'], $row['historic_body']);
    $birth = longerPlayerValue($row['birth'], $row['historic_birth']);
    $school = playerSchoolOnly($row['school']);
    if ($body !== $row['body'] || $birth !== $row['birth'] || $school !== $row['school']) $changes[] = [$body,$birth,$school,$row['player_id']];
}
echo 'Players: ' . count($rows) . '; profile changes: ' . count($changes) . PHP_EOL;
if (($argv[1] ?? '') !== '--apply' || !$changes) exit;
$backup = 'kbo_player_data_backup_profile_' . date('Ymd_His');
$pdo->exec("CREATE TABLE `$backup` LIKE kbo_player_data");
$pdo->exec("INSERT INTO `$backup` SELECT * FROM kbo_player_data");
echo "Backup: $backup\n";
$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE kbo_player_data SET body=?,birth=?,school=? WHERE player_id=?');
    foreach ($changes as $values) $update->execute($values);
    $pdo->commit();
    echo 'Updated rows: ' . count($changes) . PHP_EOL;
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
