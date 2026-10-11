<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-similarity.php';
function expectSimilarity($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.': '.json_encode([$actual,$expected],JSON_UNESCAPED_UNICODE));
}
$batter=static fn(array $stats,?string $pos='SS',int $pa=5000)=>['v'=>$pa,'pos'=>$pos,'stats'=>$stats+['games'=>1200,'ab'=>4500,'r'=>600,'h'=>1300,'doubles'=>250,'triples'=>20,'hr'=>150,'rbi'=>700,'bb'=>400,'so'=>800,'sb'=>100,'avg'=>'0.289','slg'=>'0.450']];
$base=similarityVector($batter([]),false);

// 기준: 타자 1,000타석, 투수 300이닝 또는 150경기
expectSimilarity(similarityQualified(['v'=>999,'stats'=>[]],false),false,'Batter below minimum');
expectSimilarity(similarityQualified(['v'=>1000,'stats'=>[]],false),true,'Batter at minimum');
expectSimilarity(similarityQualified(['v'=>899,'stats'=>['games'=>149]],true),false,'Pitcher below both minimums');
expectSimilarity(similarityQualified(['v'=>900,'stats'=>['games'=>30]],true),true,'Pitcher by innings');
expectSimilarity(similarityQualified(['v'=>450,'stats'=>['games'=>150]],true),true,'Pitcher by games');

// 타자: 같은 기록이면 1000점, 항목별 차이만큼 뺀다.
expectSimilarity(similarityScore($base,$base,false),1000.0,'Identical batters');
foreach(['games'=>20,'ab'=>75,'r'=>10,'h'=>15,'doubles'=>5,'triples'=>4,'hr'=>2,'rbi'=>10,'bb'=>25,'so'=>150,'sb'=>20] as $key=>$unit){
    $other=similarityVector($batter([$key=>$batter([])['stats'][$key]+$unit*3]),false);
    expectSimilarity(round(similarityScore($base,$other,false),6),997.0,'Batter '.$key);
}
expectSimilarity(round(similarityScore($base,similarityVector($batter(['avg'=>'0.300','slg'=>'0.470']),false),false),6),979.0,'Batter rates');
// 비율 기록은 정렬용 값이 있으면 그 값을 쓴다.
expectSimilarity(round(similarityScore($base,similarityVector(['sort'=>['avg'=>'0.2895']]+$batter([]),false),false),6),999.5,'Batter rate precision');
// 포지션: 값 차이만큼 빼고, 외야는 서로 같다. 모르는 포지션·항목은 비교하지 않는다.
expectSimilarity(similarityScore($base,similarityVector($batter([],'C'),false),false),928.0,'Shortstop and catcher');
expectSimilarity(similarityScore(similarityVector($batter([],'LF'),false),similarityVector($batter([],'CF'),false),false),1000.0,'Outfielders');
expectSimilarity(similarityScore($base,similarityVector($batter([],null),false),false),1000.0,'Unknown position');
expectSimilarity(similarityScore($base,similarityVector($batter(['so'=>null]),false),false),1000.0,'Unknown stat');

// 투수: 승률·ERA+는 최대 100점, 이닝은 아웃 수로 비교한다.
$pitcher=static fn(array $stats,int $outs=4500)=>['v'=>$outs,'stats'=>$stats+['wins'=>100,'losses'=>80,'winPct'=>'0.556','eraPlus'=>120,'games'=>300,'starts'=>250,'complete'=>20,'h'=>1400,'so'=>1200,'bb'=>500,'shutouts'=>5,'saves'=>3,'holds'=>0]];
$starter=similarityVector($pitcher([]),true,'우투');
expectSimilarity(similarityScore($starter,$starter,true),1000.0,'Identical pitchers');
expectSimilarity(round(similarityScore($starter,similarityVector($pitcher(['wins'=>103,'losses'=>84]),true,'우투'),true),6),995.0,'Pitcher wins and losses');
expectSimilarity(round(similarityScore($starter,similarityVector($pitcher([],4800),true,'우투'),true),6),998.0,'Pitcher innings');
expectSimilarity(round(similarityScore($starter,similarityVector($pitcher(['eraPlus'=>123,'holds'=>9]),true,'우투'),true),6),991.0,'Pitcher ERA+ and holds');
expectSimilarity(round(similarityScore($starter,similarityVector($pitcher(['eraPlus'=>40,'winPct'=>'0.100']),true,'우투'),true),6),800.0,'Pitcher rate caps');
// 던지는 손: 언더·사이드도 좌우로만 가른다. 선발끼리 10점, 구원끼리 25점, 선발과 구원 17.5점.
$reliefStats=['games'=>500,'starts'=>10];
$reliever=similarityVector($pitcher($reliefStats,1800),true,'우언');
expectSimilarity([$starter['hand'],$starter['reliever'],$reliever['hand'],$reliever['reliever']],['R',false,'R',true],'Pitcher roles');
expectSimilarity(similarityScore($starter,similarityVector($pitcher([]),true,'좌투'),true),990.0,'Opposite-hand starters');
expectSimilarity(similarityScore($reliever,similarityVector($pitcher($reliefStats,1800),true,'좌사'),true),975.0,'Opposite-hand relievers');
expectSimilarity(similarityScore($starter,similarityVector($pitcher([]),true,null),true),1000.0,'Unknown hand');
$mixed=similarityScore($reliever,similarityVector($pitcher($reliefStats,1800),true,'우투'),true)-similarityScore($reliever,similarityVector($pitcher($reliefStats,1800),true,'좌투'),true);
expectSimilarity($mixed,25.0,'Hand penalty only');
// 승률은 둘 다 구원이 아닐 때만 비교한다.
expectSimilarity(similarityScore($reliever,similarityVector($pitcher($reliefStats+['winPct'=>'0.400'],1800),true,'우언'),true),1000.0,'Reliever winning percentage is skipped');
expectSimilarity(similarityScore($starter,similarityVector($pitcher($reliefStats+['winPct'=>'0.400'],1800),true,'우투'),true)===similarityScore($starter,similarityVector($pitcher($reliefStats,1800),true,'우투'),true),true,'Starter and reliever skip winning percentage');
expectSimilarity(similarityScore($starter,similarityVector($pitcher([]),true,'좌투'),true)-similarityScore($starter,similarityVector($pitcher($reliefStats,1800),true,'좌투'),true)<0,false,'Starter is closer to a starter');
// 선발 등판 수를 모르면(2000년 이전) 등판당 이닝만으로 구원을 가르고, 선발 등판은 비교하지 않는다.
$unknown=similarityVector($pitcher(['starts'=>null,'games'=>500],1800),true,'우투');
expectSimilarity($unknown['reliever'],true,'Unknown starts use innings per game');
expectSimilarity(similarityScore($reliever,$unknown,true),1000.0,'Unknown starts are skipped');

// 상위 목록: 점수 높은 순, 같으면 ID 순, 자기 자신은 빼고 개수 제한을 지킨다.
$top=similarityTop([10=>$base,20=>similarityVector($batter(['hr'=>154]),false),30=>similarityVector($batter(['hr'=>146]),false),40=>similarityVector($batter(['hr'=>170]),false)],false,2);
expectSimilarity($top[10],[[20,998.0],[30,998.0]],'Top list order');
expectSimilarity($top[40],[[20,992.0],[10,990.0]],'Top list from the other side');
echo "player similarity tests passed\n";
