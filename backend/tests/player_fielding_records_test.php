<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-fielding-records.php';
// kbo_fielding_records 행 모양
$row=static fn($year,$team,$position,$outs,array $over=[])=>$over+['year'=>$year,'team'=>$team,'position'=>$position,'games'=>20,'starts'=>10,'innings_outs'=>$outs,
    'errors'=>1,'pickoffs'=>0,'putouts'=>30,'assists'=>69,'double_plays'=>4,'fielding_pct'=>'0.990','passed_balls'=>0,'stolen_bases'=>0,'caught_stealing'=>0,'caught_stealing_pct'=>null];
$rows=[$row(2001,'SK','유격수',31),$row(2001,'SK','2루수',17),$row(2001,'LG','3루수',9),
    $row(2004,'LG','포수',0,['passed_balls'=>2,'stolen_bases'=>8,'caught_stealing'=>3,'caught_stealing_pct'=>'27.3','pickoffs'=>1])];
$result=profileFormatFieldingRecords($rows);
if(count($result['rows'])!==3||count($result['rows'][0]['positions'])!==2)throw new RuntimeException('Year/team groups mismatch');
$first=$result['rows'][0]['positions'][0];
if($first['games']!==20||$first['starts']!==10||$first['innings']!=='10.1'||$first['fieldingPct']!=='0.990')throw new RuntimeException('Fielding format mismatch');
if($first['errors']!==1||$first['putouts']!==30||$first['assists']!==69||$first['doublePlays']!==4)throw new RuntimeException('Fielding counts mismatch');
if($first['passedBalls']!==null||$first['stolenBases']!==null||$first['caughtStealing']!==null||$first['caughtStealingPct']!==null||$first['pickoffs']!==null)throw new RuntimeException('Catcher-only columns must be blank for infielders');
$catcher=$result['rows'][2]['positions'][0];
if($catcher['caughtStealingPct']!=='27.3'||$catcher['passedBalls']!==2||$catcher['stolenBases']!==8||$catcher['caughtStealing']!==3||$catcher['pickoffs']!==1)throw new RuntimeException('Catcher columns mismatch');
if($result['career']['innings']!=='19'||$result['career']['games']!==null||$result['career']['fieldingPct']!==null)throw new RuntimeException('Overlapping games must not be summed');
if(profileFormatFieldingRecords([])['rows']!==[]||profileFormatFieldingRecords([])['career']['innings']!==null)throw new RuntimeException('Empty records mismatch');
echo "PASS: fielding year/team grouping, position rows, fractional innings, all counts, catcher-only columns, no overlapping game totals\n";

$schedule=[2000=>['regular'=>['2000-04-01','2000-10-01']],2001=>['regular'=>['2001-04-01','2001-10-01']],2025=>['regular'=>['2025-04-01','2025-10-01']],2026=>['regular'=>['2026-04-01','2026-10-01']]];
$event=static fn($id,$date,$team,$pos,$gs)=>['game_id'=>$id,'game_date'=>$date,'team'=>$team,'pos'=>$pos,'is_gs'=>$gs];
$events=[$event('a','2001-04-02','SSG','지',1),$event('a','2001-04-02','SSG','지三',1),$event('b','2001-04-03','SK','三지',1),$event('c','2001-04-04','SK','타지',0),$event('pre','2001-03-01','SK','지',1),
    $event('old','2000-04-02','SK','지',1),$event('now','2026-04-02','SSG','지',1),$event('d','2025-04-02','LG','D',null)];
$withDh=profileAddDesignatedHitterRecords($result,$events,$schedule);
$sk=array_values(array_filter($withDh['rows'],static fn($r)=>$r['year']===2001&&$r['team']==='SK'))[0];
$dh=$sk['positions'][2];
if($dh['position']!=='지명타자'||$dh['games']!==3||$dh['starts']!==1||$dh['innings']!==null||$dh['errors']!==null)throw new RuntimeException('DH dedup, alias merge or starting position mismatch');
if(count($withDh['rows'])!==5||min(array_column($withDh['rows'],'year'))!==2001||max(array_column($withDh['rows'],'year'))!==2026)throw new RuntimeException('DH seasons must cover 2001 through the current season');
$career=array_column($withDh['careerPositions'],null,'position');
if($career['지명타자']['games']!==5||$career['지명타자']['starts']!==null||$career['지명타자']['innings']!==null||$career['지명타자']['fieldingPct']!==null)throw new RuntimeException('DH career or missing starts mismatch');
$repeat=profileFormatFieldingRecords([$row(2001,'SK','유격수',31),$row(2004,'LG','유격수',17,['errors'=>3])]);
$c=$repeat['careerPositions'][0];
if($c['games']!==40||$c['starts']!==20||$c['innings']!=='16'||$c['errors']!==4||$c['putouts']!==60||$c['assists']!==138||$c['doublePlays']!==8)throw new RuntimeException('Position career aggregation mismatch');
if($c['fieldingPct']!=='0.980'||$c['passedBalls']!==null||$c['caughtStealingPct']!==null)throw new RuntimeException('Career fielding percentage must come from summed chances');
$catchers=profileFormatFieldingRecords([$row(2004,'LG','포수',30,['stolen_bases'=>8,'caught_stealing'=>3,'caught_stealing_pct'=>'27.3']),$row(2005,'LG','포수',30,['stolen_bases'=>2,'caught_stealing'=>7,'caught_stealing_pct'=>'77.8'])]);
if($catchers['careerPositions'][0]['caughtStealingPct']!=='50.0'||$catchers['careerPositions'][0]['stolenBases']!==10)throw new RuntimeException('Career caught-stealing rate mismatch');
echo "PASS: DH season range, game deduplication, team aliases, starting positions, per-position careers and career rates\n";
