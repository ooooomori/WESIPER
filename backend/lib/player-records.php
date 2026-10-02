<?php
declare(strict_types=1);
require_once __DIR__.'/player-season-totals.php';

function profileBatEvent(array $row): array {
    $text = trim((string)($row['pa_result'] ?? ''));
    $walk = in_array($text, ['4구','고4','볼넷'], true) || str_contains($text, '볼넷');
    $hbp = str_contains($text, '사구');
    $sf = str_contains($text, '희비') || str_contains($text, '희플');
    $sh = !$sf && (str_contains($text,'희번') || str_contains($text,'희타') || str_contains($text,'희실'));
    $sac = $sf || $sh;
    $interference = str_contains($text,'타방');
    $last = mb_substr($text, -1, 1, 'UTF-8');
    $bases = match ($last) { '안'=>1, '2'=>2, '3'=>3, '홈'=>4, default=>0 };
    $ab = (int)($text !== '' && !$walk && !$hbp && !$sac && !$interference);
    return ['pa'=>(int)($text!==''),'ab'=>$ab,'h'=>(int)($bases>0),'doubles'=>(int)($bases===2),'triples'=>(int)($bases===3),'tb'=>$bases,'bb'=>(int)$walk,'hbp'=>(int)$hbp,'sf'=>(int)$sf,'sh'=>(int)$sh,'hr'=>(int)($bases===4),'so'=>(int)str_contains($text,'삼진'),'gdp'=>(int)str_contains($text,'병'),'sb'=>(int)($row['sb']??0),'cs'=>(int)($row['cs']??0),'rbi'=>isset($row['rbi'])?(int)$row['rbi']:null,'r'=>isset($row['r'])?(int)$row['r']:null];
}
function profileSum(array $rows, string $key): ?int {
    if (!$rows || array_filter($rows, static fn($row)=> !isset($row[$key]))) return null;
    return array_sum(array_column($rows,$key));
}
function profileBatStats(array $events, ?array $league = null): array {
    $rows = array_map('profileBatEvent',$events);
    $s=[]; foreach (['ab','h','tb','bb','hbp','sf','hr','so','sb','rbi','r'] as $key) $s[$key]=profileSum($rows,$key);
    $avg=$s['ab'] ? $s['h']/$s['ab'] : null;
    $den=$s['ab']+$s['bb']+$s['hbp']+$s['sf'];
    $obp=$den ? ($s['h']+$s['bb']+$s['hbp'])/$den : null;
    $slg=$s['ab'] ? $s['tb']/$s['ab'] : null;
    $opsPlus=$obp!==null && $slg!==null && ($league['obp']??0)>0 && ($league['slg']??0)>0 ? round(100*($obp/$league['obp']+$slg/$league['slg']-1)) : null;
    return [['타율',$avg===null?null:number_format($avg,3)],['안타',$s['h']],['홈런',$s['hr']],['타점',$s['rbi']],['OPS',$obp===null||$slg===null?null:number_format($obp+$slg,3)],['도루',$s['sb']],['득점',$s['r']],['OPS+',$opsPlus]];
}
function profileInningOuts(string $inning): int {
    $inning=str_replace(['⅓','⅔'],['1/3','2/3'],$inning);
    // Naver innings use mixed fractions, e.g. "5 2/3", not decimal innings.
    if (preg_match('/^(?:(\d+)\s+)?([12])\/3$/',trim($inning),$m)) return (int)($m[1]??0)*3+(int)$m[2];
    if (preg_match('/^(\d+)(?:\.([012]))?$/',trim($inning),$m)) return (int)$m[1]*3+(int)($m[2]??0);
    throw new RuntimeException('Unknown innings format');
}
function profileInningText(int $outs): string { return (string)intdiv($outs,3).($outs%3 ? '.'.($outs%3) : ''); }
function profileOpponent(string $game, ?string $team): ?string {
    $names=['HT'=>'KIA','LG'=>'LG','SK'=>'SSG','OB'=>'두산','SS'=>'삼성','LT'=>'롯데','KT'=>'KT','HH'=>'한화','NC'=>'NC','WO'=>'키움'];
    $away=$names[substr($game,8,2)]??null; $home=$names[substr($game,10,2)]??null;
    $team=profileTeam($team);
    return $team===$away ? $home : ($team===$home ? $away : null);
}
function profileTeam(?string $team): ?string { return match(strtoupper($team??'')) {'KT'=>'KT','SK','SSG'=>'SSG','HT','KIA'=>'KIA','OB'=>'두산','SS'=>'삼성','LT'=>'롯데','HH'=>'한화','WO','넥센','히어로즈'=>'키움',default=>$team}; }
function profileGameMeta(array $row, array $stadiums): array {
    $id=$row['game_id']; $opponent=profileOpponent($id,$row['team']);
    $homeCode=substr($id,10,2);
    $codes=['HT'=>'KIA','LG'=>'LG','SK'=>'SSG','OB'=>'두산','SS'=>'삼성','LT'=>'롯데','KT'=>'KT','HH'=>'한화','NC'=>'NC','WO'=>'키움'];
    $home=$codes[$homeCode]??null; $team=profileTeam($row['team']);
    $isAway=$home && $team && $opponent ? $team!==$home : null;
    $schedule=$stadiums[substr($id,0,13)]??null;
    if(is_array($schedule)&&isset($schedule['away_team'],$schedule['home_team'])) {
        $away=profileTeam($schedule['away_team']);$home=profileTeam($schedule['home_team']);
        if($team===$away||$team===$home){$isAway=$team===$away;$opponent=$isAway?$home:$away;}
    }
    $result=null;
    if(is_array($schedule)&&$isAway!==null&&isset($schedule['away_score'],$schedule['home_score'])) {
        $own=(int)$schedule[$isAway?'away_score':'home_score'];$other=(int)$schedule[$isAway?'home_score':'away_score'];
        $result=($own>$other?'W':($own<$other?'L':'D')).' '.$schedule['away_score'].'-'.$schedule['home_score'];
    }
    return ['gameId'=>$id,'date'=>$row['game_date'],'opponent'=>$opponent,'isAway'=>$isAway,'location'=>$isAway===null?null:($isAway?'원정':'홈'),'stadium'=>is_array($schedule)?$schedule['stadium']:$schedule,'result'=>$result];
}
function profileRecords(PDO $db, array $player, array $schedule, ?int $selectedYear=null, string $seasonType='regular'): ?array {
    if($selectedYear!==null&&$selectedYear>=1982&&$selectedYear<=2000)return profileHistoricalOverview($db,$player,$seasonType,$selectedYear);
    $pitcher=str_contains((string)$player['Pos'],'투수');
    $table=$pitcher?'kbo_season_pitch_records':'kbo_season_records';
    $leagueLevel=$seasonType==='futures'?2:1;
    $bounds=[]; foreach ($schedule as $year=>$seasons) { [$start,$end]=$leagueLevel===2?[$year.'-01-01',$year.'-12-31']:$seasons['regular']; if ($start && $end) $bounds[]="(game_date BETWEEN ".$db->quote($start).' AND '.$db->quote($end).')'; }
    $filter='('.implode(' OR ',$bounds).')';
    if($selectedYear!==null) {
        [$start,$end]=$seasonType==='futures'?[$selectedYear.'-01-01',$selectedYear.'-12-31']:($schedule[$selectedYear][$seasonType]??['','']);
        if(!$start||!$end)return null;
        $filter='game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end);
    }
    // Keep game_code indexable: convert the record ID, using the schedule's collation.
    if($leagueLevel===2)$filter.=" AND NOT EXISTS (SELECT 1 FROM kbo_schedule s WHERE s.league_level=2 AND s.game_code=CONVERT(LEFT(`$table`.game_id,13) USING utf8mb4) COLLATE utf8mb4_general_ci AND s.is_allstar=1)";
    $stmt=$db->prepare("SELECT * FROM `$table` WHERE league_level=$leagueLevel AND player_id=? AND $filter ORDER BY game_date DESC,game_id DESC");
    $stmt->execute([$player['PlayerId']]); $all=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$all) return $leagueLevel===1?profileHistoricalOverview($db,$player,$seasonType,$selectedYear):null;
    $year=(int)substr($all[0]['game_date'],0,4); $retired=$selectedYear===null&&(string)$player['IsKbodle']==='0';
    $rows=$retired ? $all : array_values(array_filter($all,static fn($row)=>(int)substr($row['game_date'],0,4)===$year));
    $groups=[]; foreach ($rows as $row) $groups[$row['game_id']][]=$row;
    $recent=[];$box=[];$rolling=[];
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Seoul'));
    $anchor=(int)$today->format('Y')===$year&&!$retired?$today:new DateTimeImmutable($all[0]['game_date'],new DateTimeZone('Asia/Seoul'));
    $leagueSchedule=$schedule;
    if($leagueLevel===2)foreach($leagueSchedule as $y=>&$season)$season['regular']=[$y.'-01-01',$y.'-12-31'];
    unset($season);
    $codes=array_map(static fn($id)=>substr($id,0,13),array_keys($groups));
    $ms=$db->prepare('SELECT game_code,stadium,away_team,home_team,away_score,home_score FROM kbo_schedule WHERE league_level='.$leagueLevel.' AND game_code IN ('.implode(',',array_fill(0,count($codes),'?')).')');$ms->execute($codes);$stadiums=[];foreach($ms->fetchAll(PDO::FETCH_ASSOC) as $scheduled)$stadiums[$scheduled['game_code']]=$scheduled;
    if (!$pitcher) {
        $leagueTotals=['cum_ab'=>0,'cum_h'=>0,'cum_ob'=>0,'cum_sf'=>0,'cum_tb'=>0];
        $years=array_unique(array_map(static fn($row)=>(int)substr($row['game_date'],0,4),$rows));
        $ls=$db->prepare('SELECT cum_ab,cum_h,cum_ob,cum_sf,cum_tb FROM kbo_league_records WHERE year=? AND game_date BETWEEN ? AND ? ORDER BY game_date DESC LIMIT 1');
        if($leagueLevel===1)foreach($years as $y) { $ls->execute([$y,...$schedule[$y]['regular']]); $l=$ls->fetch(PDO::FETCH_ASSOC); if($l) foreach($leagueTotals as $key=>$value) $leagueTotals[$key]+=(int)$l[$key]; }
        if($leagueLevel===2){require_once __DIR__.'/player-year-league.php';$leagueYears=profileLeaguePitchingContexts($db,$leagueSchedule,2);foreach($years as $y)foreach($leagueTotals as $key=>$value)$leagueTotals[$key]+=(int)($leagueYears[$y][$key]??0);}
        $ld=$leagueTotals['cum_ab']+$leagueTotals['cum_ob']+$leagueTotals['cum_sf'];
        $league=['obp'=>$ld?($leagueTotals['cum_h']+$leagueTotals['cum_ob'])/$ld:null,'slg'=>$leagueTotals['cum_ab']?$leagueTotals['cum_tb']/$leagueTotals['cum_ab']:null];
        $stats=profileBatStats($rows,$league);
        foreach([7,15,30] as $count) {
            $window=[];$gameCount=0;$start=$anchor->modify('-'.($count-1).' days')->format('Y-m-d');
            foreach($groups as $events)if($events[0]['game_date']>=$start&&$events[0]['game_date']<=$anchor->format('Y-m-d')){$window=array_merge($window,$events);$gameCount++;}
            $summary=profileBatWindow($window,$league,$count);$summary['games']=$gameCount;$summary['startDate']=$start;$summary['endDate']=$anchor->format('Y-m-d');$rolling[]=$summary;
        }
        foreach($groups as $id=>$events) {
            $parsed=array_map('profileBatEvent',$events);
            $text=profileSum($parsed,'ab').'타수 '.profileSum($parsed,'h').'안타';
            foreach(['hr'=>'홈런','rbi'=>'타점','sb'=>'도루'] as $key=>$label) { $value=profileSum($parsed,$key); if($value>0) $text.=' '.$value.$label; }
            $game=profileGameMeta($events[0],$stadiums);
            $game['text']=$text;$game['badge']=null;
            if(count($recent)<5)$recent[]=$game;
            if((int)substr($game['date'],0,4)===$year) {
                $game['order']=null;foreach($events as $event) if(isset($event['order'])) { $game['order']=$event['order'];break; }
                $starts=array_values(array_filter(array_column($events,'is_gs'),static fn($value)=>$value!==null));
                $game['isStarter']=$starts ? in_array(1,array_map('intval',$starts),true) : null;
                foreach(['pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','sf','sh','sb','cs','gdp'] as $key)$game[$key]=profileSum($parsed,$key);
                $game['position']=profilePosition($events,$game['isStarter']);
                $notes=[];if($game['h']-$game['doubles']-$game['triples']-$game['hr']>0&&$game['doubles']>0&&$game['triples']>0&&$game['hr']>0)$notes[]='사이클링 히트';
                if(array_filter($events,static fn($event)=>(int)($event['is_gwrbi']??0)===1))$notes[]='결승타';$game['notes']=implode(', ',$notes);
                $box[]=$game;
            }
        }
    } else {
        // Pitching table lacks H/BB/K: derive them from opponent plate appearances.
        // The legacy batter table has no game/pitcher index. Avoid a nested join
        // scanning it once per appearance; scan once for the selected game IDs.
        $gameIds=array_keys($groups);
        $pc=$db->prepare('SELECT game_id,team,COUNT(*) AS cnt FROM kbo_season_pitch_records WHERE league_level='.$leagueLevel.' AND game_id IN ('.implode(',',array_fill(0,count($gameIds),'?')).') GROUP BY game_id,team');$pc->execute($gameIds);$pitchCounts=[];foreach($pc->fetchAll(PDO::FETCH_ASSOC) as $p)$pitchCounts[$p['game_id'].'|'.profileTeam($p['team'])]=(int)$p['cnt'];
        $qs=$db->prepare('SELECT game_id,pa_result,sb,cs,rbi,r FROM kbo_season_records WHERE league_level='.$leagueLevel.' AND pitcher_id=? AND game_id IN ('.implode(',',array_fill(0,count($gameIds),'?')).')');
        $qs->execute([$player['PlayerId'],...$gameIds]); $faced=[];
        foreach($qs->fetchAll(PDO::FETCH_ASSOC) as $event) $faced[$event['game_id']][]=profileBatEvent($event);
        $outs=0;$wins=$holds=$saves=0;$er=0;$erKnown=true;$h=$bb=$so=0;$facedKnown=true;
        $summaries=[];
        foreach($rows as $row) {
            $outs+=profileInningOuts($row['inning']); $erKnown=$erKnown && isset($row['er']);$er+=(int)$row['er'];
            $wins+=(int)in_array($row['record'],['승','W','승리'],true); $holds+=(int)in_array($row['record'],['홀','H','홀드'],true);$saves+=(int)in_array($row['record'],['세','S','세이브'],true);
            $f=$faced[$row['game_id']]??[]; $facedKnown=$facedKnown && count($f)>0;
            $h+=profileSum($f,'h')??0;$bb+=profileSum($f,'bb')??0;$so+=profileSum($f,'so')??0;
        }
        require_once __DIR__.'/player-year-league.php';
        $eraPlus=profileOverviewEraPlus($rows,profileLeaguePitchingContexts($db,$leagueSchedule,$leagueLevel));
        $stats=[['ERA',$outs&&$erKnown?number_format($er*27/$outs,2):null],['승리',$wins],['홀드',$holds],['세이브',$saves],['이닝',profileInningText($outs)],['삼진',$facedKnown?$so:null],['WHIP',$outs&&$facedKnown?number_format(($h+$bb)*3/$outs,2):null],['ERA+',$eraPlus]];
        foreach($rows as $row) {
            $f=$faced[$row['game_id']]??[];
            $r=$row['r']; $text=profileInningText(profileInningOuts($row['inning'])).'이닝 '.($r??'—').'실점';
            if($r!==null && (int)$r!==0 && (!isset($row['er']) || (int)$r!==(int)$row['er'])) $text.='('.($row['er']??'—').'자책)';
            $text.=' '.($f?profileSum($f,'so'):'—').'삼진';
            $badge=match($row['record']) { '승','W','승리'=>'승리','패','L','패전'=>'패전','홀','H','홀드'=>'홀드','세','S','세이브'=>'세이브',default=>null };
            $game=profileGameMeta($row,$stadiums);
            $game+=['text'=>$text,'badge'=>$badge,'role'=>isset($row['order'])?((int)$row['order']===1?'선발':'구원'):null,'innings'=>profileInningText(profileInningOuts($row['inning'])),'r'=>$row['r'],'er'=>$row['er']];
            $game['isStarter']=isset($row['order'])?(int)$row['order']===1:null;
            foreach(['so','h','hr','bb','hbp'] as $key)$game[$key]=$f?profileSum($f,$key):null;
            $game['notes']=profilePitchNote($game,($pitchCounts[$row['game_id'].'|'.profileTeam($row['team'])]??0)===1);
            $summaries[]=$game;
            if(count($recent)<5)$recent[]=$game;
            if((int)substr($game['date'],0,4)===$year)$box[]=$game;
        }
        foreach([7,15,30] as $count){$start=$anchor->modify('-'.($count-1).' days')->format('Y-m-d');$window=array_values(array_filter($summaries,static fn($game)=>$game['date']>=$start&&$game['date']<=$anchor->format('Y-m-d')));$summary=profilePitchWindow($window,$count);$summary['games']=count($window);$summary['startDate']=$start;$summary['endDate']=$anchor->format('Y-m-d');$rolling[]=$summary;}
    }
    $historicalIncluded=false;
    if($retired&&$leagueLevel===1&&profileHistoricalRows($db,(string)$player['PlayerId'],$pitcher)){
        require_once __DIR__.'/player-year-records.php';$totals=profileYearRecords($db,(string)$player['PlayerId'],$pitcher,$schedule);
        $stats=profileSeasonDisplayStats($totals['career'],$pitcher);$historicalIncluded=true;
    }
    return ['year'=>$year,'career'=>$retired,'leagueLevel'=>$leagueLevel,'season'=>$seasonType,'pitcher'=>$pitcher,'stats'=>$stats,'recent'=>$recent,'games'=>$box,'rolling'=>$rolling,'historicalSeasonTotalsIncluded'=>$historicalIncluded];
}

function profilePosition(array $events, ?bool $starter): ?string {
    $raw=[];foreach($events as $event){$pos=trim((string)($event['pos']??''));if($pos!==''&&!in_array($pos,$raw,true))$raw[]=$pos;}
    if(!$raw)return null;
    $first=$raw[0];$role='';if($starter===false)$role=str_starts_with($first,'주')?'대주자':(str_starts_with($first,'타')?'대타':(str_starts_with($first,'교')?'교체':'대수비'));
    $names=['1'=>'투수','2'=>'포수','3'=>'1루','4'=>'2루','5'=>'3루','6'=>'유격','7'=>'좌익','8'=>'중견','9'=>'우익','D'=>'지명','지'=>'지명','투'=>'투수','포'=>'포수','一'=>'1루','二'=>'2루','三'=>'3루','유'=>'유격','좌'=>'좌익','중'=>'중견','우'=>'우익'];$positions=[];
    foreach($raw as $pos){foreach(preg_split('//u',$pos,-1,PREG_SPLIT_NO_EMPTY) as $token)if(isset($names[$token]))$positions[]=$names[$token];}
    $positions=array_values(array_unique($positions));return implode(' · ',array_filter([$role,implode('-',$positions)]))?:implode('-', $raw);
}
function profilePitchNote(array $game, bool $solePitcher): string {
    if(($game['isStarter']??false)!==true)return '';
    $outs=profileInningOuts($game['innings']);$result=$game['result']??'';
    // A complete game requires being the team's only pitcher and a known final result.
    if($solePitcher&&$outs>=15&&preg_match('/^([WL]) /',$result,$m))return ((isset($game['r'])&&(int)$game['r']===0)?'완봉':'완투').($m[1]==='W'?'승':'패');
    if(!isset($game['er']))return '';
    if($outs>=24&&$game['er']<=1)return 'DS';
    if($outs>=21&&$game['er']<=3)return 'QS+';
    if($outs>=18&&$game['er']<=3)return 'QS';
    return '';
}
function profileBatWindow(array $events, ?array $league, int $count): array {
    $parsed=array_map('profileBatEvent',$events);$s=['label'=>"최근 {$count}일"];
    foreach(['pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','tb','sf','sh'] as $key)$s[$key]=$parsed?profileSum($parsed,$key):0;
    $avg=$s['ab']?$s['h']/$s['ab']:null;$den=$s['ab']+$s['bb']+$s['hbp']+$s['sf'];
    $obp=$den?($s['h']+$s['bb']+$s['hbp'])/$den:null;$slg=$s['ab']?$s['tb']/$s['ab']:null;
    $s['avg']=$avg===null?null:number_format($avg,3);$s['obp']=$obp===null?null:number_format($obp,3);$s['slg']=$slg===null?null:number_format($slg,3);
    $s['ops']=$obp===null||$slg===null?null:number_format($obp+$slg,3);
    $s['opsPlus']=$obp!==null&&$slg!==null&&($league['obp']??0)>0&&($league['slg']??0)>0?round(100*($obp/$league['obp']+$slg/$league['slg']-1)):null;
    return $s;
}
function profilePitchWindow(array $games, int $count): array {
    $s=['label'=>"최근 {$count}일",'starts'=>count(array_filter($games,static fn($g)=>($g['isStarter']??false)===true))];
    $outs=array_sum(array_map(static fn($game)=>profileInningOuts($game['innings']),$games));
    foreach(['r','er','so','h','hr','bb','hbp'] as $key)$s[$key]=$games?profileSum($games,$key):0;
    foreach(['wins'=>'승리','losses'=>'패전','saves'=>'세이브','holds'=>'홀드'] as $key=>$badge)$s[$key]=count(array_filter($games,static fn($game)=>($game['badge']??null)===$badge));
    $s['innings']=profileInningText($outs);
    $s['era']=$outs&&$s['er']!==null?number_format($s['er']*27/$outs,2):null;
    $s['whip']=$outs&&$s['h']!==null&&$s['bb']!==null?number_format(($s['h']+$s['bb'])*3/$outs,2):null;
    if(array_filter($games,static fn($game)=>!isset($game['isStarter'])))$s['starts']=null;
    return $s;
}

function profileOverviewEraPlus(array $rows, array $leagueYears): ?int {
    $er=profileSum($rows,'er');$weightedEra=0;
    if($er===null||$er<=0)return null;
    foreach($rows as $row){
        $outs=profileInningOuts($row['inning']);if($outs===0)continue;
        $era=$leagueYears[(int)substr($row['game_date'],0,4)]['era']??null;
        if($era===null)return null;
        $weightedEra+=$era*$outs;
    }
    return $weightedEra>0?(int)round(100*$weightedEra/($er*27)):null;
}
