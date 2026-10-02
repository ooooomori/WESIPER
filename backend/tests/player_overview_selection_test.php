<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-overview-cache.php';
$first=['year'=>2025,'leagueLevel'=>1,'career'=>false];$currentFutures=['year'=>2026,'leagueLevel'=>2,'career'=>false];$olderFutures=['year'=>2024,'leagueLevel'=>2,'career'=>false];
$check=static function($player,$current,$regular,$futures,$expected,$expectedCalls){$calls=[];$load=static function($year,$season)use(&$calls,$current,$regular,$futures){$calls[]=[$year,$season];return $year!==null?$current:($season==='regular'?$regular:$futures);};$actual=profileChooseOverviewRecords($player,2026,$load);if($actual!==$expected||$calls!==$expectedCalls)throw new RuntimeException('Overview selection mismatch');};
$check(['Team'=>'울산'],$currentFutures,$first,$olderFutures,$currentFutures,[[2026,'futures']]);
$check(['Team'=>'울산'],null,$first,$olderFutures,$first,[[2026,'futures'],[null,'regular']]);
$check(['Team'=>'울산'],null,null,$olderFutures,$olderFutures,[[2026,'futures'],[null,'regular'],[null,'futures']]);
$check(['Team'=>'KIA'],$currentFutures,$first,$olderFutures,$first,[[null,'regular']]);
$check(['Team'=>'KIA'],null,null,$olderFutures,$olderFutures,[[null,'regular'],[null,'futures']]);
$check(['Team'=>'은퇴','IsKbodle'=>0],null,null,['career'=>true,'leagueLevel'=>2],['career'=>true,'leagueLevel'=>2],[[null,'regular'],[null,'futures']]);
$check(['Team'=>'울산'],null,null,null,null,[[2026,'futures'],[null,'regular'],[null,'futures']]);
$rows=[['game_date'=>'2025-07-01','inning'=>'3','er'=>1],['game_date'=>'2026-07-01','inning'=>'9','er'=>1]];
if(profileOverviewEraPlus($rows,[2025=>['era'=>6],2026=>['era'=>3]])!==250)throw new RuntimeException('ERA+ innings weighting mismatch');
if(profileOverviewEraPlus($rows,[2026=>['era'=>3]])!==null)throw new RuntimeException('Missing league context mismatch');
if(profileOverviewEraPlus([['game_date'=>'2026-07-01','inning'=>'9','er'=>0]],[2026=>['era'=>3]])!==null)throw new RuntimeException('Zero ERA mismatch');
echo "PASS: Ulsan priority/fallback, first-team/futures-only, retired career, empty records and weighted ERA+\n";
