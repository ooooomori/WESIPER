<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$dir='/home/bitnami/wesiper-weather-preview/';
$source=json_decode(file_get_contents($dir.'olympic-rosters.json'),true,512,JSON_THROW_ON_ERROR);
$reviewed=json_decode(file_get_contents($dir.'olympic-reviewed-1984-1988.json'),true,512,JSON_THROW_ON_ERROR);
$expected=[1984=>20,1988=>20];
$excluded=[1984=>['강기문'],1988=>['권택재','백재우','이석재']];
$lookup=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='national' AND type='올림픽' AND year=?");
$rows=[];
foreach ($source as $event) {
  $year=(int)$event['year'];if (!isset($expected[$year])) continue;
  $map=$reviewed[(string)$year]??null;
  if (!$map || count($event['players'])!==$expected[$year] || count($map)!==$expected[$year]-count($excluded[$year])) throw new RuntimeException('Roster count '.$year);
  $seen=[];
  foreach ($event['players'] as $player) {
    $name=$player['name'];if (isset($seen[$name])) throw new RuntimeException('Duplicate '.$year.' '.$name);$seen[$name]=true;
    if (in_array($name,$excluded[$year],true)) { if (isset($map[$name])) throw new RuntimeException('Excluded in map '.$name);continue; }
    if (!isset($map[$name])) throw new RuntimeException('Unmapped '.$year.' '.$name);
    $id=(int)$map[$name];$lookup->execute([$id]);$db=$lookup->fetch(PDO::FETCH_ASSOC);
    if (!$db || !in_array($name,[$db['name'],$db['oldname']],true)) throw new RuntimeException('ID mismatch '.$year.' '.$name);
    if (preg_match('/^(\d{4})/',(string)$db['birth'],$birth) && (int)$birth[1]>$year-14) throw new RuntimeException('Birth mismatch '.$year.' '.$name);
    $exists->execute([$id,$year]);if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Already exists '.$year.' '.$name);
    $rows[]=[$id,$year];
  }
  if (count($seen)!==$expected[$year]) throw new RuntimeException('Source mismatch '.$year);
}
if (count($rows)!==36) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_olympics_8488';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'national','올림픽',NULL,?,NULL,NULL,NULL)");
$pdo->beginTransaction();
try { foreach ($rows as $row) $insert->execute($row);$pdo->commit(); }
catch (Throwable $e) { $pdo->rollBack();throw $e; }
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+36) throw new RuntimeException('Count mismatch');
echo json_encode(['backup'=>$backup,'added1984'=>19,'added1988'=>17,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
