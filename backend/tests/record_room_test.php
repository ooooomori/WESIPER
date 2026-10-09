<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/record-room.php';
function expectRecordRoom($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.': '.json_encode([$actual,$expected],JSON_UNESCAPED_UNICODE));
}
// 합계: 한 구간이라도 모르는 값이면 합계도 모른다.
$sum=recordRoomSum([['hr'=>3,'sb'=>1],['hr'=>4,'sb'=>null]],['hr','sb']);
expectRecordRoom($sum,['hr'=>7,'sb'=>null],'Sum keeps unknown values unknown');

// 투수: 아웃 수로 이닝을 계산하고, 모르는 항목은 빈칸으로 둔다.
$pitch=array_fill_keys(RECORD_ROOM_PITCH_FIELDS,null);
$pitch=['games'=>3,'starts'=>2,'wins'=>1,'losses'=>1,'saves'=>1,'holds'=>0,'outs'=>50,'r'=>4,'er'=>4,'so'=>3,'h'=>3,'hr'=>1,'bb'=>2,'hbp'=>1,'pa'=>11,'ab'=>8,'sf'=>0,'tb'=>6,'go'=>2,'fo'=>2,'eraW'=>50*4.5,'fipW'=>50*3.2]+$pitch;
$s=recordRoomPitcherStats($pitch);
foreach(['innings'=>'16.2','era'=>'2.16','whip'=>'0.30','winPct'=>'0.500','reliefs'=>1,'k9'=>'1.62','kPct'=>'27.3','opponentAvg'=>'0.375','opponentOps'=>'1.295','eraPlus'=>208,'fip'=>'4.16','qs'=>null,'starterInnings'=>null,'pitchesPerGame'=>null] as $key=>$value)expectRecordRoom($s[$key],$value,'Pitcher '.$key);
$unknown=recordRoomPitcherStats(['h'=>null,'ab'=>null,'starts'=>null]+$pitch);
foreach(['whip','opponentAvg','opponentOps','babip','reliefs'] as $key)expectRecordRoom($unknown[$key],null,'Unknown pitcher '.$key);

// 타자: 프로필의 연도별 기록과 같은 계산.
$bat=array_fill_keys(RECORD_ROOM_BAT_FIELDS,0);
$bat=['games'=>10,'starts'=>10,'pa'=>40,'ab'=>30,'h'=>12,'doubles'=>2,'triples'=>1,'hr'=>2,'rbi'=>8,'r'=>7,'bb'=>8,'hbp'=>1,'sf'=>1,'tb'=>22,'so'=>5,'sb'=>2,'cs'=>1,'eab'=>30,'eh'=>11,'etb'=>23,'eob'=>9]+$bat;
$s=recordRoomBatterStats($bat,[2024=>['cum_ab'=>1000,'cum_h'=>270,'cum_ob'=>110,'cum_sf'=>10,'cum_tb'=>400]],false,false);
foreach(['avg'=>'0.400','obp'=>'0.525','slg'=>'0.733','ops'=>'1.258','opsPlus'=>238,'effectiveOps'=>'1.267','bbPct'=>'20.0','sbPct'=>'66.7'] as $key=>$value)expectRecordRoom($s[$key],$value,'Batter '.$key);
$old=recordRoomBatterStats(['starts'=>null,'roe'=>null,'go'=>null,'fo'=>null,'runOut'=>null,'eab'=>null,'eh'=>null,'etb'=>null,'eob'=>null]+$bat,[1999=>null],true,false);
expectRecordRoom($old['avg'],'0.400','Historical average');
foreach(['woba','spd','effectiveOps','groundFly','opsPlus'] as $key)expectRecordRoom($old[$key]??null,null,'Historical '.$key);

// 정렬: 비율 기록만 최소 조건을 적용하고, 값이 없으면 제외하며, 동률은 같은 순위다.
$rows=[
    ['id'=>1,'foreign'=>false,'prospect'=>true,'pos'=>'SS','hand'=>'R','birth'=>1990,'year'=>2024,'active'=>true,'name'=>'가','v'=>500,'need'=>446.4,'stats'=>['avg'=>'0.300','hr'=>10,'innings'=>'10.1']],
    ['id'=>2,'foreign'=>true,'prospect'=>false,'pos'=>'LF','hand'=>'L','birth'=>1995,'year'=>2024,'active'=>false,'name'=>'나','v'=>320,'need'=>446.4,'stats'=>['avg'=>'0.350','hr'=>30,'innings'=>'9.2']],
    ['id'=>3,'foreign'=>false,'prospect'=>false,'pos'=>'CF','hand'=>'L','birth'=>2000,'year'=>2024,'active'=>true,'name'=>'다','v'=>100,'need'=>446.4,'stats'=>['avg'=>'0.400','hr'=>30,'innings'=>'100']],
    ['id'=>4,'foreign'=>true,'prospect'=>true,'pos'=>null,'hand'=>null,'birth'=>null,'year'=>2024,'active'=>true,'name'=>'라','v'=>450,'need'=>446.4,'stats'=>['avg'=>null,'hr'=>1,'innings'=>null]],
];
$ids=static fn(array $result)=>array_column($result['rows'],'id');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','avg',true,1.0,0,50)),[1],'Rate sort keeps qualified players only');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','avg',true,0.7,0,50)),[2,1],'70% of the requirement');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','avg',true,0.0,0,50)),[3,2,1],'No minimum, missing values dropped');
$hr=recordRoomQuery($rows,'batter','hr',true,1.0,0,50);
expectRecordRoom($ids($hr),[2,3,1,4],'Counting sort ignores the minimum');
expectRecordRoom(array_column($hr['rows'],'rank'),[1,1,3,4],'Ties share a rank');
expectRecordRoom([$hr['rate'],$hr['total'],$hr['rows'][0]['qualified'],$hr['rows'][2]['qualified']],[false,4,false,true],'Query metadata');
expectRecordRoom($ids(recordRoomQuery($rows,'pitcher','innings',true,1.0,1,1)),[1],'Innings sort by outs with paging');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','avg',false,0.0,0,50)),[1,2,3],'Ascending order');

expectRecordRoom($ids(recordRoomQuery($rows,'batter','avg',true,1.0,0,50,150.0)),[2,1],'Custom minimum plate appearances');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','hr',true,1.0,0,50,null,['active'=>true])),[3,1,4],'Active players only');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','hr',true,1.0,0,50,null,['foreign'=>true])),[2,4],'Foreign players only');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','hr',true,1.0,0,50,null,['prospect'=>true,'active'=>true])),[1,4],'Prospects only');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','hr',true,1.0,0,50,null,['pos'=>'OF'])),[2,3],'Primary position group');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','hr',true,1.0,0,50,null,['minAll'=>true])),[1,4],'Minimum on every sort when asked');
expectRecordRoom($ids(recordRoomQuery($rows,'batter','hr',true,1.0,0,50,null,['hand'=>'L','birthMin'=>1996])),[3],'Handedness and birth year');
expectRecordRoom([recordRoomHand('우투',true),recordRoomHand('우언',true),recordRoomHand('좌사',true),recordRoomHand('좌투',true),recordRoomHand('양타',false),recordRoomHand('',true)],['R','U','D','L','S',null],'Throwing and batting hand codes');
expectRecordRoom([recordRoomFranchise('해태'),recordRoomFranchise('SK'),recordRoomFranchise('넥센'),recordRoomFranchise('태평양')],['KIA','SSG','키움','현대'],'Franchise lineage');
$f=recordRoomFieldingStats(['games'=>10,'starts'=>8,'outs'=>200,'errors'=>1,'pickoffs'=>0,'putouts'=>60,'assists'=>9,'doublePlays'=>2,'passedBalls'=>3,'stolenBases'=>6,'caughtStealing'=>2],'포수');
expectRecordRoom([$f['innings'],$f['fieldingPct'],$f['caughtStealingPct'],$f['passedBalls']],['66.2','0.986','25.0',3],'Catcher fielding');
$f=recordRoomFieldingStats(['games'=>10,'starts'=>8,'outs'=>200,'errors'=>1,'pickoffs'=>0,'putouts'=>60,'assists'=>9,'doublePlays'=>2,'passedBalls'=>0,'stolenBases'=>0,'caughtStealing'=>0],'유격수');
expectRecordRoom([$f['passedBalls'],$f['caughtStealingPct'],$f['pickoffs']],[null,null,null],'Catcher-only columns stay blank');

// 골든글러브 후보 기준
$batter=static fn(array $over=[])=>$over+['games'=>100,'pa'=>450,'ab'=>400,'h'=>120,'pos'=>['SS'=>100],'dhPa'=>0,'fieldOuts'=>[]];
expectRecordRoom(recordRoomGoldenGloveBatterCodes(1983,$batter(),300),[],'No criteria before 1984');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(1984,$batter(['games'=>49,'pos'=>['SS'=>49]]),300),[],'1984 needs 50 games');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(1995,$batter(),390),['SS'],'1995 infielder .230');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(1995,$batter(['pos'=>['CF'=>100]]),390),['LF','CF','RF'],'1995 outfielder needs .280');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(1995,$batter(['pos'=>['CF'=>100],'h'=>111]),390),[],'1995 outfielder under .280');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(1990,$batter(),372),[],'1989-1991 lists outfielders only');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(2009,$batter(['pos'=>['SS'=>90,'3B'=>90],'h'=>100]),390),['SS'],'2009 per-position averages');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(2009,$batter(['pos'=>['DH'=>70,'1B'=>20]]),390),['DH'],'2009 designated hitter');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(2012,$batter(['pa'=>411]),412),[],'2012 needs the plate appearances');
expectRecordRoom(recordRoomGoldenGloveBatterCodes(2024,$batter(['fieldOuts'=>['LF'=>1000,'RF'=>1200,'1B'=>300],'dhPa'=>297]),446),['LF','RF','DH'],'2017+ innings and designated hitter');
$pitcher=static fn(array $over=[])=>$over+['wins'=>9,'saves'=>0,'holds'=>0,'outs'=>300,'er'=>40,'reliefWins'=>0];
expectRecordRoom(recordRoomGoldenGlovePitcher(1995,$pitcher(['wins'=>13,'outs'=>378]),378),true,'1995 thirteen wins with the innings');
expectRecordRoom(recordRoomGoldenGlovePitcher(1995,$pitcher(['wins'=>13,'outs'=>377]),378),false,'1995 short of the innings');
expectRecordRoom(recordRoomGoldenGlovePitcher(2000,$pitcher(['wins'=>5,'saves'=>36,'reliefWins'=>null,'outs'=>200,'er'=>30]),399),true,'2000 save points without relief wins');
expectRecordRoom(recordRoomGoldenGlovePitcher(2009,$pitcher(['saves'=>35,'outs'=>180,'er'=>20]),378),true,'2009 closer');
expectRecordRoom(recordRoomGoldenGlovePitcher(2009,$pitcher(['wins'=>14,'outs'=>300,'er'=>30]),378),false,'2009 wins need the innings');
expectRecordRoom(recordRoomGoldenGlovePitcher(2024,$pitcher(['holds'=>30]),432),true,'2017+ thirty holds');
expectRecordRoom(recordRoomFieldingAwardCodes(['2B'=>900,'SS'=>1300,'P'=>10],['2B'=>40,'SS'=>60]),['2B','SS'],'Fielding award: 720 innings in total');
expectRecordRoom(recordRoomFieldingAwardCodes(['1B'=>600,'3B'=>600,'LF'=>300,'RF'=>200],[]),['1B','3B','LF','RF'],'Fielding award: utility');
expectRecordRoom([recordRoomFieldingUtility(['1B'=>600,'3B'=>600,'LF'=>300,'RF'=>200]),recordRoomFieldingUtility(['2B'=>900,'SS'=>1300])],[true,false],'Utility condition');
$fieldRows=[['id'=>1,'name'=>'가','year'=>2025,'active'=>true,'foreign'=>false,'prospect'=>false,'pos'=>'SS','hand'=>'R','birth'=>1990,'v'=>2200,'need'=>2160.0,'utility'=>false,'stats'=>['fieldingPct'=>'0.990','games'=>100,'innings'=>'700']],['id'=>2,'name'=>'나','year'=>2025,'active'=>true,'foreign'=>false,'prospect'=>false,'pos'=>'2B','hand'=>'R','birth'=>1995,'v'=>1700,'need'=>2160.0,'utility'=>true,'stats'=>['fieldingPct'=>'0.980','games'=>40,'innings'=>'250.1']]];
expectRecordRoom($ids(recordRoomQuery($fieldRows,'fielding','fieldingPct',true,1.0,0,50)),[1],'Fielding award condition');
expectRecordRoom($ids(recordRoomQuery($fieldRows,'fielding','fieldingPct',true,0.0,0,50,null,['minUtility'=>true])),[2],'Utility as the minimum');
$fieldRows[0]['stats']['errors']=3;$fieldRows[1]['stats']['errors']=5;
expectRecordRoom($ids(recordRoomQuery($fieldRows,'fielding','errors',true,0.0,0,50,null,['minUtility'=>true])),[2,1],'Utility only on rate sorts by default');
expectRecordRoom($ids(recordRoomQuery($fieldRows,'fielding','errors',true,0.0,0,50,null,['minUtility'=>true,'minAll'=>true])),[2],'Utility on every sort when asked');
expectRecordRoom($ids(recordRoomQuery($fieldRows,'fielding','fieldingPct',true,0.0,0,50,750.0,['minUnit'=>'innings'])),[1,2],'Custom innings on the row');
expectRecordRoom($ids(recordRoomQuery($fieldRows,'fielding','fieldingPct',true,0.0,0,50,50.0,['minUnit'=>'games'])),[1],'Custom games on the row');
$fieldPart=static fn(array $over)=>$over+['games'=>0,'starts'=>0,'outs'=>0,'errors'=>0,'pickoffs'=>0,'putouts'=>0,'assists'=>0,'doublePlays'=>0,'passedBalls'=>0,'stolenBases'=>0,'caughtStealing'=>0];
$f=recordRoomFieldingMergedStats([['2루수',$fieldPart(['games'=>50,'outs'=>900,'errors'=>2,'putouts'=>80,'assists'=>118])],['유격수',$fieldPart(['games'=>30,'outs'=>301,'errors'=>3,'putouts'=>40,'assists'=>57])]]);
expectRecordRoom([$f['position'],$f['games'],$f['innings'],$f['errors'],$f['fieldingPct'],$f['passedBalls'],$f['caughtStealingPct']],['2개',80,'400.1',5,'0.983',null,null],'Merged infield positions');
$f=recordRoomFieldingMergedStats([['포수',$fieldPart(['games'=>90,'outs'=>2100,'passedBalls'=>4,'stolenBases'=>30,'caughtStealing'=>10])],['1루수',$fieldPart(['games'=>5,'outs'=>60])]]);
expectRecordRoom([$f['position'],$f['passedBalls'],$f['caughtStealingPct']],['2개',4,'25.0'],'Merged catcher keeps catcher-only columns');
expectRecordRoom(recordRoomFieldingAwardCodes(['C'=>1500,'P'=>144],['C'=>72]),['P','C'],'Fielding award: pitcher and catcher');
expectRecordRoom(recordRoomCandidatePart(['batter'=>[7=>['SS']],'pitcher'=>[],'award'=>[7=>['2B']]],'fielding',7,['fpos'=>'유격수'],true,false),true,'Fielding row of a candidate position');
expectRecordRoom(recordRoomCandidatePart(['batter'=>[7=>['SS']],'pitcher'=>[],'award'=>[7=>['2B']]],'fielding',7,['fpos'=>'유격수'],true,true),false,'Both filters must match');

// 기간 → 시즌별 구간: 정규시즌과 겹치는 부분만, 시즌 전체를 덮으면 full.
$schedule=['2025'=>['regular'=>['2025-03-22','2025-10-04']],'2024'=>['regular'=>['2024-03-23','2024-10-01']],'2000'=>['regular'=>['','']]];
expectRecordRoom(recordRoomPeriodSegments('2024-09-01','2025-04-30',$schedule),[[2024,'2024-09-01','2024-10-01',false],[2025,'2025-03-22','2025-04-30',false]],'Period spans two seasons');
expectRecordRoom(recordRoomPeriodSegments('2025-01-01','2025-12-31',$schedule),[[2025,'2025-03-22','2025-10-04',true]],'Whole season period');
expectRecordRoom(recordRoomPeriodSegments('2024-11-01','2025-02-01',$schedule),[],'Off-season period');
expectRecordRoom(recordRoomYearSegments([1999,2024,2023],$schedule),[[1999,'','',true],[2024,'2024-03-23','2024-10-01',true]],'Year segments');
[$where,$params]=recordRoomRange('2025-09-30','2025-10-04');
expectRecordRoom($params,['2025-09-30','2025-10-04','20250930','20251005'],'Index-friendly game id range');
echo "record_room_test OK\n";
