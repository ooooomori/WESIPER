<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply', '--verify'], true)) throw new InvalidArgumentException('Use --check, --apply or --verify');
$config = require ($argv[2] ?? __DIR__ . '/../backend/config/database.php');
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$players = $db->query('SELECT player_id,name,oldname,birth,team FROM kbo_player_data')->fetchAll();
$input = file($argv[3] ?? __DIR__ . '/../data/player-nicknames.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$rows = []; $issues = [];
foreach ($input as $line) {
    if (!preg_match('/^(.+?)(?:\((\d+)\))? - (.+)$/u', trim($line), $match)) throw new RuntimeException('Invalid input: ' . $line);
    [$all, $name, $id, $nicknames] = $match;
    $matches = array_values(array_filter($players, static fn($p) => $id !== '' ? (string)$p['player_id'] === $id && ($p['name'] === $name || $p['oldname'] === $name) : $p['name'] === $name || $p['oldname'] === $name));
    if (count($matches) !== 1) { $issues[] = ['name'=>$name,'id'=>$id,'candidates'=>$matches]; continue; }
    foreach (explode(',', $nicknames) as $nickname) {
        $nickname = trim($nickname);
        if ($nickname === '' || mb_strlen($nickname, 'UTF-8') > 100) throw new RuntimeException('Invalid nickname');
        $key = $matches[0]['player_id'] . ':' . $nickname;
        if (isset($rows[$key])) throw new RuntimeException('Duplicate nickname in input: ' . $key);
        $rows[$key] = ['player_id'=>(int)$matches[0]['player_id'], 'nickname'=>$nickname];
    }
}
if ($issues) { echo json_encode(['unresolved'=>$issues], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit(1); }
if ($mode === '--check') { echo json_encode(['players'=>count($input),'nicknames'=>count($rows),'rows'=>array_values($rows)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }
if ($mode === '--apply') {
    $db->exec('CREATE TABLE IF NOT EXISTS kbo_player_nicknames (`PK` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, player_id INT NOT NULL, nickname VARCHAR(100) NOT NULL, PRIMARY KEY (`PK`), UNIQUE KEY player_nickname (player_id,nickname), KEY nickname (nickname), CONSTRAINT fk_kbo_player_nicknames_player FOREIGN KEY (player_id) REFERENCES kbo_player_data (player_id) ON UPDATE RESTRICT ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->beginTransaction();
    try {
        $insert = $db->prepare('INSERT INTO kbo_player_nicknames (player_id,nickname) VALUES (?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname)');
        foreach ($rows as $row) $insert->execute([$row['player_id'], $row['nickname']]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
$check = $db->prepare('SELECT COUNT(*) FROM kbo_player_nicknames WHERE player_id=? AND BINARY nickname=BINARY ?');
foreach ($rows as $row) { $check->execute([$row['player_id'],$row['nickname']]); if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Nickname verification failed'); }
echo json_encode(['verified'=>count($rows),'players'=>count($input),'total'=>(int)$db->query('SELECT COUNT(*) FROM kbo_player_nicknames')->fetchColumn()], JSON_UNESCAPED_UNICODE);
