<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';
require_once __DIR__.'/player-rankings.php';
require_once __DIR__.'/player-year-metrics.php';
require_once __DIR__.'/player-year-league.php';
require_once __DIR__.'/player-batting-league.php';
require_once __DIR__.'/player-season-totals.php';

/*
 * 연도별 기록 '기본' 탭의 리그 1위 계산.
 * 결과: ['batter'=>[key=>[playerId,...]], 'pitcher'=>[...]] (정규시즌, 1군)
 * - 병살·도루자·패전·실점·자책·피안타·피홈런·볼넷·사구(투수) 같은 부정적 기록은 제외한다.
 * - 비율 기록은 규정 타석(팀 경기×3.1)·규정 이닝(팀 경기×1)을 채운 선수만 비교한다.
 */
const PROFILE_LEADER_BATTER_KEYS = ['games','starts','avg','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','sf','sh','sb','obp','slg','ops','effectiveOps','opsPlus'];
const PROFILE_LEADER_BATTER_RATES = ['avg','obp','slg','ops','effectiveOps','opsPlus'];
const PROFILE_LEADER_PITCHER_KEYS = ['games','starts','era','wins','saves','holds','innings','so','winPct','whip','fip','eraPlus'];
const PROFILE_LEADER_PITCHER_RATES = ['era','whip','fip','eraPlus'];
const PROFILE_LEADER_LOWER_BETTER = ['era','whip','fip'];

function profileYearLeaderPick(array $stats, array $keys, array $rates, array $qualified): array {
    $leaders=[];
    foreach($keys as $key){
        $best=null;$ids=[];
        foreach($stats as $id=>$s){
            $value=$s[$key]??null;
            if($value===null||$value==='')continue;
            // 승률은 KBO 승률 1위 기준처럼 10승 이상만 비교한다.
            if($key==='winPct'){if((int)($s['wins']??0)<10)continue;}
            elseif(in_array($key,$rates,true)&&!($qualified[$id]??false))continue;
            $v=$key==='innings'?profileInningOuts((string)$value):(float)$value;
            $lower=in_array($key,PROFILE_LEADER_LOWER_BETTER,true);
            if($best===null||($lower?$v<$best:$v>$best)){$best=$v;$ids=[(int)$id];}
            elseif(abs($v-$best)<1e-9)$ids[]=(int)$id;
        }
        // 0이 최고값이면(모두 0) 1위로 보지 않는다. 낮을수록 좋은 기록은 0도 유효하다.
        if($best!==null&&($best>0||in_array($key,PROFILE_LEADER_LOWER_BETTER,true)))$leaders[$key]=$ids;
    }
    return $leaders;
}

function profileComputeModernYearLeaders(PDO $db,int $year,array $schedule): array {
    [$start,$end]=$schedule[$year]['regular']??['',''];
    if(!$start||!$end)return ['batter'=>[],'pitcher'=>[]];
    $range=[$start,$end];
    // 팀 경기 수(규정 타석·이닝 기준)
    $q=$db->prepare('SELECT away_team,home_team FROM kbo_schedule WHERE league_level=1 AND game_date BETWEEN ? AND ? AND away_score IS NOT NULL AND home_score IS NOT NULL'.profileNotTiebreakerSql('game_code').'');$q->execute($range);
    $teamGames=[];while($row=$q->fetch(PDO::FETCH_ASSOC))foreach($row as $team){$team=profileTeam($team);$teamGames[$team]=($teamGames[$team]??0)+1;}
    $league=profileBattingLeagueContexts($db,$schedule)[$year]??null;
    $ld=$league?($league['cum_ab']+$league['cum_ob']+$league['cum_sf']):0;
    $lobp=$ld?($league['cum_h']+$league['cum_ob'])/$ld:0;$lslg=($league['cum_ab']??0)?$league['cum_tb']/$league['cum_ab']:0;
    // 마지막 소속팀(시즌 중 이적은 마지막 팀 경기 수로 규정을 본다. 순위 계산과 같은 방식)
    $lastTeam=static function(string $table)use($db,$range): array {
        $q=$db->prepare("SELECT player_id,team,MAX(game_date) d FROM `$table` WHERE league_level=1 AND game_date BETWEEN ? AND ?".profileNotTiebreakerSql()." GROUP BY player_id,team");$q->execute($range);
        $teams=[];$dates=[];while($r=$q->fetch(PDO::FETCH_ASSOC)){$id=(int)$r['player_id'];if(!isset($dates[$id])||$r['d']>$dates[$id]){$dates[$id]=$r['d'];$teams[$id]=profileTeam($r['team']);}}
        return $teams;
    };

    // ---- 타자: 타석 결과·도루·도루자 조합별로 SQL에서 묶어 PHP 부담을 줄인다.
    $sumKeys=['pa','ab','h','doubles','triples','hr','bb','hbp','sf','sh','tb'];
    $bat=[];
    $q=$db->prepare('SELECT player_id,pa_result,sb,cs,COUNT(*) n,SUM(rbi) rbi,COUNT(rbi) rbi_n,SUM(r) r,COUNT(r) r_n FROM kbo_season_records WHERE league_level=1 AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().' GROUP BY player_id,pa_result,sb,cs');$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$row['player_id'];$n=(int)$row['n'];
        $bat[$id]??=['sum'=>array_fill_keys([...$sumKeys,'rbi','r','sb','cs'],0),'missing'=>[],'eff'=>[0,0,0,0]];
        $e=profileAdvancedBatEvent(['pa_result'=>$row['pa_result'],'sb'=>$row['sb'],'cs'=>$row['cs']]);
        foreach($sumKeys as $key)$bat[$id]['sum'][$key]+=$e[$key]*$n;
        foreach(['sb','cs'] as $key){if($row[$key]===null)$bat[$id]['missing'][$key]=true;else $bat[$id]['sum'][$key]+=(int)$row[$key]*$n;}
        foreach(['rbi','r'] as $key){if((int)$row[$key.'_n']<$n)$bat[$id]['missing'][$key]=true;$bat[$id]['sum'][$key]+=(int)$row[$key];}
        // 실질 OPS: profileYearTotals와 같은 보정(도루는 루타 추가, 도루자는 앞선 출루를 지움)
        $sb=(int)$row['sb'];$cs=(int)$row['cs'];$a=$e['ab'];$h=$e['h'];$tb=$e['tb'];$ob=$e['bb']+$e['hbp'];$on=$h>0||$ob>0;
        if($on&&$cs>0){$h=0;$tb=0;if(!$e['h']){$a=1;$ob--;}}
        elseif($on&&$sb>0)$tb+=$sb;elseif(!$on&&$sb>0)$a=0;
        $bat[$id]['eff'][0]+=$a*$n;$bat[$id]['eff'][1]+=$h*$n;$bat[$id]['eff'][2]+=$tb*$n;$bat[$id]['eff'][3]+=$ob*$n;
    }
    $q=$db->prepare('SELECT player_id,COUNT(DISTINCT game_id) g,COUNT(DISTINCT CASE WHEN is_gs=1 THEN game_id END) gs,SUM(is_gs IS NULL) gs_unknown FROM kbo_season_records WHERE league_level=1 AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().' GROUP BY player_id');$q->execute($range);
    $batGames=[];while($r=$q->fetch(PDO::FETCH_ASSOC))$batGames[(int)$r['player_id']]=$r;
    $batTeams=$lastTeam('kbo_season_records');
    $batStats=[];$batQualified=[];
    foreach($bat as $id=>$b){
        $s=$b['sum'];foreach($b['missing'] as $key=>$_)$s[$key]=null;
        $s['games']=(int)($batGames[$id]['g']??0);$s['starts']=(int)($batGames[$id]['gs_unknown']??1)>0?null:(int)$batGames[$id]['gs'];
        $ab=$s['ab'];$den=$ab+$s['bb']+$s['hbp']+$s['sf'];
        $obp=$den?($s['h']+$s['bb']+$s['hbp'])/$den:null;$slg=$ab?$s['tb']/$ab:null;
        $s['avg']=$ab?number_format($s['h']/$ab,3,'.',''):null;
        $s['obp']=$obp===null?null:number_format($obp,3,'.','');$s['slg']=$slg===null?null:number_format($slg,3,'.','');
        $s['ops']=$obp===null||$slg===null?null:number_format($obp+$slg,3,'.','');
        $s['opsPlus']=$obp!==null&&$slg!==null&&$lobp&&$lslg?round(100*($obp/$lobp+$slg/$lslg-1)):null;
        [$eab,$eh,$etb,$eob]=$b['eff'];$ed=$eab+$eob+$s['sf'];
        $s['effectiveOps']=$s['sb']!==null&&$s['cs']!==null&&$ed&&$eab?number_format(($eh+$eob)/$ed+$etb/$eab,3,'.',''):null;
        $batStats[$id]=$s;$g=$teamGames[$batTeams[$id]??'']??0;$batQualified[$id]=$g>0&&$s['pa']>=floor($g*3.1);
    }

    // ---- 투수: 상대한 타석 결과(탈삼진·피안타·볼넷·사구·피홈런)
    $faces=[];
    $q=$db->prepare('SELECT pitcher_id,pa_result,COUNT(*) n FROM kbo_season_records WHERE league_level=1 AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().' AND pitcher_id IS NOT NULL GROUP BY pitcher_id,pa_result');$q->execute($range);
    while($row=$q->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$row['pitcher_id'];$e=profileAdvancedBatEvent(['pa_result'=>$row['pa_result']]);if(!$e['pa'])continue;
        foreach(['so','h','bb','hbp','hr'] as $key)$faces[$id][$key]=($faces[$id][$key]??0)+$e[$key]*(int)$row['n'];
    }
    $q=$db->prepare('SELECT pitcher_id,COUNT(DISTINCT game_id) g FROM kbo_season_records WHERE league_level=1 AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().' AND pitcher_id IS NOT NULL AND pa_result IS NOT NULL AND pa_result<>\'\' GROUP BY pitcher_id');$q->execute($range);
    $facedGames=[];while($r=$q->fetch(PDO::FETCH_ASSOC))$facedGames[(int)$r['pitcher_id']]=(int)$r['g'];
    $q=$db->prepare("SELECT player_id,COUNT(DISTINCT game_id) g,SUM(`order`=1) gs,SUM(`order` IS NULL) gs_unknown,SUM(er) er,COUNT(er) er_n,COUNT(*) n,
        SUM(record IN ('승','W','승리')) wins,SUM(record IN ('패','L','패전')) losses,SUM(record IN ('세','S','세이브')) saves,SUM(record IN ('홀','H','홀드')) holds
        FROM kbo_season_pitch_records WHERE league_level=1 AND game_date BETWEEN ? AND ?".profileNotTiebreakerSql()." GROUP BY player_id");$q->execute($range);
    $pitch=[];while($r=$q->fetch(PDO::FETCH_ASSOC))$pitch[(int)$r['player_id']]=$r+['outs'=>0];
    $q=$db->prepare('SELECT player_id,inning,COUNT(*) n FROM kbo_season_pitch_records WHERE league_level=1 AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().' GROUP BY player_id,inning');$q->execute($range);
    while($r=$q->fetch(PDO::FETCH_ASSOC)){$id=(int)$r['player_id'];if(isset($pitch[$id]))$pitch[$id]['outs']+=profileInningOuts((string)$r['inning'])*(int)$r['n'];}
    $pitchTeams=$lastTeam('kbo_season_pitch_records');
    $pitchLeague=profileLeaguePitchingContexts($db,$schedule)[$year]??[];
    $pitchStats=[];$pitchQualified=[];
    foreach($pitch as $id=>$p){
        $f=$faces[$id]??['so'=>0,'h'=>0,'bb'=>0,'hbp'=>0,'hr'=>0];$outs=$p['outs'];
        $erKnown=(int)$p['er_n']===(int)$p['n'];$known=($facedGames[$id]??0)>=(int)$p['g'];
        $s=['games'=>(int)$p['g'],'starts'=>(int)$p['gs_unknown']>0?null:(int)$p['gs'],'wins'=>(int)$p['wins'],'saves'=>(int)$p['saves'],'holds'=>(int)$p['holds'],'innings'=>profileInningText($outs)];
        $w=(int)$p['wins'];$l=(int)$p['losses'];$s['winPct']=$w+$l>0?number_format($w/($w+$l),3,'.',''):null;
        $er=(int)$p['er'];
        $s['era']=$outs&&$erKnown?number_format($er*27/$outs,2,'.',''):null;
        $s['so']=$known?$f['so']:null;
        $s['whip']=$outs&&$known?number_format(($f['h']+$f['bb'])*3/$outs,2,'.',''):null;
        $s['fip']=$outs&&$known&&isset($pitchLeague['fipConstant'])?number_format((13*$f['hr']+3*($f['bb']+$f['hbp'])-2*$f['so'])*3/$outs+$pitchLeague['fipConstant'],2,'.',''):null;
        $s['eraPlus']=$outs&&$erKnown&&$er>0&&isset($pitchLeague['era'])?(int)round(100*$pitchLeague['era']*$outs/($er*27)):null;
        $pitchStats[$id]=$s;$g=$teamGames[$pitchTeams[$id]??'']??0;$pitchQualified[$id]=$g>0&&$outs>=$g*3;
    }
    return [
        'batter'=>profileYearLeaderPick($batStats,PROFILE_LEADER_BATTER_KEYS,PROFILE_LEADER_BATTER_RATES,$batQualified),
        'pitcher'=>profileYearLeaderPick($pitchStats,PROFILE_LEADER_PITCHER_KEYS,PROFILE_LEADER_PITCHER_RATES,$pitchQualified),
    ];
}

function profileComputeHistoricalYearLeaders(PDO $db,int $year): array {
    $out=[];
    foreach([false,true] as $pitcher){
        $league=$pitcher?profileHistoricalPitchingContexts($db,'regular'):profileBattingLeagueContexts($db,[],'regular');
        $byPlayer=[];foreach(profileHistoricalRows($db,'',$pitcher,'regular',$year) as $row)$byPlayer[(int)$row['player_id']][]=$row;
        $stats=[];$teamOf=[];$teamGames=[];
        foreach($byPlayer as $id=>$rows){
            $stats[$id]=profileSeasonStats($rows,$pitcher,$league);
            $last=end($rows);$team=(string)($last['team_name']??'');$teamOf[$id]=$team;
            // 시즌 합계 자료에는 팀 경기 수가 없어, 그 팀 선수의 최다 출장 경기를 팀 경기 수로 본다.
            if(!$pitcher)$teamGames[$team]=max($teamGames[$team]??0,(int)($stats[$id]['games']??0));
        }
        if($pitcher){
            // 투수 규정 이닝은 같은 해 타자 자료의 팀 최다 출장으로 추정한다.
            foreach(profileHistoricalRows($db,'',false,'regular',$year) as $row){$team=(string)$row['team_name'];$teamGames[$team]=max($teamGames[$team]??0,(int)$row['games']);}
        }
        $qualified=[];
        foreach($stats as $id=>$s){
            $g=$teamGames[$teamOf[$id]]??0;
            $qualified[$id]=$g>0&&($pitcher?(int)($s['innings_outs']??0)>=$g*3:(int)($s['pa']??0)>=floor($g*3.1));
        }
        $out[$pitcher?'pitcher':'batter']=profileYearLeaderPick($stats,$pitcher?PROFILE_LEADER_PITCHER_KEYS:PROFILE_LEADER_BATTER_KEYS,$pitcher?PROFILE_LEADER_PITCHER_RATES:PROFILE_LEADER_BATTER_RATES,$qualified);
    }
    return $out;
}

function profileYearLeaders(PDO $db,int $year,array $schedule): array {
    $dir=sys_get_temp_dir().'/wesiper-profile-year-leaders-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Year leader cache unavailable');
    $historical=$year>=1982&&$year<=2000;
    $path=$dir.'/v1-'.$year.'-'.hash('sha256',json_encode($historical?null:($schedule[$year]??null))).'.json';
    $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Year leader cache lock unavailable');
    try{
        // 지난 시즌은 기록이 바뀌지 않으므로 한 번 계산하면 계속 쓰고, 올해만 크롤러 갱신(revision)마다 다시 계산한다.
        $currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
        $revision=$year<$currentYear?'final':profileRankingRevision();
        if(is_file($path)){$cached=json_decode((string)file_get_contents($path),true);if(is_array($cached)&&($cached['revision']??null)===$revision&&is_array($cached['leaders']??null))return $cached['leaders'];}
        @set_time_limit(300);
        $leaders=$historical?profileComputeHistoricalYearLeaders($db,$year):(isset($schedule[$year])?profileComputeModernYearLeaders($db,$year,$schedule):['batter'=>[],'pitcher'=>[]]);
        $tmp=tempnam($dir,'leaders-');
        if($tmp!==false){try{if(file_put_contents($tmp,json_encode(['revision'=>$revision,'leaders'=>$leaders],JSON_THROW_ON_ERROR))!==false)rename($tmp,$path);}finally{if(is_file($tmp))unlink($tmp);}}
        return $leaders;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

/** 한 선수의 연도별 기록 행에 대해, 리그 1위였던 열을 연도별로 돌려준다: [year=>[key,...]] */
function profilePlayerYearLeaderKeys(PDO $db,string $pid,array $rows,bool $pitcher,array $schedule): array {
    $out=[];
    foreach($rows as $row){
        $year=(int)($row['year']??0);if(!$year)continue;
        try{$leaders=profileYearLeaders($db,$year,$schedule)[$pitcher?'pitcher':'batter']??[];}
        catch(Throwable $error){error_log('Year leaders unavailable: '.$error->getMessage());continue;}
        $keys=[];foreach($leaders as $key=>$ids)if(in_array((int)$pid,$ids,true))$keys[]=$key;
        if($keys)$out[(string)$year]=$keys;
    }
    return $out;
}
