<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') exit(1);
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
$roster = json_decode(file_get_contents('/home/bitnami/wesiper-weather-preview/premier12-2015-user.json'), true, 512, JSON_THROW_ON_ERROR);
$ids = [
    '장원준'=>74513,'이현승'=>76329,'양의지'=>76232,'허경민'=>79240,
    '오재원'=>77248,'김재호'=>74206,'민병헌'=>76249,'김현수'=>76290,
    '차우찬'=>76455,'심창민'=>61411,'김상수'=>79402,
    '이태양'=>61323,'임창민'=>78352,'나성범'=>62947,
    '조상우'=>63342,'박병호'=>75125,'김광현'=>77829,
    '정우람'=>74857,'정근우'=>75808,'이용규'=>74163,
    '정대현'=>71801,'강민호'=>74540,'황재균'=>76313,'손아섭'=>77532,
    '우규민'=>73117,'조무근'=>65067,'이대은'=>67008,'이대호'=>71564,
];
$allNames = array_merge(...array_values($roster['teams']));
if (count($allNames)!==28 || count(array_unique($allNames))!==28 || array_diff($allNames,array_keys($ids)) || array_diff(array_keys($ids),$allNames)) {
    throw new RuntimeException('Roster count or names mismatch');
}
$lookup=$pdo->prepare('SELECT name,oldname,birth FROM kbo_player_data WHERE player_id=?');
foreach ($ids as $name=>$id) {
    $lookup->execute([$id]);
    $player=$lookup->fetch(PDO::FETCH_ASSOC);
    if (!$player || ($player['name']!==$name && $player['oldname']!==$name)) throw new RuntimeException('Identity mismatch: '.$name);
}
$existing=(int)$pdo->query("SELECT COUNT(*) FROM kbo_player_career WHERE category='national' AND type='프리미어12' AND year=2015")->fetchColumn();
if ($existing!==0) throw new RuntimeException('2015 Premier12 rows already exist');
$backup='kbo_player_career_backup_20260930_premier12';
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$check->execute([$backup]);
if ((int)$check->fetchColumn()!==0) throw new RuntimeException('Backup already exists');
$before=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
$pdo->exec('CREATE TABLE '.$backup.' AS SELECT * FROM kbo_player_career');
if ((int)$pdo->query('SELECT COUNT(*) FROM '.$backup)->fetchColumn()!==$before) throw new RuntimeException('Backup verification failed');
$insert=$pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,year,month,pos,note) VALUES (?,'national','프리미어12',NULL,2015,NULL,NULL,NULL)");
$pdo->beginTransaction();
try {
    foreach ($allNames as $name) $insert->execute([$ids[$name]]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
$after=(int)$pdo->query('SELECT COUNT(*) FROM kbo_player_career')->fetchColumn();
if ($after!==$before+28) throw new RuntimeException('Post-write count mismatch');
echo json_encode(['backup'=>$backup,'before'=>$before,'added'=>28,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
