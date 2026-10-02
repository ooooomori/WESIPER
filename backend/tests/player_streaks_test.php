<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/player-streaks.php';
function streakRow(string $game, ?string $result, int $sb=0, int $cs=0): array {
    return ['game_date'=>'2026-09-30', 'game_id'=>$game, 'pa_result'=>$result, 'sb'=>$sb, 'cs'=>$cs];
}
function checkStreak(array $rows, string $key, int $count, ?bool $positive): void {
    $result = array_column(profileCalculateStreaks($rows), null, 'key')[$key];
    if ($result['count'] !== $count || $result['positive'] !== $positive) {
        throw new RuntimeException(json_encode([$key, $count, $positive, $result], JSON_UNESCAPED_UNICODE));
    }
}
$rows = [streakRow('g5','삼진'), streakRow('g5','좌안'), streakRow('g4','좌홈'), streakRow('g3','4구'), streakRow('g2','사구'), streakRow('g1','유땅')];
checkStreak($rows,'h',2,true); // Multiple PAs remain one game.
checkStreak($rows,'ob',4,true); // Walks and HBP count as reaching base.
checkStreak($rows,'hr',1,false);
checkStreak([streakRow('g3','좌홈'),streakRow('g2','중홈'),streakRow('g1','좌안')],'hr',2,true);
checkStreak([streakRow('g3','유실'),streakRow('g2','삼진'),streakRow('g1','4구')],'ob',2,false);
checkStreak([streakRow('g3',null),streakRow('g2','좌안'),streakRow('g1','삼진')],'h',1,true);
$steals = [streakRow('g6','삼진'),streakRow('g5','좌안',1),streakRow('g4',null),streakRow('g3','4구',2),streakRow('g2','좌안',0,1)];
checkStreak($steals,'sb',2,true); // No attempt never breaks a streak; count games, not steals.
checkStreak([streakRow('g4',null),streakRow('g3','좌안',1,1),streakRow('g2',null,0,1),streakRow('g1','좌안',1)],'sb',2,false);
checkStreak([streakRow('g1','좌안')],'sb',1,null);
foreach (['h','ob','hr','sb'] as $key) checkStreak([],$key,0,null);
echo "PASS: 13 streak cases (per-game grouping, hits, on-base, HR, PA-less appearances, steal attempts and mixed results)\n";

$dated=static function($game,$date,$result,$sb=0,$cs=0){$row=streakRow($game,$result,$sb,$cs);$row['game_date']=$date;return $row;};
$rows=[$dated('g6','2026-09-30','좌안'),$dated('g5','2026-09-29','좌안',1),$dated('g5','2026-09-29','4구'),$dated('g4','2026-09-28',null),$dated('g3','2026-09-25','좌안',2),$dated('g2','2026-09-24','삼진',0,1)];
$result=array_column(profileCalculateStreaks($rows),null,'key');
foreach(['h'=>[3,true,'2026-09-25','2026-09-30'],'ob'=>[3,true,'2026-09-25','2026-09-30'],'hr'=>[4,false,'2026-09-24','2026-09-30'],'sb'=>[2,true,'2026-09-25','2026-09-29']] as $key=>$expected){$r=$result[$key];if([$r['count'],$r['positive'],$r['startDate'],$r['endDate']]!==$expected)throw new RuntimeException('Streak date range mismatch: '.$key);}
foreach(profileCalculateStreaks([]) as $r)if($r['startDate']!==null||$r['endDate']!==null)throw new RuntimeException('Missing streak date mismatch');
echo "PASS: streak date ranges, skipped PA-less games, skipped no-attempt games and missing dates\n";

$noAttempts=[$dated('a','2026-09-30','좌안'),$dated('a','2026-09-30','4구'),$dated('b','2026-09-29',null),$dated('c','2026-09-26','삼진')];
$r=array_column(profileCalculateStreaks($noAttempts),null,'key')['sb'];
if([$r['count'],$r['positive'],$r['startDate'],$r['endDate']]!==[3,null,'2026-09-26','2026-09-30'])throw new RuntimeException('No-attempt game count and dates mismatch');
echo "PASS: gray no-attempt streak counts unique games including PA-less appearances and date range\n";

$current=[$dated('c2','2026-04-02','좌홈',1),$dated('c1','2026-03-28','좌홈',1)];
$history=[2025=>[$dated('p2','2025-10-04','좌홈',1),$dated('p1','2025-10-03','좌홈',1)],2024=>[$dated('p0','2024-10-01','삼진',0,1)]];
$loaded=[];
$result=array_column(profileExtendStreaks($current,[2025,2024,2023],static function($year)use($history,&$loaded){$loaded[]=$year;return $history[$year]??[];}),null,'key');
foreach(['h','ob','hr','sb'] as $key){$r=$result[$key];if([$r['count'],$r['positive'],$r['startDate'],$r['endDate']]!==[4,true,'2025-10-03','2026-04-02'])throw new RuntimeException('Multi-season positive streak: '.$key);}
if($loaded!==[2025,2024])throw new RuntimeException('Should stop loading at streak break');
$current=[$dated('c2','2026-04-02','삼진',0,1),$dated('c1','2026-03-28','유땅',0,1)];
$history=[2025=>[],2024=>[$dated('p2','2024-10-01','삼진',0,1),$dated('p1','2024-09-30','좌홈',1)]];
$result=array_column(profileExtendStreaks($current,[2025,2024],static fn($year)=>$history[$year]),null,'key');
foreach(['h','ob','hr','sb'] as $key){$r=$result[$key];if([$r['count'],$r['positive'],$r['startDate'],$r['endDate']]!==[3,false,'2024-10-01','2026-04-02'])throw new RuntimeException('Negative streak across absent season: '.$key);}
$current=[$dated('c','2026-03-28','좌안')];
$history=[2025=>[$dated('p2','2025-10-04',null),$dated('p1','2025-10-03','삼진',1)]];
$r=array_column(profileExtendStreaks($current,[2025],static fn($year)=>$history[$year]),null,'key')['sb'];
if([$r['count'],$r['positive'],$r['startDate'],$r['endDate']]!==[2,null,'2025-10-04','2026-03-28'])throw new RuntimeException('Gray no-attempt streak should stop at prior attempt');
$current=[$dated('c3','2026-04-03','좌홈',1),$dated('c2','2026-04-02','삼진',0,1),$dated('c1','2026-03-28','좌홈',1)];
profileExtendStreaks($current,[2025],static function(){throw new RuntimeException('History fetched despite all streaks breaking this year');});
$current=[$dated('c','2026-03-28',null)];
$result=array_column(profileExtendStreaks($current,[2025],static fn($year)=>[$dated('p','2025-10-04','좌홈',1)]),null,'key');
foreach(['h','ob','hr'] as $key)if($result[$key]['count']!==0||$result[$key]['positive']!==null)throw new RuntimeException('Do not display old batting streak without this season PA');
echo "PASS: cross-season success/failure, multi-season boundaries, absent season, gray no-attempt state and lazy history loading\n";
