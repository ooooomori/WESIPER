<?php
declare(strict_types=1);
// php deploy/remove-player-career-note-check.php --apply /path/to/database-config.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require ($argv[2] ?? dirname(__DIR__).'/backend/config/database.php');
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET SESSION lock_wait_timeout=15');
$constraints = static function () use ($db): array {
    return $db->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='kbo_player_career' ORDER BY CONSTRAINT_NAME")->fetchAll();
};
$before = $constraints();
$note = array_values(array_filter($before, static fn($r)=>$r['CONSTRAINT_NAME']==='chk_career_note'));
if (!in_array('--apply', $argv, true)) { echo json_encode(['present'=>(bool)$note,'mode'=>'preview']).PHP_EOL; exit; }
if ($note) {
    $directory = '/home/bitnami/deploy-backups';
    $backup = $directory.'/career-note-check-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
    $ddl = $db->query('SHOW CREATE TABLE kbo_player_career')->fetch();
    if (file_put_contents($backup, json_encode(['ddl'=>$ddl,'checks'=>$before], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Schema backup failed');
    chmod($backup, 0600);
    $db->exec('ALTER TABLE kbo_player_career DROP CONSTRAINT chk_career_note');
}
$after = $constraints();
$expected = array_values(array_filter($before, static fn($r)=>$r['CONSTRAINT_NAME']!=='chk_career_note'));
if ($after !== $expected) throw new RuntimeException('Unexpected constraint change');
// LIKE keeps CHECK rules; a temporary copy verifies free text without adding live records.
$db->exec('CREATE TEMPORARY TABLE career_note_check_probe LIKE kbo_player_career');
$q = $db->prepare("INSERT INTO career_note_check_probe (player_id,category,type,team,country,year,month,pos,note) VALUES (78726,'national','올림픽',NULL,'호주',2000,NULL,NULL,?)");
foreach (['7위','임의의 메모'] as $value) $q->execute([$value]);
$db->exec('DROP TEMPORARY TABLE career_note_check_probe');
echo json_encode(['removed'=>(bool)$note,'note'=>'free text','otherChecksUnchanged'=>true,'temporaryInsertTests'=>2],JSON_UNESCAPED_UNICODE).PHP_EOL;
