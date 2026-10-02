<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-missing-recollected.json'),true,512,JSON_THROW_ON_ERROR);
$years=[1993=>['해태',25,0],2000=>['현대',25,0],2001=>['두산',26,1],2003=>['현대',26,0],2010=>['SK',26,0]];
$overrides=[
  1993=>['김정수'=>86612,'신동수'=>86672,'김성한'=>82612,'박철우'=>87630,'이호성'=>90658,'이용석'=>93601],
  2000=>['전준호|61'=>94364,'이명수'=>89221,'박종호'=>92906,'전준호|1'=>91511,'카펜터'=>70310],
  2001=>['최용호'=>95247,'김동주'=>98218],
  2003=>['바워스'=>73322,'전준호|61'=>94364,'김동수'=>90419,'정성훈'=>99606,'박종호'=>92906,'김민우'=>72303,'전준호|1'=>91511],
  2010=>['김광현'=>77829,'정대현'=>71801,'이승호|20'=>70820,'이승호|37'=>99137,'이호준'=>94629,'박정환'=>70408,'김재현'=>94107],
];
$byYear=[];foreach ($data['rosters'] as $roster) $byYear[(int)$roster['year']][]=$roster;
$lookup=$pdo->prepare('SELECT player_id,birth FROM kbo_player_data WHERE name=? OR oldname=?');
$byId=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
$mvp=$pdo->prepare("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=? AND team=?");
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=?");
$rows=[];$mvpPks=[];
foreach ($years as $year=>[$team,$count,$index]) {
  $roster=$byYear[$year][$index]??null;
  if (!$roster || count($roster['players'])!==$count || (int)$roster['expected']!==$count || (int)$roster['count']!==$count) throw new RuntimeException('Roster count '.$year);
  $mvp->execute([$year,$team]);$mvpRows=$mvp->fetchAll(PDO::FETCH_ASSOC);
  if (count($mvpRows)!==1) throw new RuntimeException('MVP '.$year);
  $mvpId=(int)$mvpRows[0]['player_id'];$mvpPks[]=(int)$mvpRows[0]['PK'];$foundMvp=false;$seen=[];
  foreach ($roster['players'] as $player) {
    $name=$player['name'];$key=isset($player['jersey'])?$name.'|'.$player['jersey']:$name;
    if (isset($seen[$key])) throw new RuntimeException('Duplicate '.$year.' '.$key);$seen[$key]=true;
    if ($year===1993 && $name==='김훈' && $player['source_path']!==null) throw new RuntimeException('Unlinked source mismatch');
    if (isset($overrides[$year][$key])) {
      $id=$overrides[$year][$key];$byId->execute([$id]);$db=$byId->fetch(PDO::FETCH_ASSOC);
      if (!$db || !in_array($name,[$db['name'],$db['oldname']],true)) throw new RuntimeException('Override mismatch '.$year.' '.$key);
      $birth=$db['birth'];
    } else {
      $lookup->execute([$name,$name]);$c=$lookup->fetchAll(PDO::FETCH_ASSOC);
      if (count($c)!==1) throw new RuntimeException('Unresolved '.$year.' '.$name);
      $id=(int)$c[0]['player_id'];$birth=$c[0]['birth'];
    }
    if (preg_match('/^(\d{4})/',(string)$birth,$b) && (int)$b[1]>$year-14) throw new RuntimeException('Birth mismatch '.$year.' '.$key);
    $exists->execute([$id,$year]);if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Already exists '.$year.' '.$key);
    if ($id===$mvpId) $foundMvp=true;
    $rows[]=[$id,$team,$year,$id===$mvpId?'한국시리즈 MVP':null];
  }
  if (!$foundMvp) throw new RuntimeException('MVP not in roster '.$year);
}
if (count($rows)!==128 || count($mvpPks)!==5) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_champs_recollected';
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
if ($after!==$before+123) throw new RuntimeException('Post-write count');
echo json_encode(['backup'=>$backup,'addedWins'=>128,'mergedKsMvp'=>5,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
