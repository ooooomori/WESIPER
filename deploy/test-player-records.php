<?php
if(PHP_SAPI!=='cli') exit;
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
require '/home/bitnami/wesiper-weather-preview/lib/player-records.php';
require '/home/bitnami/wesiper-weather-preview/lib/player-rankings.php';
function check($actual,$expected,$label) { if($actual!==$expected) throw new RuntimeException($label.': '.json_encode($actual)); }
check(profileRankingCachedPlayers(['revision'=>'before-crawl','players'=>[1=>['안타'=>2]]],'before-crawl'),[1=>['안타'=>2]],'retain cache while crawler runs');
check(profileRankingCachedPlayers(['revision'=>'before-crawl','players'=>[1=>['안타'=>2]]],'after-crawl'),null,'invalidate after successful crawl');
check(profileRankingCachedPlayers([1=>['안타'=>2]],'after-crawl'),null,'reject old cache format');
check(profileInningOuts('5 2/3'),17,'mixed innings');
check(profileInningOuts('5 ⅔'),17,'unicode innings');
check(profileInningOuts('1/3'),1,'fraction innings');
check(profileInningOuts('2.1'),7,'baseball decimal');
check(profileInningText(17),'5.2','innings display');
check(profileInningText(19),'6.1','one out innings display');
check(profileInningText(18),'6','whole innings display');
$events=array_map(fn($pa)=>['pa_result'=>$pa,'sb'=>0,'rbi'=>0,'r'=>0],['좌안','중홈','4구','사구','희비','삼진']);
$s=profileBatStats($events,['obp'=>.350,'slg'=>.400]);
check($s[0][1],'0.667','average');check($s[1][1],2,'hits');check($s[2][1],1,'home runs');
check(profileBatEvent(['pa_result'=>null])['ab'],0,'runner no AB');
check(profileOpponent('20260926LGHT02026','LG'),'KIA','opponent');
check(profileGameMeta(['game_id'=>'20260926LGHT02026','game_date'=>'2026-09-26','team'=>'LG'],[])['isAway'],true,'away flag');
check(profileGameMeta(['game_id'=>'20260926LGHT02026','game_date'=>'2026-09-26','team'=>'KIA'],[])['isAway'],false,'home flag');
$schedule=['20260926LGHT0'=>['stadium'=>'광주','away_score'=>1,'home_score'=>6]];
check(profileGameMeta(['game_id'=>'20260926LGHT02026','game_date'=>'2026-09-26','team'=>'KIA'],$schedule)['result'],'W 1-6','home win away-home score order');
check(profileGameMeta(['game_id'=>'20260926LGHT02026','game_date'=>'2026-09-26','team'=>'LG'],$schedule)['result'],'L 1-6','away loss');
$window=profileBatWindow($events,['obp'=>.350,'slg'=>.400],7);
check($window['pa'],6,'rolling plate appearances');check($window['avg'],'0.667','rolling average');check($window['hr'],1,'rolling home runs');
$pitchWindow=profilePitchWindow([['isStarter'=>true,'innings'=>'6.1','r'=>2,'er'=>2,'so'=>7,'h'=>5,'hr'=>1,'bb'=>2,'hbp'=>0]],7);
check($pitchWindow['starts'],1,'rolling starts');check($pitchWindow['innings'],'6.1','rolling innings');check($pitchWindow['era'],'2.84','rolling ERA');check($pitchWindow['whip'],'1.11','rolling WHIP');
$ranks=profileMetricRanks([1=>[['타율','0.350'],['안타',100]],2=>[['타율','0.350'],['안타',90]],3=>[['타율','0.400'],['안타',100]]],[1=>true,2=>true,3=>false],['타율']);
check($ranks[1]['타율'],1,'qualified tie');check(isset($ranks[3]['타율']),false,'unqualified rate');check($ranks[3]['안타'],1,'count metric tie');
foreach([52605,61895,77829,78168,62647,76849] as $id) {
 $s=$pdo->prepare('SELECT player_id PlayerId,pos Pos,is_kbodle IsKbodle,name FROM kbo_player_data WHERE player_id=?');$s->execute([$id]);$p=$s->fetch();if(!$p)continue;
 $r=profileRecords($pdo,$p,getKBOSchedule());
 if($r){check(count($r['rolling']),3,'three rolling windows');check($r['rolling'][0]['label'],'최근 7일','rolling label');}
 echo json_encode(['id'=>$id,'name'=>$p['name'],'year'=>$r['year']??null,'career'=>$r['career']??null,'gameCount'=>count($r['games']??[]),'recent'=>array_slice($r['recent']??[],0,1)],JSON_UNESCAPED_UNICODE)."\n";
}
echo json_encode(['kimDoyoungRanks'=>profileRankings($pdo,2026,getKBOSchedule())[52605]??null],JSON_UNESCAPED_UNICODE)."\n";
echo "PASS\n";
check(profilePitchNote(['isStarter'=>true,'innings'=>'8.2','er'=>1,'r'=>1,'result'=>'W 6-1'],false),'DS','dominant start');
check(profilePitchNote(['isStarter'=>true,'innings'=>'7','er'=>3,'r'=>3,'result'=>'W 6-3'],false),'QS+','QS plus');
check(profilePitchNote(['isStarter'=>true,'innings'=>'9','er'=>0,'r'=>0,'result'=>'W 6-0'],true),'완봉승','shutout win');
check(profilePosition([['pos'=>'주8']],false),'대주자 · 중견','pinch runner position');
check(profilePosition([['pos'=>'三유']],true),'3루-유격','stored Chinese/Korean positions');
echo json_encode(['positionSamples'=>$pdo->query('SELECT DISTINCT pos FROM kbo_season_records WHERE player_id=52605 LIMIT 15')->fetchAll(PDO::FETCH_COLUMN)],JSON_UNESCAPED_UNICODE)."\n";
