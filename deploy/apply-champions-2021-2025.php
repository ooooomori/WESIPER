<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-2006-2025.json'),true,512,JSON_THROW_ON_ERROR);
$teams=[2021=>'KT',2022=>'SSG',2023=>'LG',2024=>'KIA',2025=>'LG'];
$overrides=[
  2021=>['김민수'=>65048,'오윤석'=>64504,'조용호'=>64868,'김민혁'=>64004],
  2022=>['이태양'=>60768,'김광현'=>77829,'최민준'=>68856,'박종훈'=>60841,'장지훈'=>51895,'이재원'=>76812,'김성현'=>76802],
  2023=>['켈리'=>69103,'고우석'=>67119,'김범석'=>53144,'김민성'=>77564,'신민재'=>65207,'김현수'=>76290,'최승민'=>65905],
  2024=>['김기훈'=>69620,'양현종'=>77637,'김도현'=>69745,'김대유'=>60337,'박찬호'=>64646,'김도영'=>52605,'박정우'=>67609,'최원준'=>66606],
  2025=>['박시원'=>55121,'김진수'=>51154,'김영우'=>55167,'이주헌'=>52154,'신민재'=>65207,'천성호'=>50054,'김현수'=>76290],
];
$mvp=$pdo->prepare("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=? AND team=?");
$lookup=$pdo->prepare('SELECT player_id,name,oldname,birth FROM kbo_player_data WHERE name=? OR oldname=? ORDER BY player_id');
$rows=[];$mvpPks=[];
foreach ($teams as $year=>$team) {
    $mvp->execute([$year,$team]);
    $mvpRows=$mvp->fetchAll(PDO::FETCH_ASSOC);
    if (count($mvpRows)!==1) throw new RuntimeException('Missing unique KS MVP '.$year);
    $mvpId=(int)$mvpRows[0]['player_id'];
    $mvpPks[]=(int)$mvpRows[0]['PK'];
    $yearRosters=array_values(array_filter($data['rosters'],fn($r)=>(int)$r['year']===$year));
    $roster=$yearRosters[0]??null;
    if (!$roster || count($roster['players'])!==30 || (int)$roster['expected']!==30) throw new RuntimeException('Roster size mismatch '.$year);
    $seen=[];$foundMvp=false;
    foreach ($roster['players'] as $player) {
        $name=$player['name'];
        if (isset($seen[$name])) throw new RuntimeException('Duplicate name '.$year.' '.$name);
        $seen[$name]=true;
        $lookup->execute([$name,$name]);
        $candidates=$lookup->fetchAll(PDO::FETCH_ASSOC);
        $id=$overrides[$year][$name]??(count($candidates)===1?(int)$candidates[0]['player_id']:null);
        if ($id===null || !in_array($id,array_map(fn($c)=>(int)$c['player_id'],$candidates),true)) throw new RuntimeException('Unresolved player '.$year.' '.$name);
        if ($id===$mvpId) $foundMvp=true;
        $rows[]=[$id,$team,$year,$id===$mvpId?'한국시리즈 MVP':null];
    }
    if (!$foundMvp) throw new RuntimeException('MVP not on champion roster '.$year);
}
if (count($rows)!==150 || count($mvpPks)!==5) throw new RuntimeException('Total mismatch');
$existing=(int)$pdo->query("SELECT COUNT(*) FROM kbo_player_career WHERE category='award' AND type='우승' AND year BETWEEN 2021 AND 2025")->fetchColumn();
if ($existing!==0) throw new RuntimeException('Championship careers already exist');
$backup='kbo_player_career_backup_20260930_champions';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);
if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup already exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup mismatch');
$pdo->exec("ALTER TABLE kbo_player_career DROP CONSTRAINT chk_career_note, ADD CONSTRAINT chk_career_note CHECK (note IS NULL OR (category='award' AND type='올스타' AND note='MVP') OR (category='award' AND type='우승' AND note IN ('한국시리즈 MVP','플레이오프 MVP','준플레이오프 MVP','한국시리즈 MVP, 플레이오프 MVP','한국시리즈 MVP, 준플레이오프 MVP','플레이오프 MVP, 준플레이오프 MVP','한국시리즈 MVP, 플레이오프 MVP, 준플레이오프 MVP')))");
$pdo->exec("ALTER TABLE kbo_player_career DROP CONSTRAINT chk_career_national, ADD CONSTRAINT chk_career_national CHECK ((category='national' AND type IN ('WBC','프리미어12','APBC','아시안게임','올림픽') AND team IS NULL AND month IS NULL AND pos IS NULL AND note IS NULL) OR (category='award' AND type IN ('골든글러브','MVP','올스타','신인왕','수비상','월간 MVP','한국시리즈 MVP','우승') AND team IS NOT NULL AND year IS NOT NULL))");
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
if ($after!==$before+145) throw new RuntimeException('Post-write mismatch');
echo json_encode(['backup'=>$backup,'addedWins'=>150,'mergedKsMvp'=>5,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
