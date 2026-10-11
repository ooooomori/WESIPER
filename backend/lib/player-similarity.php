<?php
declare(strict_types=1);
require_once __DIR__.'/record-room.php';

/*
 * 유사 선수: 빌 제임스의 유사도 점수(Baseball-Reference와 같은 방식)를 1군 정규시즌 통산 기록으로 계산한다.
 * - 1000점에서 시작해 항목별 차이만큼 뺀다(타자 13개 항목 + 주 포지션, 투수 13개 항목 + 던지는 손).
 * - 기준(타자 1,000타석, 투수 300이닝 또는 150경기)을 채운 선수끼리만 비교한다.
 * - 통산 기록은 기록실의 통산 자료(recordRoomDataset)를 쓰고, 선수별 상위 목록을 파일에 보관한다.
 * - 한쪽이라도 모르는 항목(2000년 이전 투수의 선발 등판 등)은 그 항목만 비교에서 뺀다.
 * - 투수는 원래 공식을 KBO에 맞게 바꿨다: 평균자책점 대신 ERA+(시대 보정), 홀드 추가, 승률은 구원이 아닌 투수끼리만 비교.
 */
const SIMILARITY_VERSION = '2';
const SIMILARITY_MIN_PA = 1000;
const SIMILARITY_MIN_OUTS = 900;
const SIMILARITY_MIN_PITCH_GAMES = 150;
const SIMILARITY_LIMIT = 10;
// 1점이 깎이는 차이
const SIMILARITY_BAT_UNITS = ['games'=>20,'ab'=>75,'r'=>10,'h'=>15,'doubles'=>5,'triples'=>4,'hr'=>2,'rbi'=>10,'bb'=>25,'so'=>150,'sb'=>20,'avg'=>.001,'slg'=>.002];
const SIMILARITY_PITCH_UNITS = ['wins'=>1,'losses'=>2,'winPct'=>.002,'eraPlus'=>.5,'games'=>10,'starts'=>20,'complete'=>20,'outs'=>150,'h'=>50,'so'=>30,'bb'=>10,'shutouts'=>5,'saves'=>3,'holds'=>3];
// 승률·ERA+는 최대 100점까지만 깎는다.
const SIMILARITY_CAPS = ['winPct'=>100,'eraPlus'=>100];
// 주 포지션 값: 두 선수의 값 차이만큼 뺀다.
const SIMILARITY_POSITION_VALUES = ['C'=>240,'SS'=>168,'2B'=>132,'3B'=>84,'LF'=>48,'CF'=>48,'RF'=>48,'1B'=>12,'DH'=>0];
// 던지는 손이 다르면 빼는 점수: 둘 다 선발, 둘 다 구원, 선발과 구원
const SIMILARITY_HAND_PENALTY = [10,25,17.5];

function similarityQualified(array $row,bool $pitcher): bool {
    return $pitcher?(int)$row['v']>=SIMILARITY_MIN_OUTS||(int)($row['stats']['games']??0)>=SIMILARITY_MIN_PITCH_GAMES:(int)$row['v']>=SIMILARITY_MIN_PA;
}

/** 기록실 통산 자료의 한 줄 → 비교용 값. $throws는 투수의 던지는 손 표기(우투·좌언 등). */
function similarityVector(array $row,bool $pitcher,?string $throws=null): array {
    $stats=$row['stats'];$vector=[];
    foreach(array_keys($pitcher?SIMILARITY_PITCH_UNITS:SIMILARITY_BAT_UNITS) as $key){
        // 비율 기록은 정렬용(한 자리 더 계산한) 값을 쓴다.
        $value=$key==='outs'?$row['v']:($row['sort'][$key]??$stats[$key]??null);
        $vector[$key]=$value===null||$value===''?null:(float)$value;
    }
    if(!$pitcher){$vector['pos']=SIMILARITY_POSITION_VALUES[$row['pos']??'']??null;return $vector;}
    $vector['hand']=['우'=>'R','좌'=>'L'][mb_substr(trim((string)$throws),0,1)]??null;
    // 구원: 구원 등판이 선발보다 많고 등판당 4이닝 미만. 선발 등판 수를 모르면 이닝만 본다.
    $games=(float)($vector['games']??0);$short=$games>0&&$vector['outs']/$games<12;
    $vector['reliever']=$short&&($vector['starts']===null||$games-$vector['starts']>$vector['starts']);
    return $vector;
}

function similarityScore(array $a,array $b,bool $pitcher): float {
    $score=1000.0;
    foreach($pitcher?SIMILARITY_PITCH_UNITS:SIMILARITY_BAT_UNITS as $key=>$unit){
        if($a[$key]===null||$b[$key]===null)continue;
        // 구원 투수의 승률은 승패 수가 적어 우연에 가깝다.
        if($key==='winPct'&&($a['reliever']||$b['reliever']))continue;
        $score-=min(abs($a[$key]-$b[$key])/$unit,SIMILARITY_CAPS[$key]??INF);
    }
    if(!$pitcher){if($a['pos']!==null&&$b['pos']!==null)$score-=abs($a['pos']-$b['pos']);}
    elseif($a['hand']!==null&&$b['hand']!==null&&$a['hand']!==$b['hand'])$score-=SIMILARITY_HAND_PENALTY[$a['reliever']===$b['reliever']?(int)$a['reliever']:2];
    return $score;
}

/** 선수 ID → 가장 비슷한 선수 [[ID, 점수], ...] (점수 높은 순, 같으면 ID 순). */
function similarityTop(array $vectors,bool $pitcher,int $limit=SIMILARITY_LIMIT): array {
    $top=[];
    foreach($vectors as $id=>$vector){
        $list=[];
        foreach($vectors as $other=>$target)if($other!==$id)$list[]=[$other,round(similarityScore($vector,$target,$pitcher),1)];
        usort($list,static fn($x,$y)=>($y[1]<=>$x[1])?:($x[0]<=>$y[0]));
        $top[$id]=array_slice($list,0,$limit);
    }
    return $top;
}

/** 기록실의 통산 조회(playerRecords.php의 mode=career)와 같은 조건이라 보관된 통산 자료를 함께 쓴다. */
function similarityCareerRows(PDO $db,array $schedule,bool $pitcher): array {
    $filter=['career'=>true,'segments'=>recordRoomYearSegments(recordRoomYears($schedule),$schedule),'team'=>'','splitSeasons'=>false,'splitTeams'=>false,'ageMin'=>null,'ageMax'=>null,'versus'=>null,'stadium'=>'','opponent'=>'','goldenGlove'=>false,'award'=>false,'mergePositions'=>false,'season'=>'regular'];
    return recordRoomDataset($db,$filter,$pitcher?'pitcher':'batter',$schedule);
}

/** 기준을 채운 전체 선수의 유사 선수 목록. 크롤러 갱신(revision)이 바뀌면 다시 계산한다. */
function similarityIndex(PDO $db,array $schedule,bool $pitcher): array {
    $stamp=json_encode([SIMILARITY_VERSION,PROFILE_HISTORY_VERSION,PROFILE_TIEBREAKER_GAMES,profileRankingRevision()]);
    return recordRoomCached('sim-'.($pitcher?'p':'b'),$stamp,static function()use($db,$schedule,$pitcher){
        $throws=$pitcher?$db->query('SELECT player_id,`throw` FROM kbo_player_data')->fetchAll(PDO::FETCH_KEY_PAIR):[];
        $vectors=[];$players=[];
        foreach(similarityCareerRows($db,$schedule,$pitcher) as $row){
            if(!similarityQualified($row,$pitcher))continue;
            $vectors[$row['id']]=similarityVector($row,$pitcher,$throws[$row['id']]??null);
            $players[$row['id']]=['name'=>$row['name'],'team'=>$row['team'],'year'=>$row['year'],'active'=>$row['active']];
        }
        return ['players'=>$players,'top'=>similarityTop($vectors,$pitcher)];
    });
}

/** 한 선수의 유사 선수. 등록 포지션 쪽 기록이 기준에 못 미치면 다른 쪽(투수↔타자) 기록으로 찾고, 둘 다 없으면 null. */
function similarPlayers(PDO $db,int $pid,bool $pitcher,array $schedule): ?array {
    foreach([$pitcher,!$pitcher] as $kind){
        $index=similarityIndex($db,$schedule,$kind);
        if(empty($index['top'][$pid]))continue;
        $players=[];
        foreach($index['top'][$pid] as [$id,$score]){
            $player=$index['players'][$id];
            // 팀 이름은 기록에 적힌 그대로라 'kt'처럼 소문자인 경우가 있다.
            $players[]=['PlayerId'=>(int)$id,'Name'=>$player['name'],'Team'=>$player['team']===null?null:strtoupper($player['team']),'Year'=>$player['year'],'IsActive'=>$player['active'],'Score'=>$score];
        }
        return ['type'=>$kind?'pitcher':'batter','players'=>$players];
    }
    return null;
}
