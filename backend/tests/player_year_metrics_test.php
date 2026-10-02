<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-year-records.php';
function expectYearMetric($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.': '.json_encode([$actual,$expected],JSON_UNESCAPED_UNICODE));
}
expectYearMetric(profileAgeOnJulyFirst('2000-07-01',2024),24,'July 1 birthday');
expectYearMetric(profileAgeOnJulyFirst('2000-07-02',2024),23,'Birthday after cutoff');
expectYearMetric(profileAgeOnJulyFirst('2000-06-30',2024),24,'Birthday before cutoff');
expectYearMetric(profileAgeOnJulyFirst('2001-02-29',2024),null,'Invalid birthday');
expectYearMetric(profileAgeOnJulyFirst(null,2024),null,'Unknown birthday');
expectYearMetric(profileAgeOnJulyFirst('2025-01-01',2024),null,'Future birthday');
function metricBatRow(string $result,string $game='g1'): array {
    return ['game_id'=>$game,'game_date'=>'2024-06-01','pa_result'=>$result,'sb'=>0,'cs'=>0,'run_out'=>0,'rbi'=>0,'r'=>0,'is_gs'=>1,'pos'=>'3'];
}
$bat=array_map('metricBatRow',['좌안','좌2','좌3','좌홈','4구','고4','사구','유실','투희번','중희비','삼진','유땅','2병','우비','스낫']);
$bat[0]['sb']=2;$bat[0]['cs']=1;$bat[0]['run_out']=1;
foreach([0,2,3] as $i)$bat[$i]['r']=1;
$s=profileYearTotals($bat,false,[],[]);
foreach(['pa'=>15,'ab'=>10,'so'=>2,'woba'=>'0.619','spd'=>'5.6','isoObp'=>'0.100','isoSlg'=>'0.600','babip'=>'0.375','groundFly'=>'1.00','bbPct'=>'13.3','kPct'=>'13.3','bbK'=>'1.00','sbAttempts'=>3,'sbPct'=>'66.7','runOut'=>1,'sbSecond'=>null,'sbThird'=>null,'sbHome'=>null] as $key=>$value)expectYearMetric($s[$key],$value,'Batter '.$key);
$missing=$bat;$missing[0]['sb']=null;$missing[0]['run_out']=null;$s=profileYearTotals($missing,false,[],[]);
foreach(['woba','spd','sbAttempts','sbPct','runOut','effectiveOps'] as $key)expectYearMetric($s[$key],null,'Missing running data '.$key);
expectYearMetric(profileMetricRatio(1,0),null,'Zero denominator');
$pitch=[
    ['game_id'=>'g1','game_date'=>'2024-06-01','team'=>'KIA','inning'=>'9','order'=>1,'er'=>0,'r'=>0,'record'=>'승','pitched'=>100],
    ['game_id'=>'g2','game_date'=>'2024-06-02','team'=>'KIA','inning'=>'7','order'=>1,'er'=>3,'r'=>3,'record'=>'패','pitched'=>90],
    ['game_id'=>'g3','game_date'=>'2024-06-03','team'=>'KIA','inning'=>'2/3','order'=>2,'er'=>1,'r'=>1,'record'=>'세','pitched'=>10],
];
$faced=[];
foreach(['g1'=>['삼진','4구','좌홈','사구','유땅','우비'],'g2'=>['삼진','좌안','우비'],'g3'=>['4구','유땅']] as $game=>$results)$faced[$game]=array_map(static fn($text)=>profileAdvancedBatEvent(metricBatRow($text,$game)),$results);
$context=['g1|KIA'=>['pitchers'=>1,'last_order'=>1,'completed'=>true],'g2|KIA'=>['pitchers'=>3,'last_order'=>3,'completed'=>true],'g3|KIA'=>['pitchers'=>2,'last_order'=>2,'completed'=>true]];
$league=['pitchingYears'=>[2024=>['era'=>4.32,'fipConstant'=>3.0]]];
$s=profileYearTotals($pitch,true,$faced,$league,$context);
foreach(['games'=>3,'starts'=>2,'reliefs'=>1,'finishes'=>1,'starterInnings'=>'16','reliefInnings'=>'0.2','innings'=>'16.2','pitches'=>200,'pitchesPerInning'=>'12.00','pitchesPerGame'=>'66.67','qs'=>2,'qsPlus'=>2,'ds'=>1,'complete'=>1,'shutouts'=>1,'fip'=>'4.08','eraPlus'=>200,'k9'=>'1.08','bb9'=>'1.08','h9'=>'1.08','hr9'=>'0.54','kPct'=>'18.2','bbPct'=>'18.2','kBb'=>'1.00','opponentAvg'=>'0.250','opponentObp'=>'0.455','opponentSlg'=>'0.625','opponentOps'=>'1.080','babip'=>'0.200'] as $key=>$value)expectYearMetric($s[$key],$value,'Pitcher '.$key);
$pitch[2]['order']=null;$pitch[2]['pitched']=null;$s=profileYearTotals($pitch,true,$faced,$league,$context);
foreach(['starts','reliefs','finishes','starterInnings','reliefInnings','pitches','pitchesPerInning','pitchesPerGame'] as $key)expectYearMetric($s[$key],null,'Unknown pitching role/count '.$key);
$s=profileYearTotals([$pitch[0]],true,[],[],[]);
foreach(['fip','eraPlus','k9','kPct','opponentAvg','opponentOps','complete','shutouts'] as $key)expectYearMetric($s[$key],null,'Unknown context '.$key);
$multi=[['game_id'=>'a','game_date'=>'2023-06-01','team'=>'KIA','inning'=>'3','order'=>1,'er'=>1,'r'=>1,'record'=>'','pitched'=>40],['game_id'=>'b','game_date'=>'2024-06-01','team'=>'KIA','inning'=>'3','order'=>1,'er'=>1,'r'=>1,'record'=>'','pitched'=>40]];
$multiFaced=['a'=>[profileAdvancedBatEvent(metricBatRow('삼진','a'))],'b'=>[profileAdvancedBatEvent(metricBatRow('삼진','b'))]];
$s=profileYearTotals($multi,true,$multiFaced,['pitchingYears'=>[2023=>['era'=>6,'fipConstant'=>4],2024=>['era'=>3,'fipConstant'=>2]]],[]);
expectYearMetric($s['fip'],'2.33','Career FIP weights season constants by innings');
expectYearMetric($s['eraPlus'],150,'Career ERA+ weights league ERA by innings');
echo "PASS: age cutoff, user wOBA/Spd, batted-ball and running rates, innings/roles, QS/CG/SHO, FIP/ERA+, missing data\n";

expectYearMetric(profileYearPosition([['game_id'=>'a','pos'=>'유'],['game_id'=>'a','pos'=>'유'],['game_id'=>'a','pos'=>'유'],['game_id'=>'b','pos'=>'지'],['game_id'=>'c','pos'=>'지']]),'DH','Career position counts games, not plate appearances');
expectYearMetric(profileYearPosition([]),null,'Missing career position');
echo "PASS: career primary position per-game deduplication and missing position\n";
