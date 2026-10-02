<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$dir='/home/bitnami/wesiper-weather-preview/';
$source=json_decode(file_get_contents($dir.'olympic-rosters.json'),true,512,JSON_THROW_ON_ERROR);
$reviewed=json_decode(file_get_contents($dir.'olympic-reviewed-1996-2008.json'),true,512,JSON_THROW_ON_ERROR);
$expected=[1996=>20,2000=>24,2008=>24];
$lookup=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='national' AND type='올림픽' AND year=?");
$rows=[];
foreach ($source as $event) {
    $year=(int)$event['year'];
    if (!isset($expected[$year])) continue;
    $map=$reviewed[(string)$year]??null;
    if (!$map || count($event['players'])!==$expected[$year] || count($map)!==$expected[$year]) throw new RuntimeException('Roster count mismatch '.$year);
    $seen=[];
    foreach ($event['players'] as $player) {
        $name=$player['name'];
        if (!isset($map[$name]) || isset($seen[$name])) throw new RuntimeException('Roster name mismatch '.$year.' '.$name);
        $seen[$name]=true;
        $id=(int)$map[$name];
        $lookup->execute([$id]);
        $db=$lookup->fetch(PDO::FETCH_ASSOC);
        if (!$db || !in_array($name,[$db['name'],$db['oldname']],true)) throw new RuntimeException('Player ID mismatch '.$year.' '.$name);
        if (preg_match('/^(\d{4})/',(string)$db['birth'],$birth) && (int)$birth[1]>$year-14) throw new RuntimeException('Birth year mismatch '.$year.' '.$name);
        $exists->execute([$id,$year]);
        if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Olympic career already exists '.$year.' '.$name);
        $rows[]=[$id,$year];
    }
    if (count($seen)!==count($map)) throw new RuntimeException('Reviewed names differ from source '.$year);
}
if (count($rows)!==68) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_olympics';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);
if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup already exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup verification failed');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'national','올림픽',NULL,?,NULL,NULL,NULL)");
$pdo->beginTransaction();
try {
    foreach ($rows as $row) $insert->execute($row);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+68) throw new RuntimeException('Post-write count mismatch');
echo json_encode(['backup'=>$backup,'added'=>68,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
