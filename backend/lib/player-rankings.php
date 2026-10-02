<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';
function profileRankingRevision(): string {
    $path=getenv('WESIPER_CANDLE_REVISION_FILE') ?: '/tmp/wesiper-candle-data-revision';
    $revision=is_readable($path)?trim((string)file_get_contents($path)):'';
    return $revision!==''?$revision:'initial';
}
function profileRankingCachedPlayers(array $cached, string $revision): ?array {
    return ($cached['revision']??null)===$revision && is_array($cached['players']??null)?$cached['players']:null;
}
function profileMetricRanks(array $players, array $qualified, array $rateLabels, array $ascending=[]): array {
    $metrics=[];$result=[];
    foreach($players as $id=>$stats) foreach($stats as [$label,$value]) {
        if($value===null || (in_array($label,$rateLabels,true)&&!($qualified[$id]??false))) continue;
        $metrics[$label][$id]=$label==='이닝'?profileInningOuts((string)$value):(float)$value;
    }
    foreach($metrics as $label=>$values) {
        in_array($label,$ascending,true)?asort($values,SORT_NUMERIC):arsort($values,SORT_NUMERIC);
        $last=null;$rank=0;$index=0;
        foreach($values as $id=>$value) { $index++;if($last===null||$last!==$value)$rank=$index;$result[$id][$label]=$rank;$last=$value; }
    }
    return $result;
}
function profileBaseRankings(PDO $db, int $year, array $schedule, bool $career = false): array {
    if(!$career&&$year>=1982&&$year<=2000){
        $result=[];
        foreach([false,true] as $pitcher){$byPlayer=[];foreach(profileHistoricalRows($db,'',$pitcher,'regular',$year) as $row)$byPlayer[$row['player_id']][]=$row;
            $metrics=[];foreach($byPlayer as $id=>$rows){$stats=profileSeasonStats($rows,$pitcher);$metrics[$id]=profileSeasonDisplayStats($stats,$pitcher);if($pitcher)$metrics[$id][]=['경기',$stats['games']??null];}
            // Team games needed for qualification are not present in season totals.
            foreach(profileMetricRanks($metrics,[], $pitcher?['ERA','WHIP','ERA+']:['타율','OPS','OPS+'],$pitcher?['ERA','WHIP']:[]) as $id=>$ranks)$result[$id]=array_merge($result[$id]??[],$ranks);
        }return $result;
    }
    $dir=sys_get_temp_dir().'/wesiper-profile-rankings-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)) throw new RuntimeException('Ranking cache unavailable');
    // Career totals must also refresh when historical season boundaries expand.
    $scheduleKey=hash('sha256',json_encode($schedule,JSON_THROW_ON_ERROR));
    $path=$career?"$dir/v6-career-$scheduleKey.json":"$dir/v2-$year.json";$lock=fopen("$path.lock",'c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Ranking lock unavailable');
    try {
        // The crawler atomically replaces this revision only after its record updates succeed.
        // Keep the previous rankings throughout the crawl, without a clock-based expiry.
        $revision=profileRankingRevision();
        if(is_file($path)) { $cached=json_decode(file_get_contents($path),true);if(is_array($cached)&&($players=profileRankingCachedPlayers($cached,$revision))!==null)return $players; }
        $rankingYear=isset($schedule[$year])?$year:(int)array_key_first($schedule);
        [$start,$end]=$schedule[$rankingYear]['regular'];
        $params=[$start,$end];$filter='game_date BETWEEN ? AND ?';
        if($career){$params=[];$bounds=[];foreach($schedule as $season){[$a,$b]=$season['regular'];if($a&&$b)$bounds[]='(game_date BETWEEN '.$db->quote($a).' AND '.$db->quote($b).')';}$filter='('.implode(' OR ',$bounds).')';}
        $q=$db->prepare('SELECT away_team,home_team FROM kbo_schedule WHERE league_level=1 AND game_date BETWEEN ? AND ? AND away_score IS NOT NULL AND home_score IS NOT NULL');$q->execute([$start,$end]);
        $games=[];while($row=$q->fetch(PDO::FETCH_ASSOC))foreach($row as $team){$team=profileTeam($team);$games[$team]=($games[$team]??0)+1;}
        if($career)$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        $q=$db->prepare('SELECT player_id,pitcher_id,game_id,game_date,team,pa_result,sb,cs,rbi,r FROM kbo_season_records WHERE league_level=1 AND '.$filter.' ORDER BY game_date,PK');$q->execute($params);
        $bat=[];$faces=[];$coverage=[];
        while($row=$q->fetch(PDO::FETCH_ASSOC)) {
            $id=$row['player_id'];$e=profileBatEvent($row);
            if(!isset($bat[$id]))$bat[$id]=['team'=>null,'sum'=>array_fill_keys(array_keys($e),0),'missing'=>[]];
            $bat[$id]['team']=profileTeam($row['team']);
            $bat[$id]['years'][(int)substr($row['game_date'],0,4)]=true;
            foreach($e as $key=>$value) { if($value===null)$bat[$id]['missing'][$key]=true;else $bat[$id]['sum'][$key]+=$value; }
            if($row['pitcher_id']&&$e['pa']) {
                $pid=$row['pitcher_id'];$coverage[$pid][$row['game_id']]=true;
                foreach(['h','bb','so'] as $key)$faces[$pid][$key]=($faces[$pid][$key]??0)+$e[$key];
            }
        }
        $q->closeCursor();if($career)$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
        if($career)foreach(profileHistoricalRows($db,'',false) as $row){
            $id=$row['player_id'];$s=profileSeasonStats([$row],false);
            if(!isset($bat[$id]))$bat[$id]=['team'=>$row['team_name'],'sum'=>array_fill_keys(array_keys(profileBatEvent([])),0),'missing'=>[]];
            $bat[$id]['years'][(int)$row['year']]=true;
            foreach(array_keys($bat[$id]['sum']) as $key){if(!isset($s[$key]))$bat[$id]['missing'][$key]=true;else $bat[$id]['sum'][$key]+=$s[$key];}
        }
        // Use the same league baseline as the profile OPS+ calculation.
        $lq=$db->prepare('SELECT cum_ab,cum_h,cum_ob,cum_sf,cum_tb FROM kbo_league_records WHERE year=? AND game_date BETWEEN ? AND ? ORDER BY game_date DESC LIMIT 1');$lq->execute([$year,$start,$end]);$l=$lq->fetch(PDO::FETCH_ASSOC);
        $den=$l?($l['cum_ab']+$l['cum_ob']+$l['cum_sf']):0;
        $league=['obp'=>$den?($l['cum_h']+$l['cum_ob'])/$den:null,'slg'=>($l['cum_ab']??0)?$l['cum_tb']/$l['cum_ab']:null];
        $leagueYears=[];
        if($career)foreach($schedule as $y=>$season){$lq->execute([$y,...$season['regular']]);$leagueYears[$y]=$lq->fetch(PDO::FETCH_ASSOC);}
        $metrics=[];$qualified=[];
        foreach($bat as $id=>$b) {
            if($career){$totals=['cum_ab'=>0,'cum_h'=>0,'cum_ob'=>0,'cum_sf'=>0,'cum_tb'=>0];foreach(array_keys($b['years']) as $y)if($leagueYears[$y]??null)foreach($totals as $key=>$v)$totals[$key]+=(int)$leagueYears[$y][$key];$ld=$totals['cum_ab']+$totals['cum_ob']+$totals['cum_sf'];$league=['obp'=>$ld?($totals['cum_h']+$totals['cum_ob'])/$ld:null,'slg'=>$totals['cum_ab']?$totals['cum_tb']/$totals['cum_ab']:null];}
            $s=$b['sum'];$ab=$s['ab'];$den=$ab+$s['bb']+$s['hbp']+$s['sf'];$obp=$den&&!isset($b['missing']['sf'])?($s['h']+$s['bb']+$s['hbp'])/$den:null;$slg=$ab?$s['tb']/$ab:null;
            $ops=$obp!==null&&$slg!==null?number_format($obp+$slg,3):null;
            $plus=$obp!==null&&$slg!==null&&$league['obp']&&$league['slg']?round(100*($obp/$league['obp']+$slg/$league['slg']-1)):null;
            if($career&&array_filter(array_keys($b['years']),static fn($y)=>$y<=2000))$plus=null;
            $metrics[$id]=[['타율',$ab?number_format($s['h']/$ab,3):null],['안타',$s['h']],['홈런',$s['hr']],['타점',isset($b['missing']['rbi'])?null:$s['rbi']],['OPS',$ops],['도루',$s['sb']],['득점',isset($b['missing']['r'])?null:$s['r']],['OPS+',$plus]];
            $g=$games[$b['team']]??0;$qualified[$id]=$career?$ab>=3000:($g>0&&$s['pa']>=floor($g*3.1));
        }
        $result=profileMetricRanks($metrics,$qualified,['타율','OPS','OPS+']);
        $q=$db->prepare('SELECT * FROM kbo_season_pitch_records WHERE league_level=1 AND '.$filter.' ORDER BY game_date,id');$q->execute($params);$pitch=[];
        while($row=$q->fetch(PDO::FETCH_ASSOC)) {
            $id=$row['player_id'];if(!isset($pitch[$id]))$pitch[$id]=['team'=>null,'outs'=>0,'er'=>0,'erKnown'=>true,'facedKnown'=>true,'games'=>0,'wins'=>0,'holds'=>0,'saves'=>0];
            $p=&$pitch[$id];$p['team']=profileTeam($row['team']);$p['outs']+=profileInningOuts($row['inning']);$p['er']+=(int)$row['er'];$p['erKnown']=$p['erKnown']&&isset($row['er']);$p['facedKnown']=$p['facedKnown']&&isset($coverage[$id][$row['game_id']]);$p['games']++;
            $p['wins']+=(int)in_array($row['record'],['승','W','승리'],true);$p['holds']+=(int)in_array($row['record'],['홀','H','홀드'],true);$p['saves']+=(int)in_array($row['record'],['세','S','세이브'],true);unset($p);
        }
        $q->closeCursor();
        if($career)foreach(profileHistoricalRows($db,'',true) as $row){
            $id=$row['player_id'];
            if(!isset($pitch[$id]))$pitch[$id]=['team'=>$row['team_name'],'outs'=>0,'er'=>0,'erKnown'=>true,'facedKnown'=>true,'games'=>0,'wins'=>0,'holds'=>0,'saves'=>0];
            $p=&$pitch[$id];$p['outs']+=(int)$row['innings_outs'];$p['er']+=(int)$row['er'];$p['erKnown']=$p['erKnown']&&isset($row['er']);
            foreach(['games','wins','holds','saves'] as $key)$p[$key]+=(int)$row[$key];
            $p['facedKnown']=$p['facedKnown']&&isset($row['h'],$row['bb'],$row['so']);foreach(['h','bb','so'] as $key)$faces[$id][$key]=($faces[$id][$key]??0)+(int)$row[$key];unset($p);
        }
        $metrics=[];$qualified=[];
        foreach($pitch as $id=>$p) {
            $f=$faces[$id]??['so'=>0,'h'=>0,'bb'=>0];$outs=$p['outs'];
            $metrics[$id]=[['ERA',$outs&&$p['erKnown']?number_format($p['er']*27/$outs,2):null],['승리',$p['wins']],['홀드',$p['holds']],['세이브',$p['saves']],['경기',$p['games']],['이닝',profileInningText($outs)],['삼진',$p['facedKnown']?$f['so']:null],['WHIP',$outs&&$p['facedKnown']?number_format(($f['h']+$f['bb'])*3/$outs,2):null]];
            $g=$games[$p['team']]??0;$qualified[$id]=$career?$outs>=3000:($g>0&&$outs>=$g*3);
        }
        foreach(profileMetricRanks($metrics,$qualified,['ERA','WHIP'],['ERA','WHIP']) as $id=>$ranks)$result[$id]=array_merge($result[$id]??[],$ranks);
        $tmp=tempnam($dir,'rank-');file_put_contents($tmp,json_encode(['revision'=>$revision,'players'=>$result],JSON_THROW_ON_ERROR));rename($tmp,$path);return $result;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function profileEraPlusValue(array $yearOuts, ?int $er, array $leagueYears): ?int {
    if($er===null||$er<=0)return null;
    $weighted=0;
    foreach($yearOuts as $year=>$outs){
        if($outs===null)return null;if($outs===0)continue;
        $era=$leagueYears[$year]['era']??null;if($era===null)return null;
        $weighted+=$era*$outs;
    }
    return $weighted>0?(int)round(100*$weighted/($er*27)):null;
}

function profileEraPlusRankings(PDO $db,int $year,array $schedule,bool $career,int $leagueLevel): array {
    require_once __DIR__.'/player-year-league.php';
    $contextSchedule=$schedule;
    if($leagueLevel===2)foreach($contextSchedule as $y=>&$season)$season['regular']=[$y.'-01-01',$y.'-12-31'];
    unset($season);
    $dir=sys_get_temp_dir().'/wesiper-profile-rankings-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('ERA+ cache unavailable');
    $path=$dir.'/era-plus-v1-'.hash('sha256',json_encode([$career?null:$year,$career,$leagueLevel,$contextSchedule],JSON_THROW_ON_ERROR)).'.json';
    $read=static function()use($path){if(!is_file($path))return null;$cached=json_decode((string)file_get_contents($path),true);if(!is_array($cached)||time()-(int)($cached['createdAt']??0)>=300)return null;return profileRankingCachedPlayers($cached,profileRankingRevision());};
    if(($cached=$read())!==null)return $cached;
    $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('ERA+ cache lock unavailable');
    try {
        if(($cached=$read())!==null)return $cached;
        $revision=profileRankingRevision();
        $leagueYears=profileLeaguePitchingContexts($db,$contextSchedule,$leagueLevel);
        if($leagueLevel===1)foreach(profileHistoricalPitchingContexts($db,'regular') as $key=>$context)$leagueYears[(int)explode('|',$key)[0]]=$context;
        [$start,$end]=$leagueLevel===2?[$year.'-01-01',$year.'-12-31']:($schedule[$year]['regular']??[$year.'-01-01',$year.'-12-31']);
        $params=[$start,$end];$filter='game_date BETWEEN ? AND ?';
        if($career){$params=[];$bounds=[];foreach($contextSchedule as $season){[$a,$b]=$season['regular'];if($a&&$b)$bounds[]='(game_date BETWEEN '.$db->quote($a).' AND '.$db->quote($b).')';}$filter=$bounds?'('.implode(' OR ',$bounds).')':'0=1';}
        if($leagueLevel===2)$filter.=" AND NOT EXISTS (SELECT 1 FROM kbo_schedule s WHERE s.league_level=2 AND s.game_code=CONVERT(LEFT(r.game_id,13) USING utf8mb4) COLLATE utf8mb4_general_ci AND s.is_allstar=1)";
        $players=[];
        $collect=static function($row)use(&$players){
            $id=$row['player_id'];$year=isset($row['year'])?(int)$row['year']:(int)substr($row['game_date'],0,4);
            $outs=isset($row['year'])?($row['innings_outs']??null):profileInningOuts($row['inning']);
            $players[$id]??=['outs'=>0,'er'=>0,'known'=>true,'yearOuts'=>[],'team'=>null];$p=&$players[$id];
            $p['outs']+=(int)$outs;$p['er']+=(int)($row['er']??0);$p['known']=$p['known']&&$outs!==null&&isset($row['er']);
            $p['yearOuts'][$year]=($p['yearOuts'][$year]??0)+(int)$outs;$p['team']=profileTeam($row['team']??$row['team_name']??null);unset($p);
        };
        $q=$db->prepare('SELECT player_id,game_date,team,inning,er FROM kbo_season_pitch_records r WHERE league_level='.$leagueLevel.' AND '.$filter.' ORDER BY game_date,id');$q->execute($params);while($row=$q->fetch(PDO::FETCH_ASSOC))$collect($row);$q->closeCursor();
        if($leagueLevel===1&&($career||$year<=2000))foreach(profileHistoricalRows($db,'',true,'regular',$career?null:$year) as $row)$collect($row);
        $q=$db->prepare('SELECT away_team,home_team FROM kbo_schedule WHERE league_level='.$leagueLevel.' AND game_date BETWEEN ? AND ? AND away_score IS NOT NULL AND home_score IS NOT NULL'.($leagueLevel===2?' AND (is_allstar IS NULL OR is_allstar=0)':''));$q->execute([$start,$end]);$games=[];while($row=$q->fetch(PDO::FETCH_ASSOC))foreach($row as $team){$team=profileTeam($team);$games[$team]=($games[$team]??0)+1;}
        $metrics=[];$qualified=[];
        foreach($players as $id=>$p){$metrics[$id]=[['ERA+',profileEraPlusValue($p['yearOuts'],$p['known']?$p['er']:null,$leagueYears)]];$g=$games[$p['team']]??0;$qualified[$id]=$career?$p['outs']>=3000:($g>0&&$p['outs']>=$g*3);}
        $result=profileMetricRanks($metrics,$qualified,['ERA+']);
        if($revision===profileRankingRevision()){$tmp=tempnam($dir,'era-rank-');if($tmp===false)throw new RuntimeException('ERA+ cache write unavailable');try{file_put_contents($tmp,json_encode(['revision'=>$revision,'createdAt'=>time(),'players'=>$result],JSON_THROW_ON_ERROR));rename($tmp,$path);}finally{if(is_file($tmp))unlink($tmp);}}
        return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function profileRankings(PDO $db,int $year,array $schedule,bool $career=false,int $leagueLevel=1): array {
    if(!in_array($leagueLevel,[1,2],true))throw new InvalidArgumentException('Invalid ranking league');
    $result=$leagueLevel===1?profileBaseRankings($db,$year,$schedule,$career):[];
    foreach(profileEraPlusRankings($db,$year,$schedule,$career,$leagueLevel) as $id=>$ranks)$result[$id]=array_merge($result[$id]??[],$ranks);
    return $result;
}
