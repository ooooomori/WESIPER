<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-1982-2005.json'),true,512,JSON_THROW_ON_ERROR);
$years=[1996=>['해태',25,0],1997=>['해태',25,0],1998=>['현대',25,0]];
$overrides=[
  1996=>['김정수'=>86612,'김상진'=>96611,'김종국'=>96616,'김태룡'=>92407,'이호성'=>90658],
  1997=>['김정수'=>86612,'김상진'=>96611,'김종국'=>96616,'이호준'=>94629,'김태룡'=>92407,'이호성'=>90658,'조현'=>95103],
  1998=>['김홍집'=>93311,'이명수'=>89221,'박종호'=>92906,'쿨바'=>80587,'전준호'=>91511],
];
$byYear=[];foreach ($data['rosters'] as $roster) $byYear[(int)$roster['year']][]=$roster;
$lookup=$pdo->prepare('SELECT player_id,birth FROM kbo_player_data WHERE name=? OR oldname=?');
$byId=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
$mvp=$pdo->prepare("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=? AND team=?");
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=?");
$rows=[];$mvpPks=[];
foreach ($years as $year=>[$team,$count,$index]) {
  $roster=$byYear[$year][$index]??null;
  if (!$roster || count($roster['players'])!==$count || (int)$roster['expected']!==$count) throw new RuntimeException('Roster count '.$year);
  $mvp->execute([$year,$team]);$mvpRows=$mvp->fetchAll(PDO::FETCH_ASSOC);
  if (count($mvpRows)!==1) throw new RuntimeException('MVP '.$year);
  $mvpId=(int)$mvpRows[0]['player_id'];$mvpPks[]=(int)$mvpRows[0]['PK'];$foundMvp=false;$seen=[];
  foreach ($roster['players'] as $player) {
    $name=$player['name'];if (isset($seen[$name])) throw new RuntimeException('Duplicate '.$year.' '.$name);$seen[$name]=true;
    if (isset($overrides[$year][$name])) {
      $id=$overrides[$year][$name];$byId->execute([$id]);$db=$byId->fetch(PDO::FETCH_ASSOC);
      if (!$db || !in_array($name,[$db['name'],$db['oldname']],true)) throw new RuntimeException('Override mismatch '.$year.' '.$name);
      $birth=$db['birth'];
    } else {
      $lookup->execute([$name,$name]);$c=$lookup->fetchAll(PDO::FETCH_ASSOC);
      if (count($c)!==1) throw new RuntimeException('Unresolved '.$year.' '.$name);
      $id=(int)$c[0]['player_id'];$birth=$c[0]['birth'];
    }
    if (preg_match('/^(\d{4})/',(string)$birth,$b) && (int)$b[1]>$year-14) throw new RuntimeException('Birth mismatch '.$year.' '.$name);
    $exists->execute([$id,$year]);if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Already exists '.$year.' '.$name);
    if ($id===$mvpId) $foundMvp=true;
    $rows[]=[$id,$team,$year,$id===$mvpId?'한국시리즈 MVP':null];
  }
  if (!$foundMvp) throw new RuntimeException('MVP not in roster '.$year);
}
if (count($rows)!==75 || count($mvpPks)!==3) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_champs_9698';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'award','우승',?,?,NULL,NULL,?)");
$delete=$pdo->prepare("DELETE FROM kbo_player_career WHERE PK=? AND category='award' AND type='한국시리즈 MVP'");
$pdo->beginTransaction();
try { foreach ($rows as $row) $insert->execute($row);foreach ($mvpPks as $pk) { $delete->execute([$pk]);if ($delete->rowCount()!==1) throw new RuntimeException('MVP delete '.$pk); }$pdo->commit(); }
catch (Throwable $e) { $pdo->rollBack();throw $e; }
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+72) throw new RuntimeException('Post-write count');
echo json_encode(['backup'=>$backup,'addedWins'=>75,'mergedKsMvp'=>3,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
