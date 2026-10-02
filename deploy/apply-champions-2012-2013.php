<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-2006-2025.json'),true,512,JSON_THROW_ON_ERROR);
$rosters=[];
foreach ($data['rosters'] as $roster) if (in_array((int)$roster['year'],[2012,2013],true) && !isset($rosters[(int)$roster['year']])) $rosters[(int)$roster['year']]=$roster;
$expected=[2012=>26,2013=>27];
$overrides=[
  2012=>['김희걸'=>71851,'윤성환'=>74454,'정현욱'=>96462,'강명구'=>73409,'김상수'=>79402,'이승엽'=>95436],
  2013=>['김희걸'=>71851,'윤성환'=>74454,'강명구'=>73409,'김태완'=>74158,'이승엽'=>95436],
];
$lookup=$pdo->prepare('SELECT player_id FROM kbo_player_data WHERE name=? OR oldname=?');
$idExists=$pdo->prepare('SELECT COUNT(*) FROM kbo_player_data WHERE player_id=?');
$mvp=$pdo->prepare("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=? AND team='삼성'");
$already=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=?");
$rows=[];$mvpPks=[];
foreach ($expected as $year=>$count) {
  $roster=$rosters[$year]??null;
  if (!$roster || count($roster['players'])!==$count || (int)$roster['expected']!==$count) throw new RuntimeException('Roster size '.$year);
  $mvp->execute([$year]);
  $mvpRows=$mvp->fetchAll(PDO::FETCH_ASSOC);
  if (count($mvpRows)!==1) throw new RuntimeException('MVP '.$year);
  $mvpId=(int)$mvpRows[0]['player_id'];$mvpPks[]=(int)$mvpRows[0]['PK'];$foundMvp=false;$seen=[];
  foreach ($roster['players'] as $player) {
    $name=$player['name'];
    if (isset($seen[$name])) throw new RuntimeException('Duplicate '.$year.' '.$name);
    $seen[$name]=true;
    if (isset($overrides[$year][$name])) {
      $id=$overrides[$year][$name];$idExists->execute([$id]);
      if ((int)$idExists->fetchColumn()!==1) throw new RuntimeException('Override missing '.$name);
    } else {
      $lookup->execute([$name,$name]);$ids=$lookup->fetchAll(PDO::FETCH_COLUMN);
      if (count($ids)!==1) throw new RuntimeException('Unresolved '.$year.' '.$name);
      $id=(int)$ids[0];
    }
    $already->execute([$id,$year]);
    if ((int)$already->fetchColumn()!==0) throw new RuntimeException('Already present '.$year.' '.$name);
    if ($id===$mvpId) $foundMvp=true;
    $rows[]=[$id,'삼성',$year,$id===$mvpId?'한국시리즈 MVP':null];
  }
  if (!$foundMvp) throw new RuntimeException('MVP not in roster '.$year);
}
if (count($rows)!==53 || count($mvpPks)!==2) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_champs_1213';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);
if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'award','우승',?,?,NULL,NULL,?)");
$delete=$pdo->prepare("DELETE FROM kbo_player_career WHERE PK=? AND category='award' AND type='한국시리즈 MVP'");
$pdo->beginTransaction();
try {
  foreach ($rows as $row) $insert->execute($row);
  foreach ($mvpPks as $pk) { $delete->execute([$pk]); if ($delete->rowCount()!==1) throw new RuntimeException('MVP delete '.$pk); }
  $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+51) throw new RuntimeException('Count mismatch');
echo json_encode(['backup'=>$backup,'addedWins'=>53,'mergedKsMvp'=>2,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
