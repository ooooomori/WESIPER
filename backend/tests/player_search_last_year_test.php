<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__);
require dirname(__DIR__).'/api/kbocandle/common.php';
require dirname(__DIR__).'/lib/player-search.php';
// These tables shadow live tables only on this connection.
foreach(['kbo_season_records','kbo_season_pitch_records'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE `$table` (player_id INT,game_date DATE,league_level INT)");
}
foreach(['kbo_player_season_batting_totals','kbo_player_season_pitching_totals'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE `$table` (player_id INT,year INT,games INT)");
}
$pdo->exec("INSERT INTO kbo_season_records VALUES (1,'2023-09-01',1),(1,'2025-09-01',2),(2,'2023-09-01',1)");
$pdo->exec("INSERT INTO kbo_season_pitch_records VALUES (1,'2024-09-01',1),(2,'2026-09-01',2)");
$pdo->exec("INSERT INTO kbo_player_season_batting_totals VALUES (3,1998,20),(3,2000,0)");
$pdo->exec("INSERT INTO kbo_player_season_pitching_totals VALUES (3,1999,10)");
foreach([1=>2025,2=>2026,3=>1999,4=>null] as $pid=>$expected){
    if(profileLastRecordYear($pdo,(string)$pid)!==$expected)throw new RuntimeException('Last record year mismatch: '.$pid);
}
echo "PASS: latest year across leagues, batting/pitching, historical totals and absent records\n";
