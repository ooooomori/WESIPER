<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';
require_once __DIR__.'/player-rankings.php';
require_once __DIR__.'/player-year-metrics.php';
require_once __DIR__.'/player-year-league.php';
require_once __DIR__.'/player-batting-league.php';
require_once __DIR__.'/player-season-totals.php';
require_once __DIR__.'/player-fielding-records.php';
require_once __DIR__.'/record-room-awards.php';

/*
 * 선수 기록실: 1군 정규시즌 전체 선수의 기록을 조건(연도·연도 범위·통산·기간)에 맞춰 합산한다.
 * - 구간(segment) = 한 시즌 전체 또는 시즌 안의 날짜 범위. 구간마다 선수별 원시 합계를 만들고,
 *   여러 구간은 합계를 더한 뒤 비율 기록을 다시 계산한다(비율을 평균하지 않는다).
 * - 2001년 이후는 경기 기록, 1982~2000년은 시즌 합계 자료를 쓴다. 날짜 범위는 2001년 이후만 가능하다.
 * - 시즌 전체 구간은 연도별 파일에 보관한다. 지난 시즌은 PROFILE_HISTORY_VERSION이 같으면 계속 쓴다.
 * 계산식은 선수 프로필의 연도별 기록(player-year-records.php)과 같다.
 */
const RECORD_ROOM_VERSION = '9';
const RECORD_ROOM_BAT_FIELDS = ['games','starts','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','so','sf','sh','ibb','tb','roe','go','fo','runOut','eab','eh','etb','eob'];
const RECORD_ROOM_PITCH_FIELDS = ['games','starts','wins','losses','saves','holds','outs','r','er','so','h','hr','bb','hbp','pa','ab','sf','tb','go','fo','startOuts','reliefOuts','finishes','qs','qsPlus','ds','complete','shutouts','pitches','eraW','fipW'];
const RECORD_ROOM_BAT_KEYS = ['games','starts','avg','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','so','gdp','sf','sh','sb','cs','obp','slg','ops','effectiveOps','opsPlus','woba','isoObp','isoSlg','babip','groundFly','bbPct','kPct','bbK','spd','sbAttempts','sbPct','runOut'];
const RECORD_ROOM_PITCH_KEYS = ['games','starts','era','wins','losses','saves','holds','innings','r','er','so','h','hr','bb','hbp','winPct','whip','fip','eraPlus','k9','bb9','kPct','bbPct','kBb','opponentAvg','opponentObp','opponentSlg','opponentOps','h9','hr9','groundFly','babip','reliefs','finishes','starterInnings','reliefInnings','pitches','pitchesPerInning','pitchesPerGame','qs','qsPlus','ds','complete','shutouts'];
// 비율 기록: 이 열로 정렬할 때만 최소 조건(규정 타석·이닝 비율)을 적용한다.
const RECORD_ROOM_BAT_RATES = ['avg','obp','slg','ops','effectiveOps','opsPlus','woba','isoObp','isoSlg','babip','groundFly','bbPct','kPct','bbK','sbPct'];
const RECORD_ROOM_PITCH_RATES = ['era','winPct','whip','fip','eraPlus','k9','bb9','kPct','bbPct','kBb','opponentAvg','opponentObp','opponentSlg','opponentOps','h9','hr9','groundFly','babip','pitchesPerInning','pitchesPerGame'];
const RECORD_ROOM_FIELD_KEYS = ['games','starts','innings','errors','putouts','assists','doublePlays','fieldingPct','pickoffs','passedBalls','stolenBases','caughtStealing','caughtStealingPct'];
// 특정 타자를 상대한 투수 기록: 타석 결과로 알 수 있는 항목만 있다(이닝·실점 없음).
const RECORD_ROOM_VERSUS_KEYS = ['games','pa','ab','h','hr','bb','hbp','so','opponentAvg','opponentObp','opponentSlg','opponentOps','kPct','bbPct','kBb','groundFly','babip'];
const RECORD_ROOM_KEYS = ['batter'=>RECORD_ROOM_BAT_KEYS,'pitcher'=>RECORD_ROOM_PITCH_KEYS,'fielding'=>RECORD_ROOM_FIELD_KEYS,'versus'=>RECORD_ROOM_VERSUS_KEYS];
const RECORD_ROOM_RATES = ['batter'=>RECORD_ROOM_BAT_RATES,'pitcher'=>RECORD_ROOM_PITCH_RATES,'fielding'=>['fieldingPct','caughtStealingPct'],'versus'=>['opponentAvg','opponentObp','opponentSlg','opponentOps','kPct','bbPct','kBb','groundFly','babip']];
const RECORD_ROOM_INNING_KEYS = ['innings','starterInnings','reliefInnings'];
// 통산 비율 기록 기준(KBO 통산 순위 기준): 3,000타석 · 1,000이닝
const RECORD_ROOM_CAREER_PA = 3000;
const RECORD_ROOM_CAREER_OUTS = 3000;

/**
 * 이번 요청이 보는 시즌 종류: regular(정규시즌) | preseason(시범경기) | postseason(포스트시즌) | futures(퓨처스리그).
 * 요청마다 한 종류만 다루므로 API가 처음에 한 번 정해 두고, 집계 함수들이 읽는다. 퓨처스리그만 league_level이 2다.
 */
function recordRoomSeason(?string $set=null): string { static $season='regular';if($set!==null)$season=$set;return $season; }
function recordRoomLevel(): int { return recordRoomSeason()==='futures'?2:1; }

function recordRoomCurrentYear(): int { return (int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y'); }
function recordRoomToday(): string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d'); }

/** 기록실에서 고를 수 있는 연도(1982년~올해). */
function recordRoomYears(array $schedule): array {
    $current=recordRoomCurrentYear();$years=range(1982,2000);
    foreach($schedule as $year=>$season)if((int)$year<=$current&&($season['regular'][0]??'')!=='')$years[]=(int)$year;
    $years=array_values(array_unique($years));sort($years);return $years;
}

/** 파일 보관: stamp가 같으면 저장된 값을 쓰고, 다르면 다시 계산한다. */
function recordRoomCached(string $name,string $stamp,callable $compute,bool $prune=false): array {
    $dir=sys_get_temp_dir().'/wesiper-record-room-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Record room cache unavailable');
    $path="$dir/v".RECORD_ROOM_VERSION."-$name.json";$lock=fopen("$path.lock",'c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Record room cache lock unavailable');
    try{
        if(is_file($path)){$cached=json_decode((string)file_get_contents($path),true);if(is_array($cached)&&($cached['stamp']??null)===$stamp&&is_array($cached['data']??null))return $cached['data'];}
        $data=$compute();
        $tmp=tempnam($dir,'room-');
        if($tmp!==false){try{if(file_put_contents($tmp,json_encode(['stamp'=>$stamp,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))!==false)rename($tmp,$path);}finally{if(is_file($tmp))unlink($tmp);}}
        // 날짜 범위·조건 조합별 파일이 쌓이지 않게, 이틀 넘게 안 쓴 조회 결과는 지운다.
        if($prune&&random_int(1,20)===1)foreach(glob("$dir/v*-q*-*.json*")?:[] as $old)if(@filemtime($old)<time()-172800)@unlink($old);
        return $data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** 올해가 낀 자료는 크롤러 갱신(revision)·날짜가 바뀌면 다시 계산하고, 지난 시즌만이면 계속 쓴다. */
function recordRoomStamp(bool $current): string {
    return json_encode([PROFILE_HISTORY_VERSION,PROFILE_TIEBREAKER_GAMES,$current?[profileRankingRevision(),recordRoomToday()]:'final']);
}

function recordRoomSum(array $parts,array $fields): array {
    $out=[];
    foreach($fields as $key){$value=0;foreach($parts as $part){if(!isset($part[$key])){$value=null;break;}$value+=$part[$key];}$out[$key]=$value;}
    return $out;
}
function recordRoomKnown(array $s,string ...$keys): bool { foreach($keys as $key)if(!isset($s[$key]))return false;return true; }

/**
 * 경기 기록 표의 구간 조건. 날짜에는 리그 전체를 훑을 인덱스가 없어서, 날짜로 시작하는 game_id 범위를 함께 걸어
 * (league_level, game_id) 인덱스를 타게 한다. 정규시즌 기간에 game_id가 날짜와 다른 경기는 순위 결정전뿐이고, 어차피 제외한다.
 */
function recordRoomRange(string $start,string $end,?PDO $db=null): array {
    $next=(new DateTimeImmutable($end))->modify('+1 day')->format('Ymd');
    return ['league_level='.recordRoomLevel().' AND game_date BETWEEN ? AND ? AND game_id>=? AND game_id<?'.profileNotTiebreakerSql().($db?recordRoomAllStarSql($db,$start,$end):''),[$start,$end,str_replace('-','',$start),$next]];
}
/** 퓨처스 올스타전(남부·북부)은 기록에서 뺀다. 1군 일정표에는 올스타전이 없다. */
function recordRoomAllStarSql(PDO $db,string $start,string $end): string {
    if(recordRoomLevel()!==2)return '';
    $q=$db->prepare('SELECT game_code FROM kbo_schedule WHERE league_level=2 AND is_allstar=1 AND game_date BETWEEN ? AND ?');$q->execute([$start,$end]);
    $codes=$q->fetchAll(PDO::FETCH_COLUMN);
    return $codes?' AND game_id NOT IN ('.implode(',',array_map(static fn($code)=>$db->quote((string)$code),$codes)).')':'';
}

/** 구간 안에서 끝난 경기 수(팀별). 규정 타석·이닝의 기준이다. */
function recordRoomTeamGames(PDO $db,string $start,string $end): array {
    $q=$db->prepare('SELECT away_team,home_team,COUNT(*) n FROM kbo_schedule WHERE league_level='.recordRoomLevel().' AND game_date BETWEEN ? AND ? AND is_allstar=0 AND away_score IS NOT NULL AND home_score IS NOT NULL'.profileNotTiebreakerSql('game_code').' GROUP BY away_team,home_team');
    $q->execute([$start,$end]);$games=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC))foreach([$row['away_team'],$row['home_team']] as $team){$team=(string)profileTeam($team);$games[$team]=($games[$team]??0)+(int)$row['n'];}
    return $games;
}

/** 타석 기록의 수비 위치 표기 → 포지션 코드(profileYearPosition과 같은 표). */
const RECORD_ROOM_POSITION_CODES = ['2'=>'C','3'=>'1B','4'=>'2B','5'=>'3B','6'=>'SS','7'=>'LF','8'=>'CF','9'=>'RF','D'=>'DH','지'=>'DH','포'=>'C','一'=>'1B','二'=>'2B','三'=>'3B','유'=>'SS','좌'=>'LF','중'=>'CF','우'=>'RF'];
const RECORD_ROOM_FIELDING_CODES = ['투수'=>'P','포수'=>'C','1루수'=>'1B','2루수'=>'2B','3루수'=>'3B','유격수'=>'SS','좌익수'=>'LF','중견수'=>'CF','우익수'=>'RF'];
const RECORD_ROOM_POSITION_GROUPS = ['OF'=>['LF','CF','RF'],'IF'=>['1B','2B','3B','SS']];

/** 팀 필터·팀별 보기용 구단 계보: 이름이 바뀐 팀은 한 구단으로 묶는다. */
function recordRoomFranchise(?string $team,?int $year=null): string {
    $team=trim((string)$team);
    // 퓨처스리그의 '고양'은 2018년까지 NC의 퓨처스 팀(고양 다이노스), 2019년부터 고양 히어로즈다. '화성'은 2014~2018년의 히어로즈 퓨처스 팀이다.
    if($team==='고양'&&$year!==null&&$year<=2018)return 'NC';
    return match(strtoupper($team)){
        'KIA','HT','해태'=>'KIA','두산','OB'=>'두산','LG','MBC'=>'LG','삼성','SS'=>'삼성','롯데','LT'=>'롯데','한화','HH','빙그레'=>'한화',
        'SSG','SK'=>'SSG','키움','WO','넥센','히어로즈','우리','우리히어로즈','서울히어로즈','고양','화성'=>'키움','NC'=>'NC','KT'=>'KT',
        '현대','태평양','청보','삼미'=>'현대','쌍방울'=>'쌍방울',default=>$team,
    };
}

/**
 * 구장·상대팀 조건에 맞는 경기 묶음(2001년 이후 일정표 기준).
 * ids: 경기 기록의 game_id(옛 기록은 일정표 코드 그대로, 요즘 기록은 코드 뒤에 연도가 붙는다), teamGames: 그 묶음 안의 팀별 끝난 경기 수,
 * exclude: 상대팀으로 지정한 구단(그 구단 선수는 결과에서 뺀다).
 */
function recordRoomGameSubset(PDO $db,string $start,string $end,string $stadium,string $opponent): array {
    $q=$db->prepare('SELECT game_code,YEAR(game_date) y,away_team,home_team,stadium,away_score,home_score FROM kbo_schedule WHERE league_level='.recordRoomLevel().' AND is_allstar=0 AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql('game_code'));$q->execute([$start,$end]);
    $ids=[];$teamGames=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        if($stadium!==''&&trim((string)$row['stadium'])!==$stadium)continue;
        if($opponent!==''&&recordRoomFranchise($row['away_team'],(int)$row['y'])!==$opponent&&recordRoomFranchise($row['home_team'],(int)$row['y'])!==$opponent)continue;
        $ids[]=(string)$row['game_code'];$ids[]=$row['game_code'].$row['y'];
        if(isset($row['away_score'],$row['home_score']))foreach([$row['away_team'],$row['home_team']] as $team){$team=(string)profileTeam($team);$teamGames[$team]=($teamGames[$team]??0)+1;}
    }
    return ['ids'=>$ids,'teamGames'=>$teamGames,'exclude'=>$opponent];
}
function recordRoomGameIdSql(PDO $db,array $ids): string { return 'game_id IN ('.implode(',',array_map(static fn($id)=>$db->quote((string)$id),$ids)).')'; }

/*
 * 구간 자료의 모양: ['maxTg'=>그 구간 최다 팀 경기 수, 'players'=>[선수 ID=>['n'=>이름, 'parts'=>[...]]]]
 * part = ['team'=>팀 이름, 'tg'=>그 팀 경기 수, 's'=>원시 합계, 'pos'=>[포지션 코드=>경기 수](타자만)] — 시즌 중 이적하면 팀마다 하나씩, 뛴 순서대로.
 */
function recordRoomModernBatters(PDO $db,string $start,string $end,?array $vsPitchers=null,?array $subset=null): array {
    [$where,$range]=recordRoomRange($start,$end,$db);
    if($subset!==null){
        if(!$subset['ids'])return ['maxTg'=>0,'players'=>[]];
        $where='league_level='.recordRoomLevel().' AND '.recordRoomGameIdSql($db,$subset['ids']);$range=[];$teamGames=$subset['teamGames'];
    }else $teamGames=recordRoomTeamGames($db,$start,$end);
    $maxGames=$teamGames?max($teamGames):0;
    if($vsPitchers!==null){
        $pitcherIds=implode(',',array_map('intval',$vsPitchers));
        // 상대 투수 지정: pitcher_id에는 인덱스가 없어서, 그 투수가 등판한 경기로 좁힌 뒤 (league_level, game_id) 인덱스로 읽는다.
        $q=$db->prepare('SELECT game_id FROM kbo_season_pitch_records WHERE league_level='.recordRoomLevel().' AND player_id IN ('.$pitcherIds.') AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql());$q->execute([$start,$end]);
        $games=array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));if($subset!==null)$games=array_values(array_intersect($games,$subset['ids']));
        if(!$games)return ['maxTg'=>0,'players'=>[]];
        $where='league_level='.recordRoomLevel().' AND '.recordRoomGameIdSql($db,$games).' AND pitcher_id IN ('.$pitcherIds.')';$range=[];
        $teamGames=[];$maxGames=0;
    }
    $eventKeys=['pa','ab','h','doubles','triples','hr','bb','hbp','sf','tb','so','gdp','ibb','sh','roe','go','fo'];
    $sums=[];$missing=[];
    // 팀·타석 결과·도루·도루자 조합별로 묶어 읽는다(profileComputeModernYearLeaders와 같은 방식).
    $q=$db->prepare("SELECT player_id,team,pa_result,sb,cs,COUNT(*) n,SUM(rbi) rbi,COUNT(rbi) rbi_n,SUM(r) r,COUNT(r) r_n,SUM(run_out) ro,COUNT(run_out) ro_n,SUM(pos LIKE '%지%' OR pos LIKE '%D%') dh_n FROM kbo_season_records WHERE $where GROUP BY player_id,team,pa_result,sb,cs");$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$row['player_id'];$team=trim((string)$row['team'])?:'소속 미확인';$n=(int)$row['n'];
        $sums[$id][$team]??=array_fill_keys([...$eventKeys,'rbi','r','sb','cs','runOut','eab','eh','etb','eob','dhPa'],0);$sum=&$sums[$id][$team];
        $e=profileAdvancedBatEvent(['pa_result'=>$row['pa_result'],'sb'=>$row['sb'],'cs'=>$row['cs']]);
        foreach($eventKeys as $key)$sum[$key]+=$e[$key]*$n;
        if($e['pa'])$sum['dhPa']+=(int)$row['dh_n'];
        foreach(['sb','cs'] as $key){if($row[$key]===null)$missing[$id][$team][$key]=true;else $sum[$key]+=(int)$row[$key]*$n;}
        foreach(['rbi'=>'rbi','r'=>'r','runOut'=>'ro'] as $key=>$column){if((int)$row[$column.'_n']<$n)$missing[$id][$team][$key]=true;$sum[$key]+=(int)$row[$column];}
        // 실질 OPS 보정: 도루는 루타 추가, 도루자는 앞선 출루를 지운다(profileYearTotals와 같다).
        $sb=(int)$row['sb'];$cs=(int)$row['cs'];$a=$e['ab'];$h=$e['h'];$tb=$e['tb'];$ob=$e['bb']+$e['hbp'];$on=$h>0||$ob>0;
        if($on&&$cs>0){$h=0;$tb=0;if(!$e['h']){$a=1;$ob--;}}
        elseif($on&&$sb>0)$tb+=$sb;elseif(!$on&&$sb>0)$a=0;
        $sum['eab']+=$a*$n;$sum['eh']+=$h*$n;$sum['etb']+=$tb*$n;$sum['eob']+=$ob*$n;unset($sum);
    }
    $q=$db->prepare("SELECT player_id,team,COUNT(DISTINCT game_id) g,COUNT(DISTINCT CASE WHEN is_gs=1 THEN game_id END) gs,SUM(is_gs IS NULL) gs_unknown,MIN(game_date) first_date,MAX(game_date) last_date,MAX(player_name) name FROM kbo_season_records WHERE $where GROUP BY player_id,team");$q->execute($range);
    $appearances=[];while($row=$q->fetch(PDO::FETCH_ASSOC))$appearances[(int)$row['player_id']][]=$row;
    // 주 포지션용: 한 경기에 선 포지션마다 한 번씩 센다.
    $positions=[];$seen=[];$q=$db->prepare("SELECT DISTINCT player_id,team,game_id,pos FROM kbo_season_records WHERE $where");$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $raw=trim((string)$row['pos']);if($raw==='')continue;$id=(int)$row['player_id'];$team=trim((string)$row['team'])?:'소속 미확인';
        foreach(preg_split('//u',$raw,-1,PREG_SPLIT_NO_EMPTY) as $token){
            $code=RECORD_ROOM_POSITION_CODES[$token]??null;if($code===null||isset($seen[$id][$row['game_id'].$code]))continue;
            $seen[$id][$row['game_id'].$code]=true;$positions[$id][$team][$code]=($positions[$id][$team][$code]??0)+1;
        }
    }
    unset($seen);
    $players=[];
    foreach($appearances as $id=>$rows){
        usort($rows,static fn($a,$b)=>[$a['first_date'],$a['last_date']]<=>[$b['first_date'],$b['last_date']]);$parts=[];
        foreach($rows as $row){
            $team=trim((string)$row['team'])?:'소속 미확인';$s=$sums[$id][$team]??null;if($s===null)continue;
            if(($subset['exclude']??'')!==''&&recordRoomFranchise($team)===$subset['exclude'])continue;
            foreach($missing[$id][$team]??[] as $key=>$_)$s[$key]=null;
            if($s['sb']===null||$s['cs']===null)foreach(['eab','eh','etb','eob'] as $key)$s[$key]=null;
            $s['games']=(int)$row['g'];$s['starts']=(int)$row['gs_unknown']>0?null:(int)$row['gs'];
            $parts[]=['team'=>$team,'tg'=>$teamGames[(string)profileTeam($team)]??$maxGames,'s'=>$s,'pos'=>$positions[$id][$team]??[]];
        }
        if($parts)$players[$id]=['n'=>(string)end($rows)['name'],'parts'=>$parts];
    }
    return ['maxTg'=>$maxGames,'players'=>$players];
}

function recordRoomModernPitchers(PDO $db,int $year,string $start,string $end,array $schedule,?array $subset=null): array {
    [$where,$range]=recordRoomRange($start,$end,$db);
    if($subset!==null){
        if(!$subset['ids'])return ['maxTg'=>0,'players'=>[]];
        $where='league_level='.recordRoomLevel().' AND '.recordRoomGameIdSql($db,$subset['ids']);$range=[];$teamGames=$subset['teamGames'];
    }else $teamGames=recordRoomTeamGames($db,$start,$end);
    $maxGames=$teamGames?max($teamGames):0;
    // 구장 보정 없는 ERA+·FIP 상수는 날짜 범위 조회에서도 그 시즌 전체 리그 값을 쓴다.
    $league=profileLeaguePitchingContexts($db,$schedule,recordRoomLevel())[$year]??[];
    $q=$db->prepare("SELECT player_id,game_id,team,inning,record,pitched,`order`,er,r FROM kbo_season_pitch_records WHERE $where ORDER BY game_date,game_id");$q->execute($range);
    $byPlayer=[];$context=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $byPlayer[(int)$row['player_id']][trim((string)$row['team'])?:'소속 미확인'][]=$row;$key=$row['game_id'].'|'.profileTeam($row['team']);
        $context[$key]??=['pitchers'=>0,'last'=>0,'orderKnown'=>true];$context[$key]['pitchers']++;
        if($row['order']===null)$context[$key]['orderKnown']=false;else $context[$key]['last']=max($context[$key]['last'],(int)$row['order']);
    }
    $q=$db->prepare('SELECT game_code,away_score,home_score FROM kbo_schedule WHERE league_level='.recordRoomLevel().' AND game_date BETWEEN ? AND ?');$q->execute([$start,$end]);
    $completed=[];while($row=$q->fetch(PDO::FETCH_ASSOC))$completed[(string)$row['game_code']]=isset($row['away_score'],$row['home_score']);
    // 상대한 타석 결과(탈삼진·피안타·볼넷 등)와, 타석 기록이 있는 등판 경기
    $facedKeys=['so','h','hr','bb','hbp','pa','ab','sf','tb','go','fo'];
    $faced=[];$q=$db->prepare("SELECT pitcher_id,pa_result,COUNT(*) n FROM kbo_season_records WHERE $where AND pitcher_id IS NOT NULL GROUP BY pitcher_id,pa_result");$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$row['pitcher_id'];$e=profileAdvancedBatEvent(['pa_result'=>$row['pa_result']]);$faced[$id]??=array_fill_keys($facedKeys,0);
        foreach($facedKeys as $key)$faced[$id][$key]+=$e[$key]*(int)$row['n'];
    }
    $facedGames=[];$q=$db->prepare("SELECT DISTINCT pitcher_id,game_id FROM kbo_season_records WHERE $where AND pitcher_id IS NOT NULL");$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC))$facedGames[(int)$row['pitcher_id']][(string)$row['game_id']]=true;
    // 시즌 중 팀을 옮긴 투수만 상대 타석을 경기별로 다시 읽어 팀마다 나눈다.
    $facedByGame=[];$moved=array_keys(array_filter($byPlayer,static fn($teams)=>count($teams)>1));
    if($moved){
        $q=$db->prepare("SELECT pitcher_id,game_id,pa_result,COUNT(*) n FROM kbo_season_records WHERE $where AND pitcher_id IN (".implode(',',array_map('intval',$moved)).') GROUP BY pitcher_id,game_id,pa_result');$q->execute($range);
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            $e=profileAdvancedBatEvent(['pa_result'=>$row['pa_result']]);$slot=&$facedByGame[(int)$row['pitcher_id']][(string)$row['game_id']];$slot??=array_fill_keys($facedKeys,0);
            foreach($facedKeys as $key)$slot[$key]+=$e[$key]*(int)$row['n'];unset($slot);
        }
    }
    $labels=['wins'=>['승','W','승리'],'losses'=>['패','L','패전'],'saves'=>['세','S','세이브'],'holds'=>['홀','H','홀드']];
    $players=[];
    foreach($byPlayer as $id=>$teams){
        $parts=[];
        foreach($teams as $team=>$rows){
            if(($subset['exclude']??'')!==''&&recordRoomFranchise((string)$team)===$subset['exclude'])continue;
            $s=array_fill_keys(['games','starts','wins','losses','saves','holds','outs','r','er','startOuts','reliefOuts','finishes','qs','qsPlus','ds','complete','shutouts','pitches','reliefWins'],0);
            $unknown=[];$known=true;$events=count($teams)>1?array_fill_keys($facedKeys,0):($faced[$id]??array_fill_keys($facedKeys,0));
            foreach($rows as $row){
                $outs=profileInningOuts((string)$row['inning']);$order=$row['order']===null?null:(int)$row['order'];$start=$order===1;
                $s['games']++;$s['outs']+=$outs;
                foreach(['r','er'] as $key){if($row[$key]===null)$unknown[$key]=true;else $s[$key]+=(int)$row[$key];}
                if($row['pitched']===null)$unknown['pitches']=true;else $s['pitches']+=(int)$row['pitched'];
                foreach($labels as $key=>$names)if(in_array($row['record'],$names,true))$s[$key]++;
                if(in_array($row['record'],$labels['wins'],true)){if($order===null)$unknown['reliefWins']=true;elseif(!$start)$s['reliefWins']++;}
                // 등판했지만 타자를 상대하지 않은 경기(0이닝·0구·0실점)는 상대 타석이 없는 것이 정상이다.
                if(!isset($facedGames[$id][(string)$row['game_id']])&&($outs>0||$row['pitched']===null||(int)$row['pitched']!==0||(int)($row['r']??0)!==0))$known=false;
                if(count($teams)>1)foreach($facedByGame[$id][(string)$row['game_id']]??[] as $key=>$value)$events[$key]+=$value;
                if($order===null){foreach(['starts','startOuts','reliefOuts','finishes','qs','qsPlus','ds','complete','shutouts'] as $key)$unknown[$key]=true;continue;}
                $meta=$context[$row['game_id'].'|'.profileTeam($row['team'])];
                if(!$start){$s['reliefOuts']+=$outs;if(!$meta['orderKnown'])$unknown['finishes']=true;elseif($order===$meta['last'])$s['finishes']++;continue;}
                $s['starts']++;$s['startOuts']+=$outs;
                foreach([18=>['qs',3],21=>['qsPlus',3],24=>['ds',1]] as $threshold=>[$key,$maxEr]){
                    if($outs<$threshold)continue;
                    if($row['er']===null)$unknown[$key]=true;elseif((int)$row['er']<=$maxEr)$s[$key]++;
                }
                $code=substr((string)$row['game_id'],0,13);
                if(!array_key_exists($code,$completed))$unknown['complete']=$unknown['shutouts']=true;
                elseif($meta['pitchers']===1&&$completed[$code]){$s['complete']++;if($row['r']===null)$unknown['shutouts']=true;elseif((int)$row['r']===0)$s['shutouts']++;}
            }
            foreach($unknown as $key=>$_)$s[$key]=null;
            foreach($facedKeys as $key)$s[$key]=$known?$events[$key]:null;
            $s['eraW']=isset($league['era'])?$s['outs']*$league['era']:($s['outs']>0?null:0);
            $s['fipW']=isset($league['fipConstant'])?$s['outs']*$league['fipConstant']:($s['outs']>0?null:0);
            $parts[]=['team'=>$team,'tg'=>$teamGames[(string)profileTeam($team)]??$maxGames,'s'=>$s];
        }
        if($parts)$players[$id]=['n'=>null,'parts'=>$parts];
    }
    return ['maxTg'=>$maxGames,'players'=>$players];
}

/** 상대 타자 지정(여러 명이면 합산): 그 타자들의 타석을 투수별로 묶는다. 타석 결과로 알 수 있는 항목만 채운다. */
function recordRoomPitchersVersus(PDO $db,array $batters,string $start,string $end,?array $subset=null): array {
    if($subset!==null&&!$subset['ids'])return ['maxTg'=>0,'players'=>[]];
    $where='league_level='.recordRoomLevel().' AND player_id IN ('.implode(',',array_map('intval',$batters)).') AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().recordRoomAllStarSql($db,$start,$end).' AND pitcher_id IS NOT NULL'.($subset!==null?' AND '.recordRoomGameIdSql($db,$subset['ids']):'');$range=[$start,$end];
    $facedKeys=['so','h','hr','bb','hbp','pa','ab','sf','tb','go','fo'];$sums=[];
    $q=$db->prepare("SELECT pitcher_id,pa_result,COUNT(*) n FROM kbo_season_records WHERE $where GROUP BY pitcher_id,pa_result");$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$row['pitcher_id'];$e=profileAdvancedBatEvent(['pa_result'=>$row['pa_result']]);
        $sums[$id]??=array_fill_keys(RECORD_ROOM_PITCH_FIELDS,null);foreach($facedKeys as $key)$sums[$id][$key]=($sums[$id][$key]??0)+$e[$key]*(int)$row['n'];
    }
    if(!$sums)return ['maxTg'=>0,'players'=>[]];
    $names=[];$games=[];$q=$db->prepare("SELECT pitcher_id,game_id,MAX(pitcher_name) name FROM kbo_season_records WHERE $where GROUP BY pitcher_id,game_id");$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){$id=(int)$row['pitcher_id'];$sums[$id]['games']=($sums[$id]['games']??0)+1;$names[$id]=(string)$row['name'];$games[(string)$row['game_id']]=true;}
    // 투수의 소속팀은 그 경기들의 투수 기록에서 읽는다(마지막 경기 기준).
    $teams=[];$q=$db->query('SELECT player_id,team FROM kbo_season_pitch_records WHERE league_level='.recordRoomLevel().' AND game_id IN ('.implode(',',array_map(static fn($id)=>$db->quote($id),array_map('strval',array_keys($games)))).') ORDER BY game_date,game_id');
    while($row=$q->fetch(PDO::FETCH_ASSOC))$teams[(int)$row['player_id']]=trim((string)$row['team']);
    $players=[];
    foreach($sums as $id=>$s){if(($subset['exclude']??'')!==''&&recordRoomFranchise($teams[$id]??'')===$subset['exclude'])continue;$s['outs']=0;$players[$id]=['n'=>$names[$id]??null,'parts'=>[['team'=>($teams[$id]??'')?:'소속 미확인','tg'=>0,'s'=>$s]]];}
    return ['maxTg'=>0,'players'=>$players];
}

/** 1982~2000년: 시즌 합계 자료(정규시즌·포스트시즌만 있다. 포스트시즌은 그해 여러 시리즈를 합친다). 팀 경기 수가 없어 그 팀 타자의 최다 출장 경기를 팀 경기 수로 본다. */
function recordRoomHistorical(PDO $db,int $year,bool $pitcher): array {
    $teamGames=[];
    $season=recordRoomSeason();
    foreach(profileHistoricalRows($db,'',false,$season,$year) as $row){$team=trim((string)$row['team_name']);$teamGames[$team]=max($teamGames[$team]??0,(int)$row['games']);}
    $maxGames=$teamGames?max($teamGames):0;
    $league=$pitcher?profileHistoricalPitchingContexts($db,$season):[];
    $byPlayer=[];foreach(profileHistoricalRows($db,'',$pitcher,$season,$year) as $row)$byPlayer[(int)$row['player_id']][]=$row;
    // 경기 기록이 없는 시기의 타자 주 포지션(kbo_player_season_positions, deploy/import-season-positions.php). 표가 없으면 비워 둔다.
    $positions=[];
    if(!$pitcher)try{$q=$db->prepare('SELECT player_id,position FROM kbo_player_season_positions WHERE year=?');$q->execute([$year]);while($row=$q->fetch(PDO::FETCH_ASSOC))$positions[(int)$row['player_id']]=(string)$row['position'];}
        catch(Throwable $error){error_log('Season positions unavailable: '.$error->getMessage());}
    $players=[];
    foreach($byPlayer as $id=>$rows){
        $s=array_fill_keys($pitcher?RECORD_ROOM_PITCH_FIELDS:RECORD_ROOM_BAT_FIELDS,null);
        $map=$pitcher?['games'=>'games','wins'=>'wins','losses'=>'losses','saves'=>'saves','holds'=>'holds','outs'=>'innings_outs','r'=>'r','er'=>'er','so'=>'so','h'=>'h','hr'=>'hr','bb'=>'bb','hbp'=>'hbp','pa'=>'tbf','complete'=>'cg','shutouts'=>'sho']
            :array_combine($keys=['games','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','so','sf','sh','ibb'],$keys);
        foreach($map as $key=>$column)$s[$key]=profileSum($rows,$column);
        if($pitcher){
            $outs=(int)$s['outs'];$s['outs']=$outs;
            // 리그 평균(ERA+·FIP 상수)은 시리즈마다 따로 곱해 더한다.
            $s['eraW']=$s['fipW']=0;
            foreach($rows as $row){
                $rowOuts=(int)$row['innings_outs'];if(!$rowOuts)continue;$context=$league[$row['year'].'|'.$row['series_id']]??[];
                foreach(['eraW'=>'era','fipW'=>'fipConstant'] as $key=>$field)$s[$key]=$s[$key]===null||!isset($context[$field])?null:$s[$key]+$rowOuts*$context[$field];
            }
        }else $s['tb']=recordRoomKnown($s,'h','doubles','triples','hr')?$s['h']+$s['doubles']+2*$s['triples']+3*$s['hr']:null;
        $team=trim((string)end($rows)['team_name'])?:'소속 미확인';
        // 여러 시즌을 합산할 때 주 포지션을 고를 수 있게 그 시즌 출장 경기 수를 무게로 둔다.
        $players[$id]=['n'=>(string)end($rows)['player_name'],'parts'=>[['team'=>$team,'tg'=>$teamGames[$team]??$maxGames,'s'=>$s,'pos'=>isset($positions[$id])?[$positions[$id]=>max(1,(int)($s['games']??1))]:[]]]];
    }
    return ['maxTg'=>$maxGames,'players'=>$players];
}

/** 수비: 공식 시즌 수비 기록(2001년~). part는 팀·포지션마다 하나이고 'fpos'에 포지션 이름을 둔다. */
function recordRoomFielding(PDO $db,int $year,string $start,string $end): array {
    $teamGames=recordRoomTeamGames($db,$start,$end);$maxGames=$teamGames?max($teamGames):0;
    $q=$db->prepare('SELECT player_id,team,position,games,starts,innings_outs,'.implode(',',PROFILE_FIELDING_COUNTS).' FROM kbo_fielding_records WHERE year=? ORDER BY player_id,innings_outs DESC,games DESC,id');$q->execute([$year]);
    $players=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $position=trim((string)$row['position']);if($position==='')continue;$team=trim((string)$row['team'])?:'소속 미확인';
        $s=['games'=>(int)$row['games'],'starts'=>(int)$row['starts'],'outs'=>(int)$row['innings_outs']];
        foreach(PROFILE_FIELDING_COUNTS as $key=>$column)$s[$key]=(int)$row[$column];
        $id=(int)$row['player_id'];$players[$id]??=['n'=>null,'parts'=>[]];
        $players[$id]['parts'][]=['team'=>$team,'tg'=>$teamGames[profileFieldingTeamIdentity($team)]??$maxGames,'s'=>$s,'fpos'=>$position];
    }
    return ['maxTg'=>$maxGames,'players'=>$players];
}

/** 한 구간 [year,start,end,full]의 선수별 원시 합계. 시즌 전체 구간만 파일에 보관한다. */
function recordRoomSegmentData(PDO $db,array $segment,string $type,array $schedule,?array $versus=null,string $stadium='',string $opponent=''): array {
    [$year,$start,$end,$full]=$segment;$pitcher=$type==='pitcher';
    // 상대 선수·구장·상대팀 조건은 경기 기록(2001년~)에서 그때그때 읽는다.
    $season=recordRoomSeason();$special=$versus!==null||$stadium!==''||$opponent!=='';
    if($type!=='fielding'&&($special||($season==='postseason'&&$year>2000))){
        if($year<=2000)return ['maxTg'=>0,'players'=>[]];
        // 포스트시즌 경기는 game_id가 날짜로 시작하지 않아서(3333…·7777… 등) 일정표에서 고른 경기 묶음으로 읽는다.
        $compute=static function()use($db,$year,$start,$end,$schedule,$versus,$stadium,$opponent,$pitcher,$season){
            $subset=$stadium!==''||$opponent!==''||$season==='postseason'?recordRoomGameSubset($db,$start,$end,$stadium,$opponent):null;
            if(!$pitcher)return recordRoomModernBatters($db,$start,$end,$versus,$subset);
            return $versus!==null?recordRoomPitchersVersus($db,$versus,$start,$end,$subset):recordRoomModernPitchers($db,$year,$start,$end,$schedule,$subset);
        };
        return $full&&!$special?recordRoomCached('y-'.$year.'-'.$type[0].'-'.substr(hash('sha256',$season.'|'.$start.'|'.$end),0,12),recordRoomStamp($year>=recordRoomCurrentYear()),$compute):$compute();
    }
    $compute=static fn()=>$type==='fielding'?recordRoomFielding($db,$year,$start,$end)
        :($year<=2000?recordRoomHistorical($db,$year,$pitcher):($pitcher?recordRoomModernPitchers($db,$year,$start,$end,$schedule):recordRoomModernBatters($db,$start,$end)));
    if(!$full)return $compute();
    return recordRoomCached('y-'.$year.'-'.$type[0].'-'.substr(hash('sha256',($season==='regular'?'':$season.'|').$start.'|'.$end),0,12),recordRoomStamp($year>=recordRoomCurrentYear()),$compute);
}

/** 타자: 합계 → 표시 기록. 1982~2000년 자료가 섞이면 시즌 합계용 계산(없는 항목은 빈칸)을 쓴다. */
function recordRoomBatterStats(array $s,array $leagueYears,bool $historical,bool $earlyObp): array {
    $s['opsLeagueYears']=$leagueYears;
    if($historical){
        // 1985년까지의 공식 출루율은 희생플라이를 분모에 넣지 않았다(단일 시즌 조회에만 적용).
        if($earlyObp)$s['obpSf']=0;
        $out=profileSeasonRates($s,false);
    }else{
        $den=$s['ab']+$s['bb']+$s['hbp']+$s['sf'];
        $obp=$den?($s['h']+$s['bb']+$s['hbp'])/$den:null;$slg=$s['ab']?$s['tb']/$s['ab']:null;
        $s['avg']=$s['ab']?number_format($s['h']/$s['ab'],3,'.',''):null;
        $s['obp']=$obp===null?null:number_format($obp,3,'.','');$s['slg']=$slg===null?null:number_format($slg,3,'.','');
        $s['ops']=$obp===null||$slg===null?null:number_format($obp+$slg,3,'.','');
        $s['opsPlus']=profileSeasonOpsPlus($s);
        $ed=recordRoomKnown($s,'eab','eob')?$s['eab']+$s['eob']+$s['sf']:0;
        $s['effectiveOps']=$ed&&$s['eab']?number_format(($s['eh']+$s['eob'])/$ed+$s['etb']/$s['eab'],3,'.',''):null;
        $out=profileBatterAdvancedStats([$s],$s);
    }
    if(isset($out['opsPlus']))$out['opsPlus']=(int)$out['opsPlus'];
    return array_intersect_key($out,array_flip(RECORD_ROOM_BAT_KEYS));
}

/** 투수: 합계 → 표시 기록. 모르는 값(null)이 낀 항목은 빈칸으로 둔다. */
function recordRoomPitcherStats(array $s): array {
    $outs=(int)$s['outs'];$has=static fn(string ...$keys)=>recordRoomKnown($s,...$keys);
    $ratio=static fn($n,$d,int $p=2)=>profileMetricRatio($n===null?null:(float)$n,$d===null?null:(float)$d,$p);
    $o=[];foreach(['games','starts','wins','losses','saves','holds','r','er','so','h','hr','bb','hbp','finishes','pitches','qs','qsPlus','ds','complete','shutouts'] as $key)$o[$key]=$s[$key]??null;
    $o['innings']=profileInningText($outs);
    $o['winPct']=$has('wins','losses')&&$s['wins']+$s['losses']>0?number_format($s['wins']/($s['wins']+$s['losses']),3,'.',''):null;
    $o['era']=$ratio($has('er')?$s['er']*27:null,$outs);
    $o['whip']=$ratio($has('h','bb')?($s['h']+$s['bb'])*3:null,$outs);
    $o['reliefs']=$has('starts')?$s['games']-$s['starts']:null;
    $o['starterInnings']=$has('startOuts')?profileInningText((int)$s['startOuts']):null;$o['reliefInnings']=$has('reliefOuts')?profileInningText((int)$s['reliefOuts']):null;
    $o['pitchesPerInning']=$ratio($has('pitches')?$s['pitches']*3:null,$outs);$o['pitchesPerGame']=$ratio($s['pitches']??null,$s['games']);
    foreach(['k9'=>'so','bb9'=>'bb','h9'=>'h','hr9'=>'hr'] as $key=>$field)$o[$key]=$ratio($has($field)?$s[$field]*27:null,$outs);
    $o['kPct']=$ratio($has('so')?$s['so']*100:null,$s['pa']??null,1);$o['bbPct']=$ratio($has('bb')?$s['bb']*100:null,$s['pa']??null,1);
    $o['kBb']=$ratio($s['so']??null,$s['bb']??null);
    $o['opponentAvg']=$ratio($s['h']??null,$s['ab']??null,3);$o['opponentSlg']=$ratio($s['tb']??null,$s['ab']??null,3);
    $obpKnown=$has('h','bb','hbp','ab','sf');$obpDen=$obpKnown?$s['ab']+$s['bb']+$s['hbp']+$s['sf']:0;
    $o['opponentObp']=$obpKnown?$ratio($s['h']+$s['bb']+$s['hbp'],$obpDen,3):null;
    $o['opponentOps']=$obpKnown&&$has('tb')&&$obpDen>0&&$s['ab']>0?number_format(($s['h']+$s['bb']+$s['hbp'])/$obpDen+$s['tb']/$s['ab'],3,'.',''):null;
    $o['groundFly']=$ratio($s['go']??null,$s['fo']??null);
    $o['babip']=$has('h','hr','ab','so','sf')?$ratio($s['h']-$s['hr'],$s['ab']-$s['so']-$s['hr']+$s['sf'],3):null;
    $o['fip']=$outs>0&&$has('hr','bb','hbp','so','fipW')?number_format((13*$s['hr']+3*($s['bb']+$s['hbp'])-2*$s['so'])*3/$outs+$s['fipW']/$outs,2,'.',''):null;
    $o['eraPlus']=$outs>0&&$has('er','eraW')&&$s['er']>0?(int)round(100*$s['eraW']/($s['er']*27)):null;
    return array_intersect_key($o,array_flip(RECORD_ROOM_PITCH_KEYS));
}

/** 수비: 합계 → 표시 기록. 포지션에 해당 없는 항목은 빈칸이다(프로필의 수비 기록과 같다). */
function recordRoomFieldingStats(array $s,string $position): array {
    $o=profileFieldingEmptyStats($position);$o['games']=$s['games'];$o['starts']=$s['starts'];$o['innings']=profileInningText((int)$s['outs']);
    foreach(array_keys(PROFILE_FIELDING_COUNTS) as $key)if(profileFieldingApplies($key,$position))$o[$key]=$s[$key];
    return array_intersect_key(profileFieldingRates($o),array_flip(RECORD_ROOM_FIELD_KEYS))+['position'=>$position];
}

/**
 * 수비: 여러 포지션을 한 줄로 합친 기록. $parts = [[포지션 이름, 합계], ...]
 * 포수만(포일·도루 허용/저지)·투수와 포수만(견제사) 기록되는 항목은 그 포지션 기록이 있을 때만 채운다. 포지션 칸에는 포지션 수를 적는다.
 */
function recordRoomFieldingMergedStats(array $parts): array {
    $o=profileFieldingEmptyStats(null);$outs=0;$positions=[];
    foreach(['games','starts','errors','putouts','assists','doublePlays'] as $key)$o[$key]=0;
    foreach($parts as [$position,$s]){
        $positions[$position]=true;$outs+=(int)$s['outs'];
        foreach(['games','starts','errors','putouts','assists','doublePlays'] as $key)$o[$key]+=(int)$s[$key];
        foreach(['pickoffs','passedBalls','stolenBases','caughtStealing'] as $key)if(profileFieldingApplies($key,$position))$o[$key]=($o[$key]??0)+(int)$s[$key];
    }
    $o['innings']=profileInningText($outs);
    // 도루저지율은 포수 기록이 있을 때만 계산한다.
    $o['position']=isset($positions['포수'])?'포수':null;$o=profileFieldingRates($o);
    return array_intersect_key($o,array_flip(RECORD_ROOM_FIELD_KEYS))+['position'=>count($positions).'개'];
}

/**
 * 조건에 맞는 전체 선수 목록(정렬 전).
 * $filter: segments=[[year,start,end,full],...], career, team(구단 또는 ''), splitSeasons, splitTeams, ageMin, ageMax(시즌 나이, 없으면 null),
 *          stadium(구장), opponent(상대팀 구단) — 둘 다 2001년 이후 경기 기록만, 규정 기준은 그 경기들의 팀 경기 수로 센다.
 *          mergePositions(수비 기록 전용: 포지션별로 나누지 않고 선수의 모든 포지션을 한 줄로 합친다),
 *          goldenGlove(골든글러브 후보 시즌만), award(수비상 후보만, 수비 기록 전용) — 후보가 아닌 선수-시즌은 합산에서 뺀다.
 *          versus(상대 선수 ID 목록(여러 명이면 합산): 타자 기록이면 상대 투수, 투수 기록이면 상대 타자. 없으면 null. 규정 기준은 두지 않는다)
 * 한 줄 = 선수(+시즌별 보기면 시즌, +팀별 보기면 구단, 수비는 항상 포지션별).
 * 행: id,name,team(마지막 팀),teams(거친 팀 수),year(마지막 시즌),season(시즌별 보기일 때),active,pos(주 포지션),hand(타자는 타석 R·L·S, 투수는 투구 R·L 또는 언더 U·사이드 D),birth(출생 연도),v(타석·아웃·경기 수),need(규정 기준, 실수),stats
 */
function recordRoomDataset(PDO $db,array $filter,string $type,array $schedule): array {
    $segments=$filter['segments'];
    $current=recordRoomCurrentYear();$touchesCurrent=(bool)array_filter($segments,static fn($segment)=>$segment[0]>=$current);
    $name='q8-'.$type[0].'-'.substr(hash('sha256',json_encode($filter)),0,32);
    return recordRoomCached($name,recordRoomStamp($touchesCurrent),static function()use($db,$filter,$segments,$type,$schedule){
        @set_time_limit(300);
        $pitcher=$type==='pitcher';$fielding=$type==='fielding';
        $fields=$fielding?['games','starts','outs',...array_keys(PROFILE_FIELDING_COUNTS)]:($pitcher?RECORD_ROOM_PITCH_FIELDS:RECORD_ROOM_BAT_FIELDS);
        // 규정: 타석은 팀 경기×3.1, 이닝은 팀 경기×1. 수비는 아래에서 수비상 기준으로 따로 센다.
        // 퓨처스리그의 규정은 팀 경기×2.7타석, ×0.8이닝이다.
        $futures=recordRoomLevel()===2;
        $factor=$fielding?2/3:($pitcher?($futures?2.4:3):($futures?2.7:3.1));
        $splitSeasons=(bool)$filter['splitSeasons'];$splitTeams=(bool)$filter['splitTeams'];$team=(string)$filter['team'];
        $ageMin=$filter['ageMin'];$ageMax=$filter['ageMax'];$versus=$filter['versus']??null;$stadium=(string)($filter['stadium']??'');$opponent=(string)($filter['opponent']??'');
        $info=[];foreach($db->query('SELECT player_id,name,birth,is_kbodle,is_foreign,bat,`throw` FROM kbo_player_data') as $row)$info[(int)$row['player_id']]=$row;
        $prospects=array_flip(recordRoomProspects()['ids']);
        $goldenGlove=!empty($filter['goldenGlove']);$award=$fielding&&!empty($filter['award']);$mergePositions=$fielding&&!empty($filter['mergePositions']);
        $groups=[];$baseNeed=0.0;
        foreach($segments as $segment){
            $data=recordRoomSegmentData($db,$segment,$type,$schedule,$versus,$stadium,$opponent);$year=$segment[0];
            $baseNeed+=$data['maxTg']*$factor;
            $candidates=$goldenGlove||$award?recordRoomSeasonCandidates($db,$year,$schedule):null;
            // 수비의 규정 = 수비상 조건(팀 경기 수에 맞춰 144경기 기준을 비례): 투수 48이닝, 포수 72경기, 내·외야수는 내·외야 수비 합계 720이닝.
            // 유틸리티 조건(합계 540이닝 + 50이닝 이상 본 포지션 셋)을 채운 선수도 포함하고, 그 선수들만 따로 고를 수도 있다.
            $fieldTotals=[];$fieldUtility=[];
            if($fielding)foreach($data['players'] as $id=>$player){
                $outs=[];$games=[];
                foreach($player['parts'] as $part){$code=RECORD_ROOM_FIELDING_CODES[$part['fpos']]??$part['fpos'];$outs[$code]=($outs[$code]??0)+(int)$part['s']['outs'];$games[$code]=($games[$code]??0)+(int)$part['s']['games'];}
                $fieldTotals[$id]=array_sum(array_intersect_key($outs,array_flip([...RECORD_ROOM_INFIELD,...RECORD_ROOM_OUTFIELD])));
                $fieldUtility[$id]=recordRoomFieldingUtility($outs);
            }
            foreach($data['players'] as $id=>$player){
                if($ageMin!==null||$ageMax!==null){
                    $age=profileAgeOnJulyFirst($info[$id]['birth']??null,$year);
                    if($age===null||($ageMin!==null&&$age<$ageMin)||($ageMax!==null&&$age>$ageMax))continue;
                }
                foreach($player['parts'] as $part){
                    if($candidates!==null&&!recordRoomCandidatePart($candidates,$type,(int)$id,$part,$goldenGlove,$award))continue;
                    $franchise=recordRoomFranchise($part['team'],$year);
                    if($team!==''&&$franchise!==$team)continue;
                    $key=$id.($splitSeasons?'|'.$year:'').($splitTeams?'|'.$franchise:'').($fielding&&!$mergePositions?'|'.$part['fpos']:'');
                    $groups[$key]??=['id'=>$id,'name'=>$player['n'],'parts'=>[],'teams'=>[],'years'=>[],'pos'=>[],'fpos'=>$part['fpos']??null];
                    $group=&$groups[$key];
                    $group['parts'][]=$part['s'];$group['teams'][]=$part['team'];
                    // 그 시즌의 규정 기준은 (이 줄에 들어온) 마지막 소속팀 경기 수로 본다.
                    $group['years'][$year]=['tg'=>$part['tg'],'max'=>$data['maxTg']];
                    if($fielding){
                        $code=RECORD_ROOM_FIELDING_CODES[$part['fpos']]??$part['fpos'];$slot=$group['fielding'][$year]??['v'=>0,'need'=>0.0];
                        [$value,$need]=match(true){
                            $code==='P'=>[$slot['v']+(int)$part['s']['outs'],$part['tg']*1.0],
                            $code==='C'=>[$slot['v']+(int)$part['s']['games'],$part['tg']/2],
                            default=>[$fieldTotals[$id]??0,$part['tg']*15.0],
                        };
                        // 유틸리티 조건을 채운 선수의 내·외야 줄은 수비상 조건도 채운 것으로 본다.
                        if(($fieldUtility[$id]??false)&&$code!=='P'&&$code!=='C'){$group['utility']=true;$value=max($value,(int)ceil($need));}
                        $group['fielding'][$year]=['v'=>$value,'need'=>$need];
                        if($mergePositions){
                            $group['fparts'][]=[$part['fpos'],$part['s']];$merged=&$group['fyears'][$year];
                            $merged['tg']=$part['tg'];$merged['outs'][$code]=($merged['outs'][$code]??0)+(int)$part['s']['outs'];$merged['games'][$code]=($merged['games'][$code]??0)+(int)$part['s']['games'];
                            if($fieldUtility[$id]??false)$merged['utility']=true;
                            unset($merged);
                        }
                    }
                    foreach($part['pos']??[] as $code=>$games)$group['pos'][$code]=($group['pos'][$code]??0)+$games;
                    if($player['n'])$group['name']=$player['n'];
                    unset($group);
                }
            }
        }
        $leagueYears=$pitcher||$fielding?[]:profileBattingLeagueContexts($db,$schedule,recordRoomSeason(),recordRoomLevel());
        // 통산 기준(3,000타석·1,000이닝)은 선수 한 줄로 합산할 때만 쓴다.
        $careerNeed=!empty($filter['career'])&&$versus===null&&$stadium===''&&$opponent===''&&!$splitSeasons&&!$splitTeams&&$team===''&&!$fielding?($pitcher?RECORD_ROOM_CAREER_OUTS:RECORD_ROOM_CAREER_PA):null;
        $rows=[];
        foreach($groups as $group){
            $id=$group['id'];$s=recordRoomSum($group['parts'],$fields);$years=array_keys($group['years']);$position=null;
            $mergedNeed=null;
            if($mergePositions){
                // 합친 줄의 수비상 조건: 시즌마다 투수 이닝·포수 경기·내외야 합계 이닝 중 가장 많이 채운 비율(유틸리티 조건을 채웠으면 100%)로 본다.
                $stats=recordRoomFieldingMergedStats($group['fparts']);$value=0;$mergedNeed=0.0;$byCode=[];
                foreach($group['fyears'] as $merged){
                    $games=max(1,(int)$merged['tg']);$field=array_sum(array_intersect_key($merged['outs'],array_flip([...RECORD_ROOM_INFIELD,...RECORD_ROOM_OUTFIELD])));
                    $ratio=max(($merged['outs']['P']??0)/$games,($merged['games']['C']??0)/($games/2),$field/($games*15));
                    if(!empty($merged['utility']))$ratio=max($ratio,1.0);
                    $value+=(int)floor($ratio*1000+1e-6);$mergedNeed+=1000;
                    foreach($merged['outs'] as $code=>$outs)$byCode[$code]=($byCode[$code]??0)+$outs;
                }
                arsort($byCode,SORT_NUMERIC);$position=$byCode?(string)array_key_first($byCode):null;
            }elseif($fielding){$stats=recordRoomFieldingStats($s,$group['fpos']);$position=RECORD_ROOM_FIELDING_CODES[$group['fpos']]??$group['fpos'];$value=(int)array_sum(array_column($group['fielding']??[],'v'));}
            elseif($pitcher){
                if($versus!==null){$stats=array_intersect_key(recordRoomPitcherStats($s)+['pa'=>$s['pa'],'ab'=>$s['ab']],array_flip(RECORD_ROOM_VERSUS_KEYS));$value=(int)$s['pa'];}
                else{$stats=recordRoomPitcherStats($s);$value=(int)$s['outs'];}
                // 주 포지션: 선발 등판이 절반을 넘으면 선발, 아니면 구원
                if(isset($s['starts'],$s['games']))$position=$s['starts']*2>$s['games']?'SP':'RP';
            }else{
                $contexts=[];foreach($years as $year)$contexts[$year]=$leagueYears[$year]??null;
                $stats=recordRoomBatterStats($s,$contexts,min($years)<=2000,count($segments)===1&&$segments[0][0]<=1985);$value=(int)($s['pa']??0);
                // 주 포지션: 가장 많은 경기에 선 포지션(경기 기록이 있는 2001년 이후)
                if($group['pos']){arsort($group['pos'],SORT_NUMERIC);$position=(string)array_key_first($group['pos']);}
            }
            if($value<=0&&(int)($s['games']??0)<=0)continue;
            if($mergedNeed!==null)$need=$mergedNeed;
            elseif($fielding)$need=(float)array_sum(array_column($group['fielding']??[],'need'));
            elseif($versus!==null)$need=0.0;
            elseif($careerNeed!==null)$need=$careerNeed;
            else{
                // 한 줄로 합산하면 뛰지 않은 시즌도 그해 최다 팀 경기 수만큼 기준에 넣고, 나눠 보면 뛴 시즌만 센다.
                $need=$splitSeasons||$splitTeams?0.0:$baseNeed;
                foreach($group['years'] as $games)$need+=($splitSeasons||$splitTeams?$games['tg']:$games['tg']-$games['max'])*$factor;
                // 여러 시즌을 합산할 때는 통산 기준(3,000타석·1,000이닝)을 넘지 않게 한다.
                if(!$fielding&&count($segments)>1&&!$splitSeasons)$need=min($need,$pitcher?RECORD_ROOM_CAREER_OUTS:RECORD_ROOM_CAREER_PA);
            }
            $teams=array_values(array_unique($group['teams']));
            $rows[]=['id'=>(int)$id,'name'=>(string)($info[$id]['name']??$group['name']??''),'team'=>end($teams)?:null,'teams'=>count(array_unique(array_map('recordRoomFranchise',$teams))),'year'=>max($years),
                'season'=>$splitSeasons?$years[0]:null,'utility'=>!empty($group['utility']),'active'=>isset($info[$id])&&(string)$info[$id]['is_kbodle']!=='0','foreign'=>(int)($info[$id]['is_foreign']??0)===1,'prospect'=>isset($prospects[(int)$id]),'pos'=>$position,
                'hand'=>recordRoomHand((string)($info[$id][$pitcher?'throw':'bat']??''),$pitcher),'birth'=>(int)substr((string)($info[$id]['birth']??''),0,4)?:null,'v'=>$value,'need'=>$need,'stats'=>$stats];
        }
        return ['rows'=>$rows];
    },true)['rows'];
}

/** 타석(우타·좌타·양타) 또는 투구(우투·좌투, '언'으로 끝나면 언더, '사'로 끝나면 사이드) 표기 → 필터 코드 */
function recordRoomHand(string $value,bool $pitcher): ?string {
    $value=trim($value);if($value==='')return null;
    if($pitcher){$last=mb_substr($value,-1);if($last==='언')return 'U';if($last==='사')return 'D';}
    return ['우'=>'R','좌'=>'L','양'=>'S'][mb_substr($value,0,1)]??null;
}

function recordRoomSortValue(array $row,string $key): ?float {
    $value=$row['stats'][$key]??null;
    if($value===null||$value==='')return null;
    return in_array($key,RECORD_ROOM_INNING_KEYS,true)?(float)profileInningOuts((string)$value):(float)$value;
}

/**
 * 정렬·조건·페이지 나누기.
 * 최소 조건은 비율 기록으로 정렬할 때만 적용한다($only의 minAll을 켜면 모든 기록 정렬에 적용): $min은 규정 대비 비율(0이면 제한 없음), $minValue는 직접 입력한 값(타석·경기 수, 이닝은 아웃 수로 바꾼 값).
 * 수비 기록은 $only의 minUtility(유틸리티 수비상 조건을 채운 선수만), minUnit(직접 입력 단위 innings·games, 그 줄의 이닝·경기 수와 비교)을 쓴다.
 * $only(정렬과 상관없이 적용): players(이 선수 ID들만), active(현역만), foreign(외국인 선수만), prospect(유망주 명단에 있는 선수만), pos(주 포지션 코드 또는 OF·IF·SP·RP), hand(R·L·S), birthMin·birthMax(출생 연도)
 * 값이 없는 선수는 제외하고, 같은 값은 같은 순위로 매긴다.
 */
function recordRoomQuery(array $rows,string $type,string $sort,bool $descending,float $min,int $offset,int $limit,?float $minValue=null,array $only=[]): array {
    $positions=empty($only['pos'])?null:(RECORD_ROOM_POSITION_GROUPS[$only['pos']]??[$only['pos']]);
    $rate=in_array($sort,RECORD_ROOM_RATES[$type],true);$applyMin=$rate||!empty($only['minAll']);
    $list=[];
    foreach($rows as $row){
        if(!empty($only['players'])&&!in_array($row['id'],$only['players'],true))continue;
        foreach(['active','foreign','prospect'] as $flag)if(!empty($only[$flag])&&!$row[$flag])continue 2;
        if($positions!==null&&!in_array($row['pos'],$positions,true))continue;
        if(!empty($only['hand'])&&$row['hand']!==$only['hand'])continue;
        if((isset($only['birthMin'])||isset($only['birthMax']))&&($row['birth']===null||$row['birth']<($only['birthMin']??0)||$row['birth']>($only['birthMax']??9999)))continue;
        $value=recordRoomSortValue($row,$sort);if($value===null)continue;
        if($applyMin&&!empty($only['minUtility'])&&empty($row['utility']))continue;
        if($applyMin&&$type==='fielding'&&$minValue!==null){
            $games=($only['minUnit']??'innings')==='games';
            if(($games?(int)($row['stats']['games']??0):profileInningOuts((string)($row['stats']['innings']??'0')))<$minValue)continue;
        }elseif($applyMin&&($minValue!==null?$row['v']<$minValue:($min>0&&($row['need']<=0||$row['v']<floor($row['need']*$min)))))continue;
        $row['sortValue']=$value;$list[]=$row;
    }
    usort($list,static fn($a,$b)=>($descending?$b['sortValue']<=>$a['sortValue']:$a['sortValue']<=>$b['sortValue'])?:($b['v']<=>$a['v'])?:strcmp($a['name'],$b['name'])?:($a['id']<=>$b['id'])?:($b['year']<=>$a['year']));
    $rank=0;$last=null;
    foreach($list as $index=>&$row){if($last===null||abs($row['sortValue']-$last)>1e-9)$rank=$index+1;$last=$row['sortValue'];$row['rank']=$rank;$row['qualified']=$row['need']>0&&$row['v']>=floor($row['need']);unset($row['sortValue'],$row['v'],$row['need'],$row['active'],$row['foreign'],$row['prospect'],$row['utility'],$row['hand'],$row['birth']);}
    unset($row);
    return ['total'=>count($list),'rate'=>$rate,'rows'=>array_slice($list,$offset,$limit)];
}

/** 연도 목록 → 시즌 전체 구간. */
function recordRoomYearSegments(array $years,array $schedule): array {
    $segments=[];
    foreach($years as $year){
        if($year<=2000){$segments[]=[$year,'','',true];continue;}
        [$start,$end]=$schedule[$year]['regular']??['',''];
        if($start&&$end)$segments[]=[$year,$start,$end,true];
    }
    return $segments;
}

/** 날짜 범위 → 시즌별 구간(정규시즌 기간과 겹치는 부분만). 시즌 전체를 덮으면 보관된 시즌 자료를 쓴다. */
function recordRoomPeriodSegments(string $start,string $end,array $schedule): array {
    $segments=[];$current=recordRoomCurrentYear();
    foreach($schedule as $year=>$season){
        $year=(int)$year;[$a,$b]=$season['regular']??['',''];
        if(!$a||!$b||$year>$current||$end<$a||$start>$b)continue;
        $from=max($start,$a);$to=min($end,$b);
        $segments[]=[$year,$from,$to,$from===$a&&$to===$b];
    }
    usort($segments,static fn($x,$y)=>$x[0]<=>$y[0]);
    return $segments;
}

/** 시즌 전체 구간 → 그 시즌의 전반기(firstHalf)·후반기(secondHalf)·특정 월(month). 경기 기록이 있는 2001년 이후만 남긴다. */
function recordRoomPartSegments(array $segments,string $part,int $month,array $seasons): array {
    $out=[];
    foreach($segments as [$year,$start,$end]){
        if($year<=2000)continue;$season=$seasons[$year]??null;
        if($part==='firstHalf')[$from,$to]=[$start,$season['halfEnd']??null];
        elseif($part==='secondHalf')[$from,$to]=[$season['halfStart']??null,$end];
        else{$first=sprintf('%04d-%02d-01',$year,$month);[$from,$to]=[max($start,$first),min($end,date('Y-m-t',strtotime($first)))];}
        if($from&&$to&&$from<=$to)$out[]=[$year,$from,$to,false];
    }
    return $out;
}

/** 유망주 명단(선수 ID와 출처). deploy/build-record-room-prospects.php가 만든 파일을 읽고, 없으면 빈 명단이다. */
function recordRoomProspects(): array {
    static $list=null;
    if($list===null){$path=__DIR__.'/record-room-prospects.php';$list=is_file($path)?require $path:[];$list+=['season'=>null,'source'=>null,'title'=>null,'revised'=>null,'ids'=>[]];}
    return $list;
}

/** 구장 필터에 쓸 구장 이름(경기가 많은 순). */
function recordRoomStadiums(PDO $db): array {
    return recordRoomCached('stadiums',recordRoomStamp(true),static fn()=>array_values(array_filter(array_map('trim',$db->query("SELECT stadium FROM kbo_schedule WHERE league_level=1 AND stadium IS NOT NULL GROUP BY stadium ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_COLUMN)),static fn($name)=>$name!=='')));
}

/**
 * 기간 선택용 시즌 정보: 정규시즌 시작·끝과 전반기 끝·후반기 시작.
 * 일정표에 올스타전이 없어서, 7월~8월 초에 경기가 4일 이상 비는 가장 긴 구간을 올스타 휴식기로 본다(없으면 null).
 */
function recordRoomSeasons(PDO $db,array $schedule): array {
    $current=recordRoomCurrentYear();
    return recordRoomCached('seasons',recordRoomStamp(true),static function()use($db,$schedule,$current){
        $last=[];$dates=[];
        foreach($db->query('SELECT YEAR(game_date) y,MAX(game_date) d FROM kbo_schedule WHERE league_level=1 AND away_score IS NOT NULL AND home_score IS NOT NULL GROUP BY YEAR(game_date)') as $row)$last[(int)$row['y']]=(string)$row['d'];
        foreach($db->query('SELECT DISTINCT game_date d FROM kbo_schedule WHERE league_level=1 AND MONTH(game_date) IN (7,8) ORDER BY game_date') as $row)$dates[(int)substr((string)$row['d'],0,4)][]=(string)$row['d'];
        $seasons=[];
        foreach($schedule as $year=>$season){
            $year=(int)$year;[$start,$end]=$season['regular']??['',''];if(!$start||!$end||$year>$current)continue;
            $halfEnd=$halfStart=null;$gap=3;$previous=null;
            foreach($dates[$year]??[] as $date){
                if($date<$start||$date>$end||$date>"$year-08-15")continue;
                if($previous!==null){$days=(int)(new DateTimeImmutable($previous))->diff(new DateTimeImmutable($date))->days;if($days>$gap){$gap=$days;$halfEnd=$previous;$halfStart=$date;}}
                $previous=$date;
            }
            $seasons[$year]=['start'=>$start,'end'=>isset($last[$year])?min($end,$last[$year]):$end,'halfEnd'=>$halfEnd,'halfStart'=>$halfStart];
        }
        return $seasons;
    });
}
