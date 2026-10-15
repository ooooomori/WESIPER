<?php
declare(strict_types=1);

/*
 * 기록실 HOF 탭: 블랙잉크·그레이잉크·HOF 스탠다드·HOF 모니터.
 * 계산식은 나무위키 '명예의 전당 통계' 문서를 그대로 따른다(KBO에 맞춘 수치 조정 없음). 1군 정규시즌 1982년~올해.
 *
 * 블랙잉크: 그 시즌 리그 1위(공동 포함) 기록마다, 그레이잉크: 리그 10위 이내 기록마다 항목별 점수를 더한다.
 *   비율 기록(타율·출루율·장타율·평균자책점·BB/9·H/9)은 규정 타석·이닝을 채운 선수만 비교하고, 승률은 10승 이상만 비교한다.
 *   낮을수록 좋은 기록은 평균자책점·BB/9·H/9다. 값이 0인 개수 기록은 1위로 치지 않는다.
 * HOF 스탠다드: 통산 기록 점수. 문서에 최소 조건이 적힌 투수 비율 항목은 통산 500이닝 이상만 센다.
 *   타자 비율 항목(타율·장타율·출루율·경기당 득점·경기당 타점·홈런/안타)은 통산 2,000타석 이상만 세고, 포지션 가산점은 다른 점수가 있을 때만 더한다.
 *   포지션 가산점은 문서의 James's Version(포수 20, 유격수 16, 2루수 14, 3루수 13, 중견수 12, 우익수 6, 좌익수 3, 1루수 1, 지명타자 0)이다.
 * HOF 모니터: 시즌별 점수의 합. 문서에 적힌 항목만 센다(타자: 타율·100타점/100득점·홈런, 투수: 승리·탈삼진).
 */
const RECORD_ROOM_HOF_VERSION = '2';
const RECORD_ROOM_HOF_BAT_MIN_PA = 2000;
const RECORD_ROOM_HOF_PITCH_MIN_OUTS = 1500;

/** [열, 점수, 낮을수록 좋음, 비율 기록(규정 필요), 그레이잉크에만] */
function recordRoomHofInkItems(string $role): array {
    return $role==='pitcher'
        ? [['wins',4,false,false,false],['era',4,true,true,false],['so',4,false,false,false],['outs',3,false,false,false],['winPct',3,false,false,false],['saves',3,false,false,false],
            ['complete',2,false,false,false],['bb9',2,true,true,false],['h9',2,true,true,false],['shutouts',1,false,false,false],['games',1,false,false,false],['starts',1,false,false,false]]
        : [['hr',4,false,false,false],['rbi',4,false,false,false],['avg',4,false,true,false],['r',3,false,false,false],['h',3,false,false,false],['slg',3,false,true,false],['obp',3,false,true,true],
            ['doubles',2,false,false,false],['bb',2,false,false,false],['sb',2,false,false,false],['games',1,false,false,false],['ab',1,false,false,false],['triples',1,false,false,false]];
}

function recordRoomHofStep(float $value,float $start,float $step): int {
    return $value+1e-9<$start?0:(int)floor(($value-$start)/$step+1e-9);
}

/** 타자 HOF 스탠다드: [점수, 포지션 가산점 제외 점수] */
function recordRoomHofBatterStandard(array $c,?string $pos): float {
    $ab=$c['ab'];$g=max(1,$c['games']);$rate=$c['pa']>=RECORD_ROOM_HOF_BAT_MIN_PA&&$ab>0;
    $tb=$c['h']+$c['doubles']+2*$c['triples']+3*$c['hr'];$xbh=$c['doubles']+$c['triples']+$c['hr'];
    $p=0.0;
    if($c['h']>=1500)$p+=min(10,intdiv($c['h']-1500,150)+1);
    if($c['r']>=900)$p+=min(8,intdiv($c['r']-900,100)+1);
    if($c['rbi']>=800)$p+=min(8,intdiv($c['rbi']-800,100)+1);
    $p+=intdiv($c['hr'],200);
    if($xbh>=300)$p+=min(5,intdiv($xbh-300,200)+1);
    if($c['bb']>=300)$p+=min(5,intdiv($c['bb']-300,200)+1);
    $p+=min(5,intdiv($c['sb'],100));
    if($rate){
        $avg=$c['h']/$ab;$slg=$tb/$ab;$den=$ab+$c['bb']+$c['hbp']+$c['sf'];$obp=$den?($c['h']+$c['bb']+$c['hbp'])/$den:0;
        if($avg>.275)$p+=min(9,recordRoomHofStep($avg,.275,.005));
        if($avg>.300)$p+=1;
        if($slg>.300)$p+=min(10,recordRoomHofStep($slg,.300,.025));
        if($obp>.300)$p+=min(10,recordRoomHofStep($obp,.300,.010));
        $rg=$c['r']/$g;$bg=$c['rbi']/$g;
        if($rg>.5)$p+=1;if($rg>.644)$p+=1;
        if($bg>.5)$p+=1;if($bg>.6)$p+=1;
        if($c['h']>0){$hrh=$c['hr']/$c['h'];if($hrh>=.10)$p+=1;if($hrh>=.20)$p+=1;}
    }
    if($p>0)$p+=['C'=>20,'SS'=>16,'2B'=>14,'3B'=>13,'CF'=>12,'RF'=>6,'LF'=>3,'1B'=>1,'DH'=>0][$pos??'']??0;
    return $p;
}

function recordRoomHofPitcherStandard(array $c): float {
    $outs=max(0,$c['outs']);$w=$c['wins'];$l=$c['losses'];$p=0.0;
    if($w>=100)$p+=min(25,intdiv($w-100,10)+1);
    if($w>$l)$p+=min(10,intdiv($w-$l,20));
    if($outs>=RECORD_ROOM_HOF_PITCH_MIN_OUTS&&$w+$l>0){
        $pct=$w/($w+$l);$era=$c['er']*27/$outs;$bb9=$c['bb']*27/$outs;$h9=$c['h']*27/$outs;
        if($pct>.5)$p+=min(15,recordRoomHofStep($pct,.5,.013));
        if($era<4.0)$p+=min(10,recordRoomHofStep(4.0-$era,0,.2));
        if($c['so']>=1000)$p+=min(10,intdiv($c['so']-1000,200)+1);
        if($bb9<4.0)$p+=min(10,recordRoomHofStep(4.0-$bb9,0,.3));
        if($h9<10.0)$p+=min(10,recordRoomHofStep(10.0-$h9,0,.3));
    }
    $ip=intdiv($outs,3);
    if($ip>=1000)$p+=min(5,intdiv($ip-1000,1000)+1);
    if($c['complete']>=200)$p+=min(5,intdiv($c['complete']-200,100)+1);
    $p+=min(5,intdiv($c['shutouts'],30));
    return $p;
}

function recordRoomHofBatterMonitor(array $s): float {
    $p=0.0;$g=(int)($s['games']??0);$avg=(float)($s['avg']??0);
    if($g>=100){if($avg>=.400)$p+=15;elseif($avg>=.350)$p+=5;elseif($avg>=.300)$p+=2.5;}
    if((int)($s['rbi']??0)>=100||(int)($s['r']??0)>=100)$p+=3;
    $hr=(int)($s['hr']??0);
    if($hr>=50)$p+=10;elseif($hr>=40)$p+=4;elseif($hr>=30)$p+=2;
    return $p;
}

function recordRoomHofPitcherMonitor(array $s): float {
    $p=0.0;$w=(int)($s['wins']??0);$so=(int)($s['so']??0);
    foreach([30=>15,25=>10,23=>8,20=>6,18=>4,15=>2] as $limit=>$points)if($w>=$limit){$p+=$points;break;}
    foreach([300=>6,250=>3,200=>2] as $limit=>$points)if($so>=$limit){$p+=$points;break;}
    return $p;
}

function recordRoomHofNumber(mixed $value): ?float {
    return $value===null||$value===''?null:(float)$value;
}

/** 한 시즌·한 기록 종류의 잉크: [선수 ID => [블랙, 그레이]] */
function recordRoomHofInk(array $rows,string $role): array {
    $ink=[];
    foreach(recordRoomHofInkItems($role) as [$key,$points,$lower,$rate,$grayOnly]){
        $list=[];
        foreach($rows as $row){
            $stats=$row['stats'];
            if($key==='outs'){$value=(float)$row['v'];}
            else{$value=recordRoomHofNumber($stats[$key]??null);if($value===null)continue;}
            if($rate&&!($row['need']>0&&$row['v']>=floor($row['need'])))continue;
            if($key==='winPct'&&(int)($stats['wins']??0)<10)continue;
            if(!$lower&&$value<=0)continue;
            $list[]=[(int)$row['id'],$value];
        }
        if(!$list)continue;
        usort($list,static fn($a,$b)=>$lower?$a[1]<=>$b[1]:$b[1]<=>$a[1]);
        $rank=0;$last=null;
        foreach($list as $index=>[$id,$value]){
            if($last===null||abs($value-$last)>1e-9)$rank=$index+1;$last=$value;
            if($rank>10)break;
            $ink[$id]??=[0,0];
            if($rank===1&&!$grayOnly)$ink[$id][0]+=$points;
            $ink[$id][1]+=$points;
        }
    }
    return $ink;
}

function recordRoomHofCompute(PDO $db,array $schedule): array {
    @set_time_limit(0);ini_set('memory_limit','1024M');
    $years=recordRoomYears($schedule);
    $bat=[];$pit=[];
    $zero=static fn(array $keys)=>array_fill_keys($keys,0);
    foreach($years as $year){
        $filter=['career'=>false,'segments'=>recordRoomYearSegments([$year],$schedule),'team'=>'','splitSeasons'=>false,'splitTeams'=>false,'ageMin'=>null,'ageMax'=>null,'versus'=>null,'stadium'=>'','opponent'=>''];
        if(!$filter['segments'])continue;
        foreach(['batter','pitcher'] as $role){
            $rows=recordRoomDataset($db,$filter,$role,$schedule);
            $ink=recordRoomHofInk($rows,$role);
            foreach($rows as $row){
                $id=(int)$row['id'];$s=$row['stats'];
                if($role==='batter'){
                    $e=&$bat[$id];
                    $e??=['id'=>$id,'sum'=>$zero(['games','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','sf','sb']),'pos'=>[],'black'=>0,'gray'=>0,'monitor'=>0.0,'seasons'=>0];
                    foreach($e['sum'] as $key=>$_)$e['sum'][$key]+=(int)($s[$key]??0);
                    if($row['pos'])$e['pos'][$row['pos']]=($e['pos'][$row['pos']]??0)+max(1,(int)($s['games']??1));
                    $e['monitor']+=recordRoomHofBatterMonitor($s);
                }else{
                    $e=&$pit[$id];
                    $e??=['id'=>$id,'sum'=>$zero(['games','starts','wins','losses','saves','outs','er','so','h','bb','complete','shutouts']),'black'=>0,'gray'=>0,'monitor'=>0.0,'seasons'=>0];
                    foreach($e['sum'] as $key=>$_)$e['sum'][$key]+=(int)($key==='outs'?$row['v']:($s[$key]??0));
                    $e['monitor']+=recordRoomHofPitcherMonitor($s);
                }
                $e['seasons']++;$e['name']=$row['name'];$e['team']=$row['team'];$e['active']=(bool)$row['active'];$e['first']=min($e['first']??$year,$year);$e['last']=$year;
                if(isset($ink[$id])){$e['black']+=$ink[$id][0];$e['gray']+=$ink[$id][1];}
                unset($e);
            }
        }
    }
    $out=['batter'=>[],'pitcher'=>[]];
    foreach($bat as $e){
        arsort($e['pos'],SORT_NUMERIC);$pos=$e['pos']?(string)array_key_first($e['pos']):null;
        $out['batter'][]=['id'=>$e['id'],'name'=>$e['name'],'team'=>$e['team'],'pos'=>$pos,'active'=>$e['active'],'first'=>$e['first'],'last'=>$e['last'],'seasons'=>$e['seasons'],
            'blackInk'=>$e['black'],'grayInk'=>$e['gray'],'standard'=>recordRoomHofBatterStandard($e['sum'],$pos),'monitor'=>$e['monitor']];
    }
    foreach($pit as $e){
        $s=$e['sum'];
        $out['pitcher'][]=['id'=>$e['id'],'name'=>$e['name'],'team'=>$e['team'],'pos'=>$s['starts']*2>$s['games']?'SP':'RP','active'=>$e['active'],'first'=>$e['first'],'last'=>$e['last'],'seasons'=>$e['seasons'],
            'blackInk'=>$e['black'],'grayInk'=>$e['gray'],'standard'=>recordRoomHofPitcherStandard($s),'monitor'=>$e['monitor']];
    }
    return $out;
}

/** 전체 선수 HOF 기록(타자·투수). 지난 시즌은 계속 쓰고, 올해 기록은 갱신 때 다시 계산한다. */
function recordRoomHof(PDO $db,array $schedule): array {
    return recordRoomCached('hof-v'.RECORD_ROOM_HOF_VERSION,recordRoomStamp(true),static fn()=>recordRoomHofCompute($db,$schedule));
}
