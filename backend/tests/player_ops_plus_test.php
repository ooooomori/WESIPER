<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-year-records.php';
function expectOpsPlus($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.': '.json_encode([$actual,$expected]));
}
$league=['cum_ab'=>1000,'cum_h'=>300,'cum_ob'=>100,'cum_sf'=>20,'cum_tb'=>450];
$stats=['ab'=>100,'h'=>30,'bb'=>8,'hbp'=>2,'sf'=>2,'tb'=>45,'opsLeagueYears'=>[2007=>$league]];
expectOpsPlus(profileSeasonOpsPlus($stats),100.0,'Matching league rates equal 100');
$stats['h']=0;$stats['tb']=0;$stats['bb']=0;$stats['hbp']=0;
expectOpsPlus(profileSeasonOpsPlus($stats),-100.0,'Zero hits/on-base is calculable');
$stats['opsLeagueYears'][2006]=null;
expectOpsPlus(profileSeasonOpsPlus($stats),null,'Incomplete league seasons remain unknown');
$row=array_fill_keys(['games','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','so','sf','sh','ibb','e'],0);
$row=array_replace($row,['year'=>1985,'series_id'=>0,'games'=>100,'pa'=>112,'ab'=>100,'h'=>30,'doubles'=>15,'bb'=>8,'hbp'=>2,'sf'=>2]);
$pre1986=$league;$pre1986['cum_sf']=0;
$old=profileSeasonStats([$row],false,[1985=>$pre1986]);
expectOpsPlus($old['opsPlus'],100.0,'Pre-1986 official OBP excludes SF in both denominators');
$row['year']=1986;
$new=profileSeasonStats([$row],false,[1986=>$league]);
expectOpsPlus($new['opsPlus'],100.0,'1986 standard denominator');
$merged=profileMergeSeasonStats([$old,$new],false);
$expected=round(100*((80/224)/(800/2220)+(90/200)/(900/2000)-1));
expectOpsPlus($merged['opsPlus'],$expected,'Career recomputes from unrounded totals and all season baselines');
expectOpsPlus(count($merged['opsLeagueYears']),2,'Historical league years survive career merge');
$sameYear=profileMergeSeasonStats([$new,$new],false);
expectOpsPlus(count($sameYear['opsLeagueYears']),1,'Team/series merge never duplicates league seasons');
expectOpsPlus($sameYear['opsPlus'],100.0,'Same-year merge retains correct league denominator');
echo "PASS: historical OPS+, old OBP convention, zero rates, missing baseline, career totals and duplicate-season merge\n";
