<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-game-seasons.php';
require dirname(__DIR__).'/lib/player-records.php';
function expectGameSeasons($actual,$expected): void {
    if($actual!==$expected)throw new RuntimeException('Season availability mismatch: '.json_encode($actual,JSON_UNESCAPED_UNICODE));
}
$schedule=[];
foreach([2022,2023,2024] as $year)$schedule[$year]=['preseason'=>["$year-03-01","$year-03-20"],'regular'=>["$year-04-01","$year-10-10"],'postseason'=>["$year-10-11","$year-11-20"]];
$row=static fn($date,$league=1,$allstar=0)=>['game_date'=>$date,'league_level'=>$league,'is_allstar'=>$allstar];
expectGameSeasons(profileGameSeasonAvailability([$row('2023-04-15'),$row('2023-10-20')],$schedule),[2023=>['regular','postseason']]);
expectGameSeasons(profileGameSeasonAvailability([$row('2024-06-01',2),$row('2023-04-15')],$schedule),[2024=>['futures'],2023=>['regular']]);
expectGameSeasons(profileGameSeasonAvailability([$row('2024-07-01',2,1)],$schedule),[]);
expectGameSeasons(profileGameSeasonAvailability([$row('2023-03-02'),$row('2023-10-20'),$row('2023-08-01',2),$row('2023-04-15')],$schedule),[2023=>['regular','futures','postseason','preseason']]);
expectGameSeasons(profileGameSeasonAvailability([$row('2023-10-20'),$row('2023-08-01',2)],$schedule)[2023][0],'futures');
expectGameSeasons(profileGameSeasonAvailability([$row('2023-10-20'),$row('2023-03-02')],$schedule)[2023][0],'postseason');
expectGameSeasons(profileGameSeasonAvailability([$row('2023-03-02')],$schedule)[2023][0],'preseason');
expectGameSeasons(profileGameSeasonAvailability([$row('2024-07-01',2,1),$row('2023-04-15')],$schedule),[2023=>['regular']]);
$game=profileGameMeta(['game_id'=>'20240601SMLG0','game_date'=>'2024-06-01','team'=>'상무'],['20240601SMLG0'=>['away_team'=>'상무','home_team'=>'LG','away_score'=>4,'home_score'=>1,'stadium'=>'이천']]);
expectGameSeasons([$game['opponent'],$game['isAway'],$game['result'],$game['stadium']],['LG',true,'W 4-1','이천']);
echo "PASS: 9 cases (season availability, futures-only years, all-star exclusion, fallback priority, futures game metadata)\n";
