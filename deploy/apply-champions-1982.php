<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$data=json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/korean-series-1982-2005.json'),true,512,JSON_THROW_ON_ERROR);
$roster=null;foreach ($data['rosters'] as $entry) if ((int)$entry['year']===1982) { $roster=$entry;break; }
if (!$roster || $roster['team']!=='OB 베어스' || (int)$roster['expected']!==25 || count($roster['players'])!==25) throw new RuntimeException('Roster count');
$overrides=['김광수'=>82210,'박종호'=>80045,'정혁진'=>82007,'이근식(1958)'=>80086,'이근식(1959)'=>80085];
$lookup=$pdo->prepare('SELECT player_id,birth FROM kbo_player_data WHERE name=? OR oldname=?');
$byId=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
$mvp=$pdo->query("SELECT PK,player_id FROM kbo_player_career WHERE category='award' AND type='한국시리즈 MVP' AND year=1982 AND team='OB'")->fetchAll(PDO::FETCH_ASSOC);
if (count($mvp)!==1) throw new RuntimeException('MVP');
$mvpId=(int)$mvp[0]['player_id'];
$exists=$pdo->prepare("SELECT COUNT(*) FROM kbo_player_career WHERE player_id=? AND category='award' AND type='우승' AND year=1982");
$rows=[];$seen=[];$foundMvp=false;
foreach ($roster['players'] as $player) {
  $name=$player['name'];
  $key=$name==='이근식'?$name.(str_contains($player['source_path'],'(1958)')?'(1958)':'(1959)'):$name;
  if (isset($seen[$key])) throw new RuntimeException('Duplicate '.$key);$seen[$key]=true;
  if (isset($overrides[$key])) {
    $id=$overrides[$key];$byId->execute([$id]);$db=$byId->fetch(PDO::FETCH_ASSOC);
    if (!$db || !in_array($name,[$db['name'],$db['oldname']],true)) throw new RuntimeException('Override '.$key);
    $birth=$db['birth'];
    if ($name==='이근식' && !str_starts_with((string)$birth,substr($key,-5,4))) throw new RuntimeException('Same-name birth '.$key);
  } else {
    $lookup->execute([$name,$name]);$c=$lookup->fetchAll(PDO::FETCH_ASSOC);
    if (count($c)!==1) throw new RuntimeException('Unresolved '.$name);
    $id=(int)$c[0]['player_id'];$birth=$c[0]['birth'];
  }
  if (preg_match('/^(\d{4})/',(string)$birth,$b) && (int)$b[1]>1968) throw new RuntimeException('Birth '.$key);
  $exists->execute([$id]);if ((int)$exists->fetchColumn()!==0) throw new RuntimeException('Already exists '.$key);
  if ($id===$mvpId) $foundMvp=true;
  $rows[]=[$id,'OB',1982,$id===$mvpId?'한국시리즈 MVP':null];
}
if (count($rows)!==25 || !$foundMvp) throw new RuntimeException('Validation');
$backup='kbo_player_career_backup_20260930_champs_1982';
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
if ($after!==$before+24) throw new RuntimeException('Post-write count');
echo json_encode(['backup'=>$backup,'addedWins'=>25,'mergedKsMvp'=>1,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
