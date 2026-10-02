<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-1982-2005.json'),true,512,JSON_THROW_ON_ERROR);
$years=[1983=>['해태',26,0],1986=>['해태',25,0],1987=>['해태',25,1],1988=>['해태',26,0],1989=>['해태',25,1],1990=>['LG',27,0],1991=>['해태',22,0]];
$overrides=[
  1983=>['김현재'=>83539,'김성한'=>82612],
  1986=>['김정수'=>86612,'신동수'=>86672,'김대현'=>86993,'김성한'=>82612],
  1987=>['김정수'=>86612,'신동수'=>86672,'김대현'=>86993,'김성한'=>82612,'박철우'=>87630],
  1988=>['김정수'=>86612,'신동수'=>86672,'김성한'=>82612,'박철우'=>87630],
  1989=>['김정수'=>86612,'신동수'=>86672,'김성한'=>82612,'박철우'=>87630,'김태완'=>89611],
  1990=>['김동수'=>90419,'김상훈'=>84112,'이병훈'=>90460],
  1991=>['김정수'=>86612,'신동수'=>86672,'김성한'=>82612,'박철우'=>87630,'김태완'=>89611,'이호성'=>90658],
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
$expected=array_sum(array_column($years,1));
if (count($rows)!==$expected || count($mvpPks)!==count($years)) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_champs_8391';
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
if ($after!==$before+$expected-count($years)) throw new RuntimeException('Post-write count');
echo json_encode(['backup'=>$backup,'addedWins'=>$expected,'mergedKsMvp'=>count($years),'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
