<?php
declare(strict_types=1);

/*
 * 기록실의 '골든글러브 후보만'·'수비상 후보만' 필터.
 * 후보 여부는 선수의 그 시즌 전체 기록으로 정한다(기간·구장 조건으로 일부만 보는 조회에서도 같다).
 *
 * 골든글러브 후보 기준(1984년~, 시즌별로 다르다). 기준에 적히지 않은 부분은 이렇게 본다.
 * - 2000년까지는 포지션별 출장 수를 알 수 없어, 그 시즌 모든 경기를 주 포지션(kbo_player_season_positions)으로 뛴 것으로 본다.
 * - 2001~2016년의 '○경기 이상 출전'은 타석 기록에 남은 그 포지션 출장 경기 수로 센다.
 * - 2017년부터의 '해당 포지션 720이닝'은 공식 수비 기록의 이닝이다(외야수는 세 자리를 합친다).
 * - 규정타석·규정이닝은 그 시즌 팀 경기 수×3.1 / ×1.
 * - 2000~2002년 투수의 '방어율 ○ 이하'는 규정이닝을 채운 투수에게만 적용한다. 2003~2010년의 '규정이닝 이상이면서 … 15승 이상 또는 ○세이브'는
 *   평균자책점 기준을 모두에게, 규정이닝은 승수 쪽에만 건다(마무리 투수는 규정이닝을 채울 수 없으므로).
 * - 세이브포인트 = 세이브 + 구원승. 2000년은 구원승을 알 수 없어 15세이브 이상 투수의 승리를 모두 구원승으로 본다.
 * - 2000년부터는 그해 개인 타이틀 수상자도 후보다.
 * - 기준이 전해지지 않는 부분(1984~1985년 투수, 1989~1991년 외야수 외, 1992~1999년 지명타자)은 후보를 내지 않는다.
 *
 * 수비상 후보(2023년~, 수비 탭 전용): 48이닝 이상 던진 투수, 72경기 이상 나선 포수, 내·외야 수비 합계 720이닝 이상인 선수,
 * 그리고 합계 540이닝 이상이면서 50이닝 이상 본 포지션이 셋 이상인 선수(외야는 하나로 센다).
 */
const RECORD_ROOM_INFIELD = ['1B','2B','3B','SS'];
const RECORD_ROOM_OUTFIELD = ['LF','CF','RF'];
const RECORD_ROOM_GG_FIRST_YEAR = 1984;
const RECORD_ROOM_FIELDING_AWARD_FIRST_YEAR = 2023;

/** 타자 후보 기준: [포지션 묶음, 최소 경기, 최소 타율, 규정타석 필요, 방식('' | total = 포지션 무관 전체 경기 | dhMost = 지명타자로 가장 많이 출전)] */
function recordRoomGoldenGloveBatterRules(int $year): array {
    $each=static fn(array $averages,int $games,bool $pa)=>array_map(static fn($group,$average)=>[[$group],$games,$average,$pa,''],array_keys($averages),$averages);
    return match(true){
        $year<=1985=>[[['C','IF','OF','DH'],50,0.0,false,'']],
        $year===1986=>[[['ANY'],72,.250,false,'total']],
        $year===1987=>[[['C'],54,.220,false,''],[['IF'],72,.230,false,''],[['OF'],72,.280,false,''],[['DH'],72,.260,false,'']],
        $year===1988=>[[['C'],54,0.0,false,''],[['IF','OF','DH'],0,.240,true,'']],
        $year<=1991=>[[['OF'],0,.260,true,'']],
        $year<=1999=>[[['C','IF'],84,.230,false,''],[['OF'],84,.280,false,'']],
        $year===2000=>[[['C'],66,.250,false,''],[['IF'],88,.250,false,''],[['OF'],88,.260,false,''],[['DH'],0,.260,true,'dhMost']],
        $year<=2002=>[[['C'],66,.250,false,''],[['IF'],88,.240,false,''],[['OF'],88,.285,false,''],[['DH'],0,.300,true,'dhMost']],
        $year<=2004=>[[['C','IF'],88,.250,false,''],[['OF'],88,.290,false,''],[['DH'],0,.290,true,'dhMost']],
        $year===2005=>[[['C'],84,.245,false,''],[['IF'],84,.250,true,''],[['OF'],84,.275,true,''],[['DH'],0,.250,true,'dhMost']],
        $year===2006=>[[['C'],84,.250,false,''],[['IF','OF'],84,.250,true,''],[['DH'],0,.250,true,'dhMost']],
        $year===2007=>[[['C','IF'],84,.240,true,''],[['OF'],84,.250,true,''],[['DH'],0,.300,false,'dhMost']],
        $year<=2010=>[...$each(['C'=>.260,'1B'=>.260,'2B'=>.250,'3B'=>.270,'SS'=>.240,'OF'=>.300],84,false),[['DH'],63,.260,true,'dhMost']],
        $year<=2012=>[[['ANY'],88,0.0,true,'total']],
        $year<=2015=>[...$each(['C'=>.230,'1B'=>.280,'2B'=>.250,'3B'=>.280,'SS'=>.250,'OF'=>.280],85,true),[['DH'],85,.270,true,'']],
        $year===2016=>[[['C'],96,.290,false,''],[['1B','2B','3B','OF'],96,.310,true,''],[['SS'],96,.280,true,''],[['DH'],96,.300,true,'dhMost']],
        default=>[],
    };
}

/** 묶음(C·IF·OF·DH·개별 포지션)에 속한 포지션 코드 */
function recordRoomPositionGroup(string $group): array {
    return match($group){'IF'=>RECORD_ROOM_INFIELD,'OF'=>RECORD_ROOM_OUTFIELD,default=>[$group]};
}

/**
 * 타자 한 명의 후보 포지션 코드 목록(후보가 아니면 빈 배열). 포지션을 몰라도 되는 기준으로 뽑혔으면 ['ANY'].
 * $b: games, pa, ab, h, pos(포지션 코드=>경기 수), dhPa, fieldOuts(포지션 코드=>수비 아웃 수)
 */
function recordRoomGoldenGloveBatterCodes(int $year,array $b,int $regulationPa): array {
    if($year<RECORD_ROOM_GG_FIRST_YEAR)return [];
    $codes=[];
    if($year>=2017){
        foreach(['C',...RECORD_ROOM_INFIELD] as $code)if(($b['fieldOuts'][$code]??0)>=2160)$codes[]=$code;
        $outfield=0;foreach(RECORD_ROOM_OUTFIELD as $code)$outfield+=$b['fieldOuts'][$code]??0;
        if($outfield>=2160)foreach(RECORD_ROOM_OUTFIELD as $code)if(($b['fieldOuts'][$code]??0)>0)$codes[]=$code;
        if(($b['dhPa']??0)>=297)$codes[]='DH';
        return $codes;
    }
    $pos=$b['pos'];arsort($pos,SORT_NUMERIC);$primary=$pos?(string)array_key_first($pos):null;
    $average=$b['ab']>0?$b['h']/$b['ab']:null;
    $groupGames=static fn(string $group)=>array_sum(array_map(static fn($code)=>$pos[$code]??0,recordRoomPositionGroup($group)));
    $most=['C'=>$groupGames('C'),'IF'=>$groupGames('IF'),'OF'=>$groupGames('OF'),'DH'=>$groupGames('DH')];arsort($most,SORT_NUMERIC);
    foreach(recordRoomGoldenGloveBatterRules($year) as [$groups,$minGames,$minAverage,$needPa,$mode]){
        if($minAverage>0&&($average===null||$average<$minAverage-1e-9))continue;
        if($needPa&&$b['pa']<$regulationPa)continue;
        if($mode==='total'){if($b['games']>=$minGames)$codes[]=$primary??'ANY';continue;}
        foreach($groups as $group){
            $games=$groupGames($group);
            if($games<=0||$games<$minGames)continue;
            // 경기 수 조건이 없는 기준은 주 포지션이 그 묶음일 때만 해당한다.
            if($minGames===0&&$mode===''&&!in_array($primary,recordRoomPositionGroup($group),true))continue;
            if($mode==='dhMost'&&array_key_first($most)!=='DH')continue;
            foreach(recordRoomPositionGroup($group) as $code)if($group==='OF'||($pos[$code]??0)>0)$codes[]=$code;
        }
    }
    return array_values(array_unique($codes));
}

/** 투수 한 명이 후보인지. $p: wins, saves, holds, outs, er, reliefWins(모르면 null) */
function recordRoomGoldenGlovePitcher(int $year,array $p,int $regulationOuts): bool {
    if($year<1986)return false;
    $wins=(int)$p['wins'];$saves=(int)$p['saves'];$outs=(int)$p['outs'];
    $regulation=$regulationOuts>0&&$outs>=$regulationOuts;
    $era=$outs>0&&$p['er']!==null?$p['er']*27/$outs:null;$eraUnder=static fn(float $limit)=>$era!==null&&$era<=$limit+1e-9;
    $savePoints=$saves+($p['reliefWins']??($saves>=15?$wins:0));
    return match(true){
        $year===1986=>$regulation&&$wins>=10&&$eraUnder(2.70),
        $year===1987=>$regulation&&$wins>=12&&$eraUnder(3.10),
        $year===1988=>$regulation&&$wins>=10&&$eraUnder(3.60),
        $year<=1991=>$regulation&&$wins>=10&&$eraUnder(3.40),
        $year<=1999=>$regulation&&$wins>=13,
        $year===2000=>$wins>=15||$savePoints>=40||($regulation&&$eraUnder(4.00)),
        $year<=2002=>$wins>=15||$savePoints>=30||($regulation&&$eraUnder(3.50)),
        $year<=2004=>$eraUnder(3.60)&&(($regulation&&$wins>=15)||$savePoints>=35),
        $year===2005=>$eraUnder(3.00)&&(($regulation&&$wins>=15)||$saves>=30),
        $year===2006=>$eraUnder(3.00)&&(($regulation&&$wins>=15)||$saves>=35),
        $year===2007=>$eraUnder(3.00)&&(($regulation&&$wins>=15)||$saves>=30),
        $year<=2010=>$eraUnder(3.50)&&(($regulation&&$wins>=14)||$saves>=35),
        $year<=2012=>$eraUnder(3.00)&&($wins>=15||$saves>=25),
        $year<=2015=>$eraUnder(3.00)&&($wins>=14||$saves>=40),
        $year===2016=>$eraUnder(3.40)&&($wins>=15||$saves>=30),
        default=>$wins>=10||$saves>=30||(int)$p['holds']>=30||$regulation,
    };
}

/** 수비상 후보 포지션 코드. $outs·$games: 포지션 코드=>수비 아웃 수·경기 수 */
function recordRoomFieldingAwardCodes(array $outs,array $games): array {
    $codes=[];
    if(($outs['P']??0)>=144)$codes[]='P';
    if(($games['C']??0)>=72)$codes[]='C';
    $total=0;$played=[];
    foreach([...RECORD_ROOM_INFIELD,...RECORD_ROOM_OUTFIELD] as $code){$value=$outs[$code]??0;$total+=$value;if($value>0)$played[]=$code;}
    if($total>=2160||recordRoomFieldingUtility($outs))array_push($codes,...$played);
    return $codes;
}

/** 수비상 유틸리티 조건: 내·외야 수비 합계 540이닝 이상이면서 50이닝 이상 본 포지션이 셋 이상(외야는 하나로 센다). */
function recordRoomFieldingUtility(array $outs): bool {
    $total=0;$outfield=0;$qualified=0;
    foreach(RECORD_ROOM_INFIELD as $code){$value=$outs[$code]??0;$total+=$value;if($value>=150)$qualified++;}
    foreach(RECORD_ROOM_OUTFIELD as $code){$value=$outs[$code]??0;$total+=$value;$outfield+=$value;}
    if($outfield>=150)$qualified++;
    return $total>=1620&&$qualified>=3;
}

/**
 * 한 시즌의 후보 명단: ['batter'=>[선수 ID=>[포지션 코드,...]], 'pitcher'=>[선수 ID=>true], 'award'=>[선수 ID=>[포지션 코드,...]]]
 * 시즌 전체 자료(연도별 보관 파일)로 계산해 파일에 둔다.
 */
function recordRoomSeasonCandidates(PDO $db,int $year,array $schedule): array {
    static $memo=[];
    if(isset($memo[$year]))return $memo[$year];
    $empty=['batter'=>[],'pitcher'=>[],'award'=>[]];
    $segment=recordRoomYearSegments([$year],$schedule)[0]??null;
    if($year<RECORD_ROOM_GG_FIRST_YEAR||$segment===null)return $memo[$year]=$empty;
    return $memo[$year]=recordRoomCached('gg-'.$year.'-'.substr(hash('sha256',json_encode($segment)),0,12),recordRoomStamp($year>=recordRoomCurrentYear()),static function()use($db,$year,$segment,$schedule,$empty){
        $out=$empty;
        $fieldOuts=[];$fieldGames=[];
        if($year>=PROFILE_FIELDING_FIRST_YEAR)foreach(recordRoomSegmentData($db,$segment,'fielding',$schedule)['players'] as $id=>$player)foreach($player['parts'] as $part){
            $code=RECORD_ROOM_FIELDING_CODES[$part['fpos']]??$part['fpos'];
            $fieldOuts[$id][$code]=($fieldOuts[$id][$code]??0)+(int)$part['s']['outs'];$fieldGames[$id][$code]=($fieldGames[$id][$code]??0)+(int)$part['s']['games'];
        }
        $titles=['batter'=>[],'pitcher'=>[]];
        if($year>=2000)try{
            $q=$db->prepare('SELECT player_id,type FROM kbo_player_titleholder WHERE year=?');$q->execute([$year]);
            while($row=$q->fetch(PDO::FETCH_ASSOC))$titles[in_array($row['type'],['다승','평균자책점','탈삼진','세이브','홀드','승률'],true)?'pitcher':'batter'][(int)$row['player_id']]=true;
        }catch(Throwable $error){error_log('Title holders unavailable: '.$error->getMessage());}
        $batters=recordRoomSegmentData($db,$segment,'batter',$schedule);$regulationPa=(int)floor($batters['maxTg']*3.1);
        foreach($batters['players'] as $id=>$player){
            $b=['games'=>0,'pa'=>0,'ab'=>0,'h'=>0,'pos'=>[],'dhPa'=>0,'fieldOuts'=>$fieldOuts[$id]??[]];
            foreach($player['parts'] as $part){
                foreach(['games','pa','ab','h'] as $key)$b[$key]+=(int)($part['s'][$key]??0);
                $b['dhPa']+=(int)($part['s']['dhPa']??0);
                foreach($part['pos']??[] as $code=>$games)$b['pos'][$code]=($b['pos'][$code]??0)+$games;
            }
            $codes=recordRoomGoldenGloveBatterCodes($year,$b,$regulationPa);
            if(!$codes&&isset($titles['batter'][$id])){arsort($b['pos'],SORT_NUMERIC);$codes=[$b['pos']?(string)array_key_first($b['pos']):'ANY'];}
            if($codes)$out['batter'][$id]=$codes;
        }
        $pitchers=recordRoomSegmentData($db,$segment,'pitcher',$schedule);$regulationOuts=$pitchers['maxTg']*3;
        foreach($pitchers['players'] as $id=>$player){
            $p=['wins'=>0,'saves'=>0,'holds'=>0,'outs'=>0,'er'=>0,'reliefWins'=>0];
            foreach($player['parts'] as $part)foreach($p as $key=>$value)$p[$key]=$value===null||!isset($part['s'][$key])?null:$value+$part['s'][$key];
            foreach(['wins','saves','holds','outs'] as $key)$p[$key]=(int)$p[$key];
            if(recordRoomGoldenGlovePitcher($year,$p,$regulationOuts)||isset($titles['pitcher'][$id]))$out['pitcher'][$id]=true;
        }
        if($year>=RECORD_ROOM_FIELDING_AWARD_FIRST_YEAR)foreach($fieldOuts as $id=>$outs){$codes=recordRoomFieldingAwardCodes($outs,$fieldGames[$id]??[]);if($codes)$out['award'][$id]=$codes;}
        return $out;
    });
}

/** 구간 자료의 한 줄(part)이 후보 조건에 맞는지. $goldenGlove·$award는 켜진 필터. */
function recordRoomCandidatePart(array $candidates,string $type,int $id,array $part,bool $goldenGlove,bool $award): bool {
    if($type!=='fielding')return !$goldenGlove||isset($candidates[$type][$id]);
    $code=RECORD_ROOM_FIELDING_CODES[$part['fpos']]??$part['fpos'];
    if($goldenGlove&&!($code==='P'?isset($candidates['pitcher'][$id]):in_array($code,$candidates['batter'][$id]??[],true)))return false;
    return !$award||in_array($code,$candidates['award'][$id]??[],true);
}
