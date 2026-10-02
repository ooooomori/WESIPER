<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') { exit(1); }
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$events = json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/national-kbo-official.json'), true, 512, JSON_THROW_ON_ERROR);
$expected = ['프리미어12:2019'=>28,'프리미어12:2024'=>28,'APBC:2017'=>25,'APBC:2023'=>26,'아시안게임:2018'=>24,'아시안게임:2026'=>24];
$rows = [];
$lookup = $pdo->prepare('SELECT name,oldname FROM kbo_player_data WHERE player_id=?');
foreach ($events as $event) {
    $key = $event['event'];
    if (!isset($expected[$key])) { continue; }
    if (isset($event['error']) || count($event['players']) !== $expected[$key]) {
        throw new RuntimeException('Unverified event: '.$key);
    }
    [$type,$year] = explode(':', $key, 2);
    $seen = [];
    foreach ($event['players'] as $player) {
        $id = (int)$player['player_id'];
        if (isset($seen[$id])) { throw new RuntimeException('Duplicate roster ID: '.$key.' '.$id); }
        $seen[$id] = true;
        $lookup->execute([$id]);
        $dbPlayer = $lookup->fetch(PDO::FETCH_ASSOC);
        $normalize = fn($name) => preg_replace('/\s+/u', '', (string)$name);
        if (!$dbPlayer || !in_array($normalize($player['name']),
            [$normalize($dbPlayer['name']),$normalize($dbPlayer['oldname'])], true)) {
            throw new RuntimeException('Player identity mismatch: '.$key.' '.$id);
        }
        $rows[] = [$id,$type,(int)$year];
    }
}
if (count($rows) !== array_sum($expected)) { throw new RuntimeException('Roster total mismatch'); }

// Snapshot the current table before changing either its constraints or data.
$backup = 'kbo_player_career_backup_20260930_national';
$check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
$check->execute([$backup]);
if ((int)$check->fetchColumn() !== 0) { throw new RuntimeException('Backup table already exists'); }
$before = (int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
$copied = (int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn();
if ($copied !== $before) { throw new RuntimeException('Backup row count mismatch'); }

$pdo->exec("ALTER TABLE kbo_player_career DROP CONSTRAINT chk_career_national, ADD CONSTRAINT chk_career_national CHECK ((category='national' AND type IN ('WBC','프리미어12','APBC','아시안게임','올림픽') AND team IS NULL AND month IS NULL AND pos IS NULL AND note IS NULL) OR (category='award' AND type IN ('골든글러브','MVP','올스타','신인왕','수비상','월간 MVP','한국시리즈 MVP') AND team IS NOT NULL AND year IS NOT NULL))");

$exists = $pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='national' AND type=? AND year=?");
$insert = $pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'national',?,NULL,?,NULL,NULL,NULL)");
$added = 0;
$pdo->beginTransaction();
try {
    foreach ($rows as [$id,$type,$year]) {
        $exists->execute([$id,$type,$year]);
        if ((int)$exists->fetchColumn() !== 0) { continue; }
        $insert->execute([$id,$type,$year]);
        $added++;
    }
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}
$after = (int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after !== $before+$added) { throw new RuntimeException('Post-write count mismatch'); }
echo json_encode(['backup'=>$backup,'before'=>$before,'added'=>$added,'after'=>$after], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), PHP_EOL;
