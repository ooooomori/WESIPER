<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$events=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/national-additional.json'),true,512,JSON_THROW_ON_ERROR);
$expected=['아시안게임:2023'=>23,'올림픽:2020'=>24];
if (count($events)!==2) throw new RuntimeException('Event count mismatch');
$lookup=$pdo->prepare('SELECT name,oldname FROM kbo_player_data WHERE player_id=?');
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='national' AND type=? AND year=?");
$rows=[];
foreach ($events as $event) {
    $key=$event['event'];
    if (!isset($expected[$key]) || count($event['players'])!==$expected[$key]) throw new RuntimeException('Unverified event '.$key);
    [$type,$year]=explode(':',$key,2);
    $seen=[];
    foreach ($event['players'] as $player) {
        $id=(int)$player['player_id'];
        if (isset($seen[$id])) throw new RuntimeException('Duplicate player');
        $seen[$id]=true;
        $lookup->execute([$id]);
        $db=$lookup->fetch(PDO::FETCH_ASSOC);
        if (!$db || !in_array($player['name'],[$db['name'],$db['oldname']],true)) throw new RuntimeException('Identity mismatch '.$key.' '.$id);
        $exists->execute([$id,$type,(int)$year]);
        if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Career already exists '.$key.' '.$id);
        $rows[]=[$id,$type,(int)$year];
    }
}
if (count($rows)!==47) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_additional';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);
if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup already exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup verification failed');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'national',?,NULL,?,NULL,NULL,NULL)");
$pdo->beginTransaction();
try {
    foreach ($rows as $row) $insert->execute($row);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+47) throw new RuntimeException('Post-write mismatch');
echo json_encode(['backup'=>$backup,'added'=>47,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
