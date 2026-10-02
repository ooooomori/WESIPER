<?php
declare(strict_types=1);

/** Season-wide league denominators, including seasons without kbo_league_records. */
function profileBattingLeagueContexts(PDO $db,array $schedule,string $season='regular',int $level=1): array {
    require_once __DIR__.'/player-rankings.php';
    $dir=sys_get_temp_dir().'/wesiper-profile-batting-league-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Batting league cache unavailable');
    $path=$dir.'/v1-'.hash('sha256',json_encode([$schedule,$season,$level],JSON_THROW_ON_ERROR)).'.json';
    $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Batting league cache lock unavailable');
    try {
        $revision=profileRankingRevision();
        if(is_file($path)){$cached=json_decode((string)file_get_contents($path),true);if(is_array($cached)&&($cached['revision']??null)===$revision&&is_array($cached['years']??null))return $cached['years'];}
        $years=[];$missing=[];
        $q=$db->prepare('SELECT cum_ab,cum_h,cum_ob,cum_sf,cum_tb FROM kbo_league_records WHERE year=? AND game_date BETWEEN ? AND ? ORDER BY game_date DESC LIMIT 1');
        foreach($schedule as $year=>$ranges){
            [$start,$end]=$level===2?[$year.'-01-01',$year.'-12-31']:($ranges[$season]??['','']);if(!$start||!$end)continue;
            $row=false;if($level===1&&$season==='regular'){$q->execute([$year,$start,$end]);$row=$q->fetch(PDO::FETCH_ASSOC);}
            if($row&&$row['cum_ab']>0)$years[$year]=array_map('intval',$row);
            else $missing[]='(game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end).')';
        }
        // Aggregate each PA exactly once. Never sum cumulative snapshots or team-filtered totals.
        if($missing){
            $allstar=$level===2?" AND NOT EXISTS (SELECT 1 FROM kbo_schedule s WHERE s.league_level=2 AND s.game_code=CONVERT(LEFT(kbo_season_records.game_id,13) USING utf8mb4) COLLATE utf8mb4_general_ci AND s.is_allstar=1)":'';
            $q=$db->query('SELECT YEAR(game_date) year,pa_result,COUNT(*) n FROM kbo_season_records WHERE league_level='.$level.' AND ('.implode(' OR ',$missing).')'.profileNotTiebreakerSql().$allstar.' GROUP BY YEAR(game_date),pa_result');
            while($r=$q->fetch(PDO::FETCH_ASSOC)){
                $year=(int)$r['year'];$years[$year]??=array_fill_keys(['cum_ab','cum_h','cum_ob','cum_sf','cum_tb'],0);$e=profileBatEvent($r);$n=(int)$r['n'];
                foreach(['cum_ab'=>'ab','cum_h'=>'h','cum_sf'=>'sf','cum_tb'=>'tb'] as $k=>$f)$years[$year][$k]+=$e[$f]*$n;
                $years[$year]['cum_ob']+=($e['bb']+$e['hbp'])*$n;
            }
        }
        $ids=$level===1?match($season){'regular'=>[0],'preseason'=>[1],'postseason'=>[3,5,7],default=>[]}:[];
        if($ids){
            $q=$db->query("SELECT year,series_id,ab,h,doubles,triples,hr,bb,hbp,sf FROM kbo_player_season_batting_totals WHERE league_level=1 AND row_scope='total' AND year BETWEEN 1982 AND 2000 AND series_id IN (".implode(',',$ids).')');
            $historical=[];$unknown=[];
            while($r=$q->fetch(PDO::FETCH_ASSOC)){
                $year=(int)$r['year'];$historical[$year]??=array_fill_keys(['cum_ab','cum_h','cum_ob','cum_sf','cum_tb'],0);
                $sf=$season==='regular'&&$year<=1985?0:$r['sf'];
                if($sf===null||!isset($r['ab'],$r['h'],$r['doubles'],$r['triples'],$r['hr'],$r['bb'],$r['hbp'])){$unknown[$year]=true;continue;}
                $historical[$year]['cum_ab']+=(int)$r['ab'];$historical[$year]['cum_h']+=(int)$r['h'];
                $historical[$year]['cum_ob']+=(int)$r['bb']+(int)$r['hbp'];$historical[$year]['cum_sf']+=(int)$sf;
                $historical[$year]['cum_tb']+=(int)$r['h']+(int)$r['doubles']+2*(int)$r['triples']+3*(int)$r['hr'];
            }
            foreach($historical as $year=>$totals)if(!isset($unknown[$year]))$years[$year]=$totals;
        }
        $tmp=tempnam($dir,'batting-');if($tmp===false)throw new RuntimeException('Batting league cache write unavailable');
        try{if(file_put_contents($tmp,json_encode(['revision'=>$revision,'years'=>$years],JSON_THROW_ON_ERROR))===false||!rename($tmp,$path))throw new RuntimeException('Batting league cache write failed');}
        finally{if(is_file($tmp))unlink($tmp);}
        return $years;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function profileBattingLeagueTotals(array $years): ?array {
    if(!$years)return null;$s=array_fill_keys(['cum_ab','cum_h','cum_ob','cum_sf','cum_tb'],0);
    foreach($years as $r){if(!is_array($r))return null;foreach($s as $k=>$v){if(!isset($r[$k]))return null;$s[$k]+=$r[$k];}}
    return $s;
}

function profileSeasonOpsPlus(array $s): ?float {
    $l=profileBattingLeagueTotals($s['opsLeagueYears']??[]);
    $sf=$s['obpSf']??($s['sf']??null);
    if(!$l||$sf===null||!isset($s['ab'],$s['h'],$s['bb'],$s['hbp'],$s['tb'])||$s['ab']<=0)return null;
    $den=$s['ab']+$s['bb']+$s['hbp']+$sf;$ld=$l['cum_ab']+$l['cum_ob']+$l['cum_sf'];
    if($den<=0||$ld<=0||$l['cum_ab']<=0||$l['cum_h']+$l['cum_ob']<=0||$l['cum_tb']<=0)return null;
    return round(100*(($s['h']+$s['bb']+$s['hbp'])/$den/(($l['cum_h']+$l['cum_ob'])/$ld)+($s['tb']/$s['ab'])/($l['cum_tb']/$l['cum_ab'])-1));
}
