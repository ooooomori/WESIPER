<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-2006-2025.json'),true,512,JSON_THROW_ON_ERROR);
$roster=null;
foreach ($data['rosters'] as $entry) if ((int)$entry['year']===2017) { $roster=$entry; break; }
if (!$roster || (int)$roster['expected']!==30 || count($roster['players'])!==30) throw new RuntimeException('Roster size');
$overrides=['양현종'=>77637,'팻 딘'=>67645,'이정훈'=>67644,'서동욱'=>73606,'김주형'=>74605,'최원준'=>66606,'이명기'=>76849];
$lookup=$pdo->prepare('SELECT player_id FROM kbo_player_data WHERE name=? OR oldname=?');
$idExists=$pdo->prepare('SELECT COUNT(*) FROM kbo_player_data WHERE player_id=?');
$mvp=$pdo->query("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=2017 AND team='KIA'")->fetchAll(PDO::FETCH_ASSOC);
if (count($mvp)!==1) throw new RuntimeException('MVP');
$mvpId=(int)$mvp[0]['player_id'];
$already=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=2017");
$rows=[];$seen=[];$foundMvp=false;
foreach ($roster['players'] as $player) {
  $name=$player['name'];
  if (isset($seen[$name])) throw new RuntimeException('Duplicate '.$name);
  $seen[$name]=true;
  if (isset($overrides[$name])) { $id=$overrides[$name];$idExists->execute([$id]);if ((int)$idExists->fetchColumn()!==1) throw new RuntimeException('Override missing '.$name); }
  else { $lookup->execute([$name,$name]);$ids=$lookup->fetchAll(PDO::FETCH_COLUMN);if (count($ids)!==1) throw new RuntimeException('Unresolved '.$name);$id=(int)$ids[0]; }
  $already->execute([$id]);if ((int)$already->fetchColumn()!==0) throw new RuntimeException('Already present '.$name);
  if ($id===$mvpId) $foundMvp=true;
  $rows[]=[$id,'KIA',2017,$id===$mvpId?'한국시리즈 MVP':null];
}
if (count($rows)!==30 || !$foundMvp) throw new RuntimeException('Validation');
$backup='kbo_player_career_backup_20260930_champs_2017';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'award','우승',?,?,NULL,NULL,?)");
$delete=$pdo->prepare("DELETE FROM kbo_player_career WHERE PK=? AND category='award' AND type='한국시리즈 MVP'");
$pdo->beginTransaction();
try { foreach ($rows as $row) $insert->execute($row);$delete->execute([(int)$mvp[0]['PK']]);if ($delete->rowCount()!==1) throw new RuntimeException('MVP delete');$pdo->commit(); }
catch (Throwable $e) { $pdo->rollBack();throw $e; }
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+29) throw new RuntimeException('Count mismatch');
echo json_encode(['backup'=>$backup,'addedWins'=>30,'mergedKsMvp'=>1,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
