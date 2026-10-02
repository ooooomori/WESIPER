<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-2006-2025.json'),true,512,JSON_THROW_ON_ERROR);
// Years with a complete 26–30-player championship roster and a direct KS berth.
$teams=[2006=>'삼성',2007=>'SK',2008=>'SK',2009=>'KIA',2011=>'삼성',2014=>'삼성',2016=>'두산',2019=>'두산',2020=>'NC'];
$expected=[2006=>26,2007=>26,2008=>26,2009=>26,2011=>26,2014=>27,2016=>28,2019=>30,2020=>30];
$overrides=[
  2006=>['오상민'=>97815,'브라운'=>76429,'박종호'=>92906,'강명구'=>73409,'박정환'=>70408,'김종훈'=>94539],
  2007=>['정대현'=>71801,'이영욱'=>73825,'김광현'=>77829,'김동건'=>71806,'이호준'=>94629,'김재현'=>94107,'이진영'=>99810],
  2008=>['이승호'=>70820,'정대현'=>71801,'이영욱'=>73825,'김광현'=>77829,'이재원'=>76812,'김재현'=>94107,'박정환'=>70408,'이진영'=>99810],
  2009=>['윤석민'=>75620,'양현종'=>77637,'로페즈'=>79644,'김상훈'=>70612,'김종국'=>96616,'최경환'=>70117,'김상현'=>70646,'이용규'=>74163],
  2011=>['정현욱'=>96462,'윤성환'=>74454,'강명구'=>73409,'김상수'=>79402,'이영욱'=>78467],
  2014=>['마틴'=>64430,'윤성환'=>74454,'김현우'=>60457,'이승엽'=>95436,'김상수'=>79402,'김태완'=>74158],
  2016=>['최재훈'=>78288,'에반스'=>66244,'오재원'=>77248,'이원석'=>75566,'박건우'=>79215],
  2019=>['최원준'=>67263,'페르난데스'=>69209,'오재원'=>77248,'정진호'=>61208,'박건우'=>79215],
  2020=>['라이트'=>50912,'김형준'=>68912,'이원재'=>62025,'이재율'=>66968,'김성욱'=>62934,'이명기'=>76849],
];
$byYear=[];
foreach ($data['rosters'] as $roster) $byYear[(int)$roster['year']][]=$roster;
$mvp=$pdo->prepare("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=? AND team=?");
$lookup=$pdo->prepare('SELECT player_id,name,oldname,birth FROM kbo_player_data WHERE name=? OR oldname=? ORDER BY player_id');
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=?");
$rows=[];$mvpPks=[];
foreach ($teams as $year=>$team) {
    $roster=$byYear[$year][0]??null;
    if (!$roster || count($roster['players'])!==$expected[$year] || (int)$roster['expected']!==$expected[$year]) throw new RuntimeException('Roster size mismatch '.$year);
    $mvp->execute([$year,$team]);
    $mvpRows=$mvp->fetchAll(PDO::FETCH_ASSOC);
    if (count($mvpRows)!==1) throw new RuntimeException('MVP mismatch '.$year);
    $mvpId=(int)$mvpRows[0]['player_id'];$mvpPks[]=(int)$mvpRows[0]['PK'];
    $seen=[];$foundMvp=false;
    foreach ($roster['players'] as $player) {
        $name=$player['name'];
        if (isset($seen[$name])) throw new RuntimeException('Duplicate name '.$year.' '.$name);
        $seen[$name]=true;
        $lookup->execute([$name,$name]);
        $candidates=$lookup->fetchAll(PDO::FETCH_ASSOC);
        $id=$overrides[$year][$name]??(count($candidates)===1?(int)$candidates[0]['player_id']:null);
        if ($id===null || !in_array($id,array_map(fn($c)=>(int)$c['player_id'],$candidates),true)) throw new RuntimeException('Unresolved player '.$year.' '.$name);
        $exists->execute([$id,$year]);
        if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Win already exists '.$year.' '.$name);
        if ($id===$mvpId) $foundMvp=true;
        $rows[]=[$id,$team,$year,$id===$mvpId?'한국시리즈 MVP':null];
    }
    if (!$foundMvp) throw new RuntimeException('MVP missing from champion roster '.$year);
}
if (count($rows)!==array_sum($expected) || count($mvpPks)!==count($teams)) throw new RuntimeException('Total mismatch');
$backup='kbo_player_career_backup_20260930_champs_old';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);
if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup already exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'award','우승',?,?,NULL,NULL,?)");
$delete=$pdo->prepare("DELETE FROM kbo_player_career WHERE PK=? AND category='award' AND type='한국시리즈 MVP'");
$pdo->beginTransaction();
try {
    foreach ($rows as $row) $insert->execute($row);
    foreach ($mvpPks as $pk) {
        $delete->execute([$pk]);
        if ($delete->rowCount()!==1) throw new RuntimeException('MVP delete mismatch '.$pk);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+count($rows)-count($mvpPks)) throw new RuntimeException('Post-write mismatch');
echo json_encode(['backup'=>$backup,'addedWins'=>count($rows),'mergedKsMvp'=>count($mvpPks),'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
