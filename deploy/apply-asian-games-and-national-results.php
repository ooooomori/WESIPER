<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$events=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/asian-games-1998-2014-user.json'),true,512,JSON_THROW_ON_ERROR);
$expected=[1998=>22,2002=>22,2006=>22,2010=>24,2014=>24];
$results=[
  'WBC'=>[2006=>'3위',2009=>'준우승',2013=>'1라운드 탈락',2017=>'1라운드 탈락',2023=>'1라운드 탈락',2026=>'8위'],
  '프리미어12'=>[2015=>'우승',2019=>'준우승',2024=>'5위'],
  'APBC'=>[2017=>'준우승',2023=>'준우승'],
  '아시안게임'=>[1998=>'금메달',2002=>'금메달',2006=>'동메달',2010=>'금메달',2014=>'금메달',2018=>'금메달',2023=>'금메달',2026=>'금메달'],
  '올림픽'=>[1984=>'4위',1988=>'4위',1996=>'8위',2000=>'동메달',2008=>'금메달',2020=>'4위'],
];
$overrides=[
  1998=>['박찬호'=>62761,'김동주'=>98218,'이병규'=>97109],
  2002=>['이승호'=>70820,'송진우'=>89770,'김진우'=>72641,'이상훈'=>93147,'김상훈'=>70612,'김동주'=>98218,'김민재'=>91523,'김종국'=>96616,'이승엽'=>95436,'이병규'=>97109],
  2006=>['윤석민'=>75620,'이병규'=>97109,'이용규'=>74163,'이진영'=>99810],
  2010=>['정대현'=>71801,'김명성'=>61527,'윤석민'=>75620,'양현종'=>77637,'김태균'=>71752,'이용규'=>74163,'김현수'=>76290],
  2014=>['이태양'=>60768,'김광현'=>77829,'양현종'=>77637,'이재원'=>76812,'김민성'=>77564,'김상수'=>79402,'오재원'=>77248,'박병호'=>75125,'김현수'=>76290],
];
$lookup=$pdo->prepare('SELECT player_id,name,oldname,birth FROM kbo_player_data WHERE name=? OR oldname=? ORDER BY player_id');
$byId=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='national' AND type='아시안게임' AND year=?");
$rows=[];$eventYears=[];
foreach ($events as $event) {
  $year=(int)$event['year'];
  if (!isset($expected[$year]) || isset($eventYears[$year]) || $event['result']!==$results['아시안게임'][$year]) throw new RuntimeException('Unexpected event '.$year);
  $eventYears[$year]=true;$seen=[];$count=0;
  foreach ($event['players'] as $role=>$names) {
    if (!in_array($role,['투수','포수','내야수','외야수'],true)) throw new RuntimeException('Unexpected role '.$role);
    foreach ($names as $name) {
      if (isset($seen[$name])) throw new RuntimeException('Duplicate name '.$year.' '.$name);$seen[$name]=true;$count++;
      if (isset($overrides[$year][$name])) {
        $id=$overrides[$year][$name];$byId->execute([$id]);$db=$byId->fetch(PDO::FETCH_ASSOC);
        if (!$db || !in_array($name,[$db['name'],$db['oldname']],true)) throw new RuntimeException('Override mismatch '.$year.' '.$name);
        $birth=$db['birth'];
      } else {
        $lookup->execute([$name,$name]);$candidates=$lookup->fetchAll(PDO::FETCH_ASSOC);
        if (count($candidates)!==1) throw new RuntimeException('Unresolved '.$year.' '.$name);
        $id=(int)$candidates[0]['player_id'];$birth=$candidates[0]['birth'];
      }
      if (preg_match('/^(\d{4})/',(string)$birth,$match) && (int)$match[1]>$year-14) throw new RuntimeException('Birth mismatch '.$year.' '.$name);
      $exists->execute([$id,$year]);if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Already exists '.$year.' '.$name);
      $rows[]=[$id,$year,$results['아시안게임'][$year]];
    }
  }
  if ($count!==$expected[$year]) throw new RuntimeException('Roster count '.$year);
}
if (count($eventYears)!==5 || count($rows)!==114) throw new RuntimeException('Total mismatch');
$groups=$pdo->query("SELECT type,year,COUNT(*) AS n,COUNT(note) AS noted FROM kbo_player_career WHERE category='national' AND (type<>'WBC' OR country='한국') GROUP BY type,year")->fetchAll(PDO::FETCH_ASSOC);
$existingNational=0;
foreach ($groups as $group) {
  $type=$group['type'];$year=(int)$group['year'];
  if (!isset($results[$type][$year]) || (int)$group['noted']!==0) throw new RuntimeException('Unmapped or pre-noted national group '.$type.' '.$year);
  $existingNational+=(int)$group['n'];
}
$backup='kbo_player_career_backup_20260930_results_agold';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$pdo->exec("ALTER TABLE kbo_player_career
  DROP CONSTRAINT chk_career_note,
  DROP CONSTRAINT chk_career_national,
  ADD CONSTRAINT chk_career_note CHECK (
    note IS NULL
    OR (category='national' AND note IN ('금메달','동메달','우승','준우승','5위','3위','4위','8위','1라운드 탈락','2라운드 탈락','4강','8강'))
    OR (category='award' AND type='올스타' AND note='MVP')
    OR (category='award' AND type='우승' AND note IN ('한국시리즈 MVP','플레이오프 MVP','준플레이오프 MVP','한국시리즈 MVP, 플레이오프 MVP','한국시리즈 MVP, 준플레이오프 MVP','플레이오프 MVP, 준플레이오프 MVP','한국시리즈 MVP, 플레이오프 MVP, 준플레이오프 MVP'))
  ),
  ADD CONSTRAINT chk_career_national CHECK (
    (category='national' AND type IN ('WBC','프리미어12','APBC','아시안게임','올림픽') AND team IS NULL AND month IS NULL AND pos IS NULL)
    OR (category='award' AND type IN ('골든글러브','MVP','올스타','신인왕','수비상','월간 MVP','한국시리즈 MVP','우승') AND team IS NOT NULL AND year IS NOT NULL)
  )");
$update=$pdo->prepare("UPDATE kbo_player_career SET note=? WHERE category='national' AND type=? AND year=? AND note IS NULL AND (type<>'WBC' OR country='한국')");
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,country,year,month,pos,note) VALUES (?,'national','아시안게임',NULL,'한국',?,NULL,NULL,?)");
$updated=0;$pdo->beginTransaction();
try {
  foreach ($groups as $group) { $update->execute([$results[$group['type']][(int)$group['year']],$group['type'],(int)$group['year']]);$updated+=$update->rowCount(); }
  if ($updated!==$existingNational) throw new RuntimeException('Existing update count');
  foreach ($rows as $row) $insert->execute($row);
  $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack();throw $e; }
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$noted=(int)$pdo->query("SELECT COUNT(*) FROM kbo_player_career WHERE category='national' AND note IS NOT NULL")->fetchColumn();
if ($after!==$before+114 || $noted!==$existingNational+114) throw new RuntimeException('Post-write count');
echo json_encode(['backup'=>$backup,'newAsianGames'=>114,'updatedNationalResults'=>$updated,'allNationalWithResult'=>$noted,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
