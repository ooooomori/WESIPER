<?php
declare(strict_types=1);
require_once __DIR__.'/player-batting-league.php';

function profileHistoricalRows(PDO $db,string $pid,bool $pitcher,string $season='regular',?int $year=null): array {
    $ids=match($season){'regular'=>[0],'preseason'=>[1],'postseason'=>[3,5,7],default=>[]};if(!$ids)return [];
    $table=$pitcher?'kbo_player_season_pitching_totals':'kbo_player_season_batting_totals';
    $sql="SELECT * FROM `$table` WHERE league_level=1 AND row_scope='total' AND year BETWEEN 1982 AND 2000 AND series_id IN (".implode(',',$ids).')';$params=[];
    if($pid!==''){$sql.=' AND player_id=?';$params[]=$pid;}if($year!==null){$sql.=' AND year=?';$params[]=$year;}
    $q=$db->prepare($sql.' ORDER BY year,series_id,player_id');$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function profileSeasonRatio($n,$d,int $p=3): ?string {return $n!==null&&$d!==null&&$d>0?number_format($n/$d,$p,'.',''):null;}
function profileHistoricalPitchingContexts(PDO $db,string $season): array {
    $ids=match($season){'regular'=>[0],'postseason'=>[3,5,7],default=>[]};if(!$ids)return [];
    $q=$db->query("SELECT year,series_id,COUNT(*) AS n,COUNT(innings_outs) AS known_outs,COUNT(er) AS known_er,COUNT(hr) AS known_hr,COUNT(bb) AS known_bb,COUNT(hbp) AS known_hbp,COUNT(so) AS known_so,SUM(innings_outs) AS outs,SUM(er) AS er,SUM(hr) AS hr,SUM(bb) AS bb,SUM(hbp) AS hbp,SUM(so) AS so FROM kbo_player_season_pitching_totals WHERE league_level=1 AND row_scope='total' AND year BETWEEN 1982 AND 2000 AND series_id IN (".implode(',',$ids).') GROUP BY year,series_id');
    $out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $era=$r['outs']>0&&$r['known_outs']===$r['n']&&$r['known_er']===$r['n']?$r['er']*27/$r['outs']:null;
        $known=$r['known_hr']===$r['n']&&$r['known_bb']===$r['n']&&$r['known_hbp']===$r['n']&&$r['known_so']===$r['n'];
        $out[$r['year'].'|'.$r['series_id']]=['era'=>$era,'fipConstant'=>$era!==null&&$known?$era-(13*$r['hr']+3*($r['bb']+$r['hbp'])-2*$r['so'])*3/$r['outs']:null];
    }return $out;
}
function profileSeasonRates(array $s,bool $pitcher): array {
    if($pitcher){
        $outs=$s['innings_outs']??profileInningOuts((string)($s['innings']??'0'));$s['innings_outs']=$outs;$s['innings']=profileInningText($outs);
        $s['winPct']=isset($s['wins'],$s['losses'])&&$s['wins']+$s['losses']>0?number_format($s['wins']/($s['wins']+$s['losses']),3,'.',''):null;
        $s['era']=profileSeasonRatio(isset($s['er'])?$s['er']*27:null,$outs,2);$s['whip']=profileSeasonRatio(isset($s['h'],$s['bb'])?($s['h']+$s['bb'])*3:null,$outs,2);
        foreach(['k9'=>'so','bb9'=>'bb','h9'=>'h','hr9'=>'hr'] as $k=>$f)$s[$k]=profileSeasonRatio(isset($s[$f])?$s[$f]*27:null,$outs,2);
        $s['kBb']=profileSeasonRatio($s['so']??null,$s['bb']??null,2);$s['kPct']=profileSeasonRatio(isset($s['so'])?$s['so']*100:null,$s['tbf']??null,1);$s['bbPct']=profileSeasonRatio(isset($s['bb'])?$s['bb']*100:null,$s['tbf']??null,1);
        $s['fip']=$outs>0&&isset($s['hr'],$s['bb'],$s['hbp'],$s['so'],$s['fipConstantWeighted'])?number_format((13*$s['hr']+3*($s['bb']+$s['hbp'])-2*$s['so'])*3/$outs+$s['fipConstantWeighted']/$outs,2,'.',''):null;
        $s['eraPlus']=$outs>0&&isset($s['eraWeighted'],$s['er'])&&$s['er']>0?(int)round(100*$s['eraWeighted']/($s['er']*27)):null;
        $missing=['reliefs','finishes','starterInnings','reliefInnings','pitches','pitchesPerInning','pitchesPerGame','qs','qsPlus','ds','opponentAvg','opponentObp','opponentSlg','opponentOps','groundFly','babip'];
    }else{
        $s['avg']=profileSeasonRatio($s['h'],$s['ab']);$s['slg']=profileSeasonRatio($s['tb'],$s['ab']);
        $obpSf=$s['obpSf']??$s['sf'];
        $den=isset($s['ab'],$s['bb'],$s['hbp'])&&$obpSf!==null?$s['ab']+$s['bb']+$s['hbp']+$obpSf:null;
        $s['obp']=profileSeasonRatio(isset($s['h'],$s['bb'],$s['hbp'])?$s['h']+$s['bb']+$s['hbp']:null,$den);
        $s['ops']=$s['obp']!==null&&$s['slg']!==null?number_format(($s['h']+$s['bb']+$s['hbp'])/$den+$s['tb']/$s['ab'],3,'.',''):null;
        $s['isoObp']=$s['obp']!==null&&$s['ab']>0?number_format(($s['h']+$s['bb']+$s['hbp'])/$den-$s['h']/$s['ab'],3,'.',''):null;
        $s['babip']=profileSeasonRatio(isset($s['h'],$s['hr'])?$s['h']-$s['hr']:null,isset($s['ab'],$s['so'],$s['hr'],$s['sf'])?$s['ab']-$s['so']-$s['hr']+$s['sf']:null);
        $s['isoSlg']=profileSeasonRatio(isset($s['tb'],$s['h'])?$s['tb']-$s['h']:null,$s['ab']);$s['bbPct']=profileSeasonRatio(isset($s['bb'])?$s['bb']*100:null,$s['pa'],1);$s['kPct']=profileSeasonRatio(isset($s['so'])?$s['so']*100:null,$s['pa'],1);$s['bbK']=profileSeasonRatio($s['bb'],$s['so'],2);
        $s['sbAttempts']=isset($s['sb'],$s['cs'])?$s['sb']+$s['cs']:null;$s['sbPct']=profileSeasonRatio(isset($s['sb'])?$s['sb']*100:null,$s['sbAttempts'],1);
        $s['opsPlus']=profileSeasonOpsPlus($s);
        $missing=['effectiveOps','woba','groundFly','spd','runOut','roe','go','fo','sbSecond','sbThird','sbHome'];
    }foreach($missing as $k)$s[$k]=null;return $s;
}
function profileSeasonStats(array $rows,bool $pitcher,array $league=[]): array {
    $fields=$pitcher?['games','wins','losses','saves','holds','r','er','so','h','hr','bb','hbp','tbf','innings_outs','cg','sho']:['games','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','so','sf','sh','ibb','e'];
    $s=[];foreach($fields as $k)$s[$k]=profileSum($rows,$k);$s['starts']=null;
    if($pitcher){$s['complete']=$s['cg'];$s['shutouts']=$s['sho'];$era=$constant=0;$eraKnown=$constantKnown=true;
        foreach($rows as $r){$ip=(int)$r['innings_outs'];if(!$ip)continue;$context=$league[$r['year'].'|'.$r['series_id']]??[];
            if(!isset($context['era']))$eraKnown=false;else $era+=$ip*$context['era'];
            if(!isset($context['fipConstant']))$constantKnown=false;else $constant+=$ip*$context['fipConstant'];
        }$s['eraWeighted']=$eraKnown?$era:null;$s['fipConstantWeighted']=$constantKnown?$constant:null;
    }else{
        $s['tb']=isset($s['h'],$s['doubles'],$s['triples'],$s['hr'])?$s['h']+$s['doubles']+2*$s['triples']+3*$s['hr']:null;
        $years=array_unique(array_column($rows,'year'));
        foreach($years as $year)$s['opsLeagueYears'][$year]=$league[$year]??null;
        // Official annual OBP before 1986 excluded sacrifice flies. Career
        // recomputation uses the full standard denominator across all years.
        $series=array_unique(array_column($rows,'series_id'));
        if(count($years)===1&&(int)reset($years)<=1985&&count($series)===1&&(int)reset($series)===0)$s['obpSf']=0;
    }
    return profileSeasonRates($s,$pitcher);
}
function profileMergeSeasonStats(array $stats,bool $pitcher): ?array {
    if(!$stats)return null;
    $fields=$pitcher?['games','starts','wins','losses','saves','holds','r','er','so','h','hr','bb','hbp','tbf','complete','shutouts']:['games','starts','pa','ab','h','doubles','triples','hr','rbi','r','bb','hbp','gdp','sb','cs','so','sf','sh','ibb','e','tb'];
    $s=[];foreach($fields as $k)$s[$k]=profileSum($stats,$k);
    if($pitcher)foreach(['eraWeighted','fipConstantWeighted'] as $key){$s[$key]=array_filter($stats,static fn($r)=>!isset($r[$key]))?null:array_sum(array_column($stats,$key));}
    if(!$pitcher){$s['opsLeagueYears']=[];foreach($stats as $item)foreach($item['opsLeagueYears']??[] as $year=>$context)$s['opsLeagueYears'][$year]=$context;}
    if($pitcher)$s['innings_outs']=array_sum(array_map(static fn($r)=>$r['innings_outs']??profileInningOuts((string)$r['innings']),$stats));return profileSeasonRates($s,$pitcher);
}
function profileHistoricalYearRecords(PDO $db,string $pid,bool $pitcher,string $season='regular'): array {
    $raw=profileHistoricalRows($db,$pid,$pitcher,$season);$years=[];foreach($raw as $r)$years[(int)$r['year']][]=$r;if(!$years)return ['rows'=>[],'career'=>null];
    $q=$db->prepare('SELECT birth FROM kbo_player_data WHERE player_id=?');$q->execute([$pid]);$birth=$q->fetchColumn();$out=[];
    $league=$pitcher?profileHistoricalPitchingContexts($db,$season):profileBattingLeagueContexts($db,[],$season);
    foreach($years as $year=>$rows){$teams=array_unique(array_column($rows,'team_name'));$age=null;
        if(is_string($birth)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$birth))$age=$year-(int)substr($birth,0,4)-(substr($birth,5)>'07-01'?1:0);
        $entry=['year'=>$year,'team'=>count($teams)>1?count($teams).'팀':reset($teams),'age'=>$age!==null&&$age>=0?$age:null,'position'=>null,'teams'=>[],'stats'=>profileSeasonStats($rows,$pitcher,$league),'source'=>'official_season_totals','granularity'=>'season','series'=>[]];
        foreach($rows as $r)$entry['series'][]=['series_id'=>(int)$r['series_id'],'team'=>$r['team_name'],'player_name'=>$r['player_name'],'stats'=>profileSeasonStats([$r],$pitcher,$league)];$out[]=$entry;
    }
    // 팀별 통산(시즌 합계 자료): 같은 팀 이름의 시즌 행을 모아 계산한다.
    $teamRows=[];foreach($raw as $r)$teamRows[(string)$r['team_name']][]=$r;$careerTeams=[];
    foreach($teamRows as $team=>$rows){$years=array_map('intval',array_column($rows,'year'));$careerTeams[]=['team'=>$team,'firstYear'=>min($years),'lastYear'=>max($years),'stats'=>profileSeasonStats($rows,$pitcher,$league)];}
    return ['rows'=>$out,'career'=>profileMergeSeasonStats(array_column($out,'stats'),$pitcher),'careerTeams'=>$careerTeams];
}
function profileSeasonDisplayStats(array $s,bool $pitcher): array {
    $labels=$pitcher?['ERA'=>'era','승리'=>'wins','홀드'=>'holds','세이브'=>'saves','이닝'=>'innings','삼진'=>'so','WHIP'=>'whip','ERA+'=>'eraPlus']:['타율'=>'avg','안타'=>'h','홈런'=>'hr','타점'=>'rbi','OPS'=>'ops','도루'=>'sb','득점'=>'r','OPS+'=>'opsPlus'];$out=[];foreach($labels as $label=>$k)$out[]=[$label,$s[$k]??null];return $out;
}
function profileHistoricalOverview(PDO $db,array $player,string $season='regular',?int $year=null): ?array {
    $pitcher=str_contains((string)$player['Pos'],'투수');$rows=profileHistoricalRows($db,(string)$player['PlayerId'],$pitcher,$season,$year);if(!$rows)return null;
    $career=$year===null&&(string)$player['IsKbodle']==='0';$latest=(int)max(array_column($rows,'year'));if(!$career)$rows=array_values(array_filter($rows,static fn($r)=>(int)$r['year']===$latest));
    $summary=profileSeasonStats($rows,$pitcher,$pitcher?profileHistoricalPitchingContexts($db,$season):profileBattingLeagueContexts($db,[],$season));
    if($career)$summary=profileMergeSeasonStats([$summary],$pitcher);
    return ['year'=>$latest,'career'=>$career,'leagueLevel'=>1,'season'=>$season,'pitcher'=>$pitcher,'stats'=>profileSeasonDisplayStats($summary,$pitcher),'recent'=>[],'games'=>[],'rolling'=>[],'granularity'=>'season','gameRecordsAvailable'=>false];
}
