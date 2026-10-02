<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-1994-1999-2005-reviewed.json'),true,512,JSON_THROW_ON_ERROR);
$expected=[1994=>25,1999=>25,2005=>26];
$overrides=[
  1994=>['이상훈'=>93147,'김동수'=>90419,'김정민'=>93112,'박종호'=>92906,'김재현'=>94107,'박준태'=>89331],
  1999=>['송진우'=>89770,'허준'=>93705,'심재윤'=>98762,'데이비스'=>99725],
  2005=>['오상민'=>97815,'박종호'=>92906,'강명구'=>73409,'김종훈'=>94539,'강동우'=>98420],
];
$lookup=$pdo->prepare('SELECT player_id,name,oldname,birth,team FROM kbo_player_data WHERE name=? OR oldname=? ORDER BY player_id');
$byId=$pdo->prepare('SELECT player_id,name,oldname,birth,team FROM kbo_player_data WHERE player_id=?');
$oldMvp=$pdo->prepare("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=? AND team=?");
$existing=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=?");
$rows=[];$deletePks=[];$report=[];$issues=[];
foreach ($data['rosters'] as $roster) {
  $year=(int)$roster['year'];$team=$roster['team'];$names=$roster['players'];
  if (count($names)!==$expected[$year] || count($names)!==count(array_unique($names))) throw new RuntimeException('Roster count '.$year);
  $oldMvp->execute([$year,$team]);$mvpRows=$oldMvp->fetchAll(PDO::FETCH_ASSOC);
  if (count($mvpRows)!==1) throw new RuntimeException('KS MVP '.$year);
  $mvpId=(int)$mvpRows[0]['player_id'];$deletePks[]=(int)$mvpRows[0]['PK'];$foundMvp=false;$resolved=[];
  foreach ($names as $name) {
    if (isset($overrides[$year][$name])) {
      $byId->execute([$overrides[$year][$name]]);$person=$byId->fetch(PDO::FETCH_ASSOC);
      if (!$person || !in_array($name,[$person['name'],$person['oldname']],true)) throw new RuntimeException('Override '.$year.' '.$name);
    } else {
      $lookup->execute([$name,$name]);$people=$lookup->fetchAll(PDO::FETCH_ASSOC);
      if (count($people)!==1) {$issues[]=['year'=>$year,'name'=>$name,'candidates'=>$people];continue;}
      $person=$people[0];
    }
    $id=(int)$person['player_id'];
    if (preg_match('/^(\d{4})/',(string)$person['birth'],$m) && (int)$m[1]>$year-14) throw new RuntimeException('Birth '.$year.' '.$name);
    $existing->execute([$id,$year]);if ((int)$existing->fetchColumn()!==0) throw new RuntimeException('Already exists '.$year.' '.$name);
    $note=$id===$mvpId?'한국시리즈 MVP':null;
    if ($note!==null) $foundMvp=true;
    $rows[]=[$id,$team,$year,$note];$resolved[]=[$name,$id,$person['birth'],$person['team'],$note];
  }
  $report[]=['year'=>$year,'count'=>count($resolved),'mvpFound'=>$foundMvp,'players'=>$resolved];
}
if (!in_array('--apply',$argv,true)) {echo json_encode(['issues'=>$issues,'report'=>$report],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;exit;}
if ($issues || count($rows)!==76 || count($deletePks)!==3) throw new RuntimeException('Unresolved or total mismatch');
foreach ($report as $r) if (!$r['mvpFound']) throw new RuntimeException('MVP not in roster '.$r['year']);
$backup='kbo_player_career_backup_20260930_champs_1994_1999_2005';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'award','우승',?,?,NULL,NULL,?)");
$delete=$pdo->prepare("DELETE FROM kbo_player_career WHERE PK=? AND category='award' AND type='한국시리즈 MVP'");
$pdo->beginTransaction();
try {foreach ($rows as $row) $insert->execute($row);foreach ($deletePks as $pk) {$delete->execute([$pk]);if ($delete->rowCount()!==1) throw new RuntimeException('Delete '.$pk);}$pdo->commit();}
catch (Throwable $e) {$pdo->rollBack();throw $e;}
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+73) throw new RuntimeException('Post count');
echo json_encode(['backup'=>$backup,'addedWins'=>76,'mergedKsMvp'=>3,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
