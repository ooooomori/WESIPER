<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';
require_once __DIR__.'/player-rankings.php';
require_once __DIR__.'/player-year-metrics.php';
require_once __DIR__.'/player-year-league.php';
require_once __DIR__.'/player-season-totals.php';

function profileYearTotals(array $rows, bool $pitcher, array $faced, array $league, array $pitchContext=[]): array {
    $games=[];$starts=[];$knownStarts=[];
    foreach($rows as $row){$id=$row['game_id'];$games[$id]=true;
        $start=$pitcher?(isset($row['order'])?(int)$row['order']===1:null):(isset($row['is_gs'])?(int)$row['is_gs']===1:null);
        if($start!==null){$knownStarts[$id]=true;if($start)$starts[$id]=true;}
    }
    $s=['games'=>count($games),'starts'=>count($knownStarts)<count($games)?null:count($starts)];
    if($pitcher){
        $events=[];$known=true;$outs=0;
        foreach($rows as $row){$outs+=profileInningOuts($row['inning']);if(!isset($faced[$row['game_id']]))$known=false;else $events=array_merge($events,$faced[$row['game_id']]);}
        foreach(['r','er'] as $key)$s[$key]=profileSum($rows,$key);
        foreach(['so','h','hr','bb','hbp'] as $key)$s[$key]=$known?profileSum($events,$key):null;
        foreach(['wins'=>['승','W','승리'],'losses'=>['패','L','패전'],'saves'=>['세','S','세이브'],'holds'=>['홀','H','홀드']] as $key=>$labels)$s[$key]=count(array_filter($rows,static fn($r)=>in_array($r['record'],$labels,true)));
        // 승률 = 승 / (승+패). 승패가 없으면 비운다.
        $s['winPct']=$s['wins']+$s['losses']>0?number_format($s['wins']/($s['wins']+$s['losses']),3,'.',''):null;
        $s['innings']=profileInningText($outs);
        $s['era']=$outs&&$s['er']!==null?number_format($s['er']*27/$outs,2):null;
        $s['whip']=$outs&&$known?number_format(($s['h']+$s['bb'])*3/$outs,2):null;
        return profilePitcherAdvancedStats($rows,$s,$events,$known,$league['pitchingYears']??[],$pitchContext);
    }
    $parsed=array_map('profileAdvancedBatEvent',$rows);
    foreach(['pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','sf','tb'] as $key)$s[$key]=profileSum($parsed,$key);
    $den=$s['ab']+$s['bb']+$s['hbp']+$s['sf'];
    $obp=$den?($s['h']+$s['bb']+$s['hbp'])/$den:null;$slg=$s['ab']?$s['tb']/$s['ab']:null;
    $s['avg']=$s['ab']?number_format($s['h']/$s['ab'],3):null;
    $s['obp']=$obp===null?null:number_format($obp,3);$s['slg']=$slg===null?null:number_format($slg,3);
    $s['ops']=$obp===null||$slg===null?null:number_format($obp+$slg,3);
    $ld=($league['cum_ab']??0)+($league['cum_ob']??0)+($league['cum_sf']??0);
    $lobp=$ld?($league['cum_h']+$league['cum_ob'])/$ld:0;$lslg=($league['cum_ab']??0)?$league['cum_tb']/$league['cum_ab']:0;
    $s['opsPlus']=$obp!==null&&$slg!==null&&$lobp&&$lslg?round(100*($obp/$lobp+$slg/$lslg-1)):null;
    $s['opsLeagueYears']=$league['battingYears']??[];
    // Same effective OPS adjustment as kbocandle: steals add bases,
    // caught stealing removes the preceding hit/on-base credit.
    $eab=$eh=$etb=$eob=0;
    foreach($parsed as $e){$a=$e['ab'];$h=$e['h'];$tb=$e['tb'];$ob=$e['bb']+$e['hbp'];$on=$h>0||$ob>0;
        if($on&&$e['cs']>0){$h=0;$tb=0;if(!$e['h']){$a=1;$ob--;}}
        elseif($on&&$e['sb']>0)$tb+=$e['sb'];elseif(!$on&&$e['sb']>0)$a=0;
        $eab+=$a;$eh+=$h;$etb+=$tb;$eob+=$ob;
    }
    $ed=$eab+$eob+$s['sf'];$s['effectiveOps']=$ed&&$eab?number_format(($eh+$eob)/$ed+$etb/$eab,3):null;
    return profileBatterAdvancedStats($parsed,$s);
}

function profileYearPosition(array $rows): ?string {
    // Count each fielded position once per game, not once per plate appearance.
    $codes=['2'=>'C','3'=>'1B','4'=>'2B','5'=>'3B','6'=>'SS','7'=>'LF','8'=>'CF','9'=>'RF','D'=>'DH','지'=>'DH','포'=>'C','一'=>'1B','二'=>'2B','三'=>'3B','유'=>'SS','좌'=>'LF','중'=>'CF','우'=>'RF'];
    $games=[];
    foreach($rows as $row){
        $raw=trim((string)($row['pos']??''));
        if($raw==='')continue;
        $tokens=preg_split('//u',$raw,-1,PREG_SPLIT_NO_EMPTY);
        foreach($tokens as $token)if(isset($codes[$token]))$games[$row['game_id']][$codes[$token]]=true;
    }
    $counts=[];foreach($games as $positions)foreach($positions as $position=>$_)$counts[$position]=($counts[$position]??0)+1;
    if(!$counts)return null;
    arsort($counts,SORT_NUMERIC);
    return array_key_first($counts);
}

function profileYearRecords(PDO $db, string $pid, bool $pitcher, array $schedule, string $seasonType='regular'): array {
    if(!in_array($seasonType,['regular','preseason','postseason','futures'],true))throw new InvalidArgumentException('Invalid season');
    $leagueLevel=$seasonType==='futures'?2:1;
    if($seasonType!=='regular')foreach($schedule as $year=>&$season)$season['regular']=$seasonType==='futures'?[$year.'-01-01',$year.'-12-31']:($season[$seasonType]??['','']);
    unset($season);
    $dir=sys_get_temp_dir().'/wesiper-profile-year-records-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Year record cache unavailable');
    $key=hash('sha256',$pid.'|'.($pitcher?'pitcher':'batter').'|'.$seasonType.'|'.json_encode($schedule));
    $path="$dir/v12-$key.json";$lock=fopen("$path.lock",'c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Year record cache lock unavailable');
    try {
        // Shared with ranking caches: only a successful crawler update changes this revision.
        $revision=profileRankingRevision();
        if(is_file($path)){
            $cached=json_decode((string)file_get_contents($path),true);
            if(is_array($cached)&&($cached['revision']??null)===$revision&&is_array($cached['records']??null))return $cached['records'];
        }
        $records=profileComputeYearRecords($db,$pid,$pitcher,$schedule,$leagueLevel,$seasonType);
        $tmp=tempnam($dir,'years-');
        if($tmp===false)throw new RuntimeException('Year record cache write unavailable');
        try {
            if(file_put_contents($tmp,json_encode(['revision'=>$revision,'records'=>$records],JSON_THROW_ON_ERROR))===false||!rename($tmp,$path))throw new RuntimeException('Year record cache write failed');
        } finally { if(is_file($tmp))unlink($tmp); }
        return $records;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function profileComputeYearRecords(PDO $db, string $pid, bool $pitcher, array $schedule, int $leagueLevel=1, string $seasonType='regular'): array {
    $modern=profileComputeGameYearRecords($db,$pid,$pitcher,$schedule,$leagueLevel,$seasonType);
    if($leagueLevel!==1)return $modern;
    $historical=profileHistoricalYearRecords($db,$pid,$pitcher,$seasonType);
    if(!$historical['rows'])return $modern;
    $rows=array_merge($historical['rows'],$modern['rows']);usort($rows,static fn($a,$b)=>$a['year']<=>$b['year']);
    $career=profileMergeSeasonStats(array_values(array_filter([$historical['career'],$modern['career']],static fn($s)=>$s!==null)),$pitcher);$career['position']=$modern['career']['position']??null;
    // 팀별 통산: 시즌 합계(1982~2000)와 경기 기록(2001~) 중 같은 팀 이름끼리 합친다.
    $byTeam=[];
    foreach([$historical['careerTeams']??[],$modern['careerTeams']??[]] as $list)foreach($list as $item){
        $team=$item['team'];$byTeam[$team]??=['team'=>$team,'firstYear'=>$item['firstYear'],'lastYear'=>$item['lastYear'],'parts'=>[]];
        $byTeam[$team]['firstYear']=min($byTeam[$team]['firstYear'],$item['firstYear']);$byTeam[$team]['lastYear']=max($byTeam[$team]['lastYear'],$item['lastYear']);$byTeam[$team]['parts'][]=$item['stats'];
    }
    $careerTeams=[];foreach($byTeam as $item){$stats=count($item['parts'])===1?$item['parts'][0]:profileMergeSeasonStats($item['parts'],$pitcher);$careerTeams[]=['team'=>$item['team'],'firstYear'=>$item['firstYear'],'lastYear'=>$item['lastYear'],'stats'=>$stats];}
    usort($careerTeams,static fn($a,$b)=>$a['firstYear']<=>$b['firstYear']);
    return ['rows'=>$rows,'career'=>$career,'careerTeams'=>$careerTeams,'historicalSeasonTotalsIncluded'=>true];
}
function profileComputeGameYearRecords(PDO $db, string $pid, bool $pitcher, array $schedule, int $leagueLevel=1, string $seasonType='regular'): array {
    $bounds=[];foreach($schedule as $season){[$a,$b]=$season['regular'];if($a&&$b)$bounds[]='(game_date BETWEEN '.$db->quote($a).' AND '.$db->quote($b).')';}
    if(!$bounds)return ['rows'=>[],'career'=>null];
    $table=$pitcher?'kbo_season_pitch_records':'kbo_season_records';
    $allstar=$leagueLevel===2?" AND NOT EXISTS (SELECT 1 FROM kbo_schedule s WHERE s.league_level=2 AND s.game_code=CONVERT(LEFT(`$table`.game_id,13) USING utf8mb4) COLLATE utf8mb4_general_ci AND s.is_allstar=1)":'';
    $q=$db->prepare("SELECT * FROM `$table` WHERE league_level=$leagueLevel AND player_id=? AND (".implode(' OR ',$bounds).")".profileNotTiebreakerSql()."$allstar ORDER BY game_date,game_id");$q->execute([$pid]);$all=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$all)return ['rows'=>[],'career'=>null];
    $years=[];foreach($all as $row)$years[(int)substr($row['game_date'],0,4)][]=$row;ksort($years);
    $currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
    $positionQuery=$db->prepare('SELECT mainPos,birth FROM kbo_player_data WHERE player_id=? LIMIT 1');$positionQuery->execute([$pid]);$bio=$positionQuery->fetch(PDO::FETCH_ASSOC)?:[];
    $mainPosition=trim((string)($bio['mainPos']??''));
    $currentPosition=match(true){
        str_contains($mainPosition,'포수')=>'C',str_contains($mainPosition,'1루')=>'1B',str_contains($mainPosition,'2루')=>'2B',
        str_contains($mainPosition,'3루')=>'3B',str_contains($mainPosition,'유격')=>'SS',str_contains($mainPosition,'좌익')=>'LF',
        str_contains($mainPosition,'중견')=>'CF',str_contains($mainPosition,'우익')=>'RF',str_contains($mainPosition,'지명')=>'DH',
        default=>profileYearPosition([['game_id'=>'current','pos'=>$mainPosition]])};
    $faced=[];
    $pitchContext=[];$leaguePitch=[];
    if($pitcher){$ids=array_values(array_unique(array_column($all,'game_id')));$q=$db->prepare('SELECT game_id,pa_result,sb,cs,run_out,rbi,r FROM kbo_season_records WHERE league_level='.$leagueLevel.' AND pitcher_id=? AND game_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([$pid,...$ids]);while($e=$q->fetch(PDO::FETCH_ASSOC))$faced[$e['game_id']][]=profileAdvancedBatEvent($e);$pitchContext=profilePitcherGameContexts($db,$all,$leagueLevel);$leaguePitch=profileLeaguePitchingContexts($db,$schedule,$leagueLevel);}
    if(!$pitcher&&$seasonType!=='regular')$leaguePitch=profileLeaguePitchingContexts($db,$schedule,$leagueLevel);
    $out=[];$leagueAll=['cum_ab'=>0,'cum_h'=>0,'cum_ob'=>0,'cum_sf'=>0,'cum_tb'=>0];
    $battingYears=$pitcher?[]:profileBattingLeagueContexts($db,$schedule,$seasonType,$leagueLevel);
    foreach($years as $year=>$rows){
        $league=$pitcher?($leaguePitch[$year]??[]):($battingYears[$year]??[]);foreach(['cum_ab','cum_h','cum_ob','cum_sf','cum_tb'] as $key)$leagueAll[$key]+=(int)($league[$key]??0);
        if(!$pitcher){$league['battingYears']=[$year=>$battingYears[$year]??null];$leagueAll['battingYears'][$year]=$battingYears[$year]??null;}
        $league['pitchingYears']=$leaguePitch;$age=profileAgeOnJulyFirst($bio['birth']??null,$year);
        $teams=[];foreach($rows as $row)$teams[trim((string)$row['team'])?:'소속 미확인'][]=$row;
        $children=[];foreach($teams as $team=>$events)$children[]=['year'=>$year,'team'=>$team,'age'=>$age,'position'=>$pitcher?null:($year===$currentYear?$currentPosition:profileYearPosition($events)),'stats'=>profileYearTotals($events,$pitcher,$faced,$league,$pitchContext)];
        $out[]=['year'=>$year,'team'=>count($teams)>1?count($teams).'팀':array_key_first($teams),'age'=>$age,'position'=>$pitcher?null:($year===$currentYear?$currentPosition:profileYearPosition($rows)),'teams'=>count($teams)>1?$children:[],'stats'=>profileYearTotals($rows,$pitcher,$faced,$league,$pitchContext)];
    }
    $leagueAll['pitchingYears']=$leaguePitch;
    $career=profileYearTotals($all,$pitcher,$faced,$leagueAll,$pitchContext);
    $career['position']=$pitcher?null:profileYearPosition($all);
    // 팀별 통산: 같은 팀에서 뛴 경기만 모아 계산한다(OPS+ 리그 기준은 그 팀에서 뛴 시즌들).
    $teamEvents=[];foreach($all as $row)$teamEvents[trim((string)$row['team'])?:'소속 미확인'][]=$row;
    $careerTeams=[];
    foreach($teamEvents as $team=>$events){
        $teamYears=array_values(array_unique(array_map(static fn($r)=>(int)substr($r['game_date'],0,4),$events)));
        $teamLeague=['cum_ab'=>0,'cum_h'=>0,'cum_ob'=>0,'cum_sf'=>0,'cum_tb'=>0,'pitchingYears'=>$leaguePitch];
        if(!$pitcher)foreach($teamYears as $y){$l=$battingYears[$y]??[];foreach(['cum_ab','cum_h','cum_ob','cum_sf','cum_tb'] as $key)$teamLeague[$key]+=(int)($l[$key]??0);$teamLeague['battingYears'][$y]=$battingYears[$y]??null;}
        $stats=profileYearTotals($events,$pitcher,$faced,$teamLeague,$pitchContext);$stats['position']=$pitcher?null:profileYearPosition($events);
        $careerTeams[]=['team'=>$team,'firstYear'=>min($teamYears),'lastYear'=>max($teamYears),'stats'=>$stats];
    }
    return ['rows'=>$out,'career'=>$career,'careerTeams'=>$careerTeams];
}
