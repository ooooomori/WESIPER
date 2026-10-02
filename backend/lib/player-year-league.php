<?php
declare(strict_types=1);
require_once __DIR__.'/player-year-metrics.php';
require_once __DIR__.'/player-rankings.php';

function profileLeaguePitchingContexts(PDO $db, array $schedule, int $leagueLevel=1): array {
    $dir=sys_get_temp_dir().'/wesiper-profile-league-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('League context cache unavailable');
    $path=$dir.'/v2-'.hash('sha256',$leagueLevel.'|'.json_encode($schedule,JSON_THROW_ON_ERROR)).'.json';
    $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('League context lock unavailable');
    try {
        $revision=profileRankingRevision();
        if(is_file($path)){$cached=json_decode((string)file_get_contents($path),true);if(is_array($cached)&&($cached['revision']??null)===$revision&&is_array($cached['years']??null))return $cached['years'];}
        $bounds=[];foreach($schedule as $season){[$start,$end]=$season['regular'];if($start&&$end)$bounds[]='(game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end).')';}
        $filter='('.implode(' OR ',$bounds).')';$years=[];
        if(!$bounds)return [];
        $allstar=static fn($table)=>$leagueLevel===2?" AND NOT EXISTS (SELECT 1 FROM kbo_schedule s WHERE s.league_level=2 AND s.game_code=CONVERT(LEFT(`$table`.game_id,13) USING utf8mb4) COLLATE utf8mb4_general_ci AND s.is_allstar=1)":'';
        $query=$db->query('SELECT YEAR(game_date) AS year,inning,er FROM kbo_season_pitch_records WHERE league_level='.$leagueLevel.' AND '.$filter.profileNotTiebreakerSql().$allstar('kbo_season_pitch_records'));
        while($row=$query->fetch(PDO::FETCH_ASSOC)){
            $year=(int)$row['year'];$years[$year]??=['outs'=>0,'er'=>0,'knownEr'=>true,'hr'=>0,'bb'=>0,'hbp'=>0,'so'=>0,'pa'=>0,'ab'=>0,'h'=>0,'sf'=>0,'tb'=>0];
            $years[$year]['outs']+=profileInningOuts($row['inning']);
            if(!isset($row['er']))$years[$year]['knownEr']=false;else $years[$year]['er']+=(int)$row['er'];
        }
        // One aggregate scan for all seasons; cache until the crawler revision changes.
        $query=$db->query('SELECT YEAR(game_date) AS year,pa_result,COUNT(*) AS count FROM kbo_season_records WHERE league_level='.$leagueLevel.' AND '.$filter.profileNotTiebreakerSql().$allstar('kbo_season_records').' GROUP BY YEAR(game_date),pa_result');
        while($row=$query->fetch(PDO::FETCH_ASSOC)){
            $year=(int)$row['year'];if(!isset($years[$year]))continue;$event=profileAdvancedBatEvent($row);
            foreach(['hr','bb','hbp','so','pa','ab','h','sf','tb'] as $key)$years[$year][$key]+=$event[$key]*(int)$row['count'];
        }
        $result=[];
        foreach($years as $year=>$totals){
            $outs=$totals['outs'];$era=$outs>0&&$totals['knownEr']?$totals['er']*27/$outs:null;
            $constant=$era!==null&&$totals['pa']>0?$era-(13*$totals['hr']+3*($totals['bb']+$totals['hbp'])-2*$totals['so'])*3/$outs:null;
            $result[$year]=['era'=>$era,'fipConstant'=>$constant,'cum_ab'=>$totals['ab'],'cum_h'=>$totals['h'],'cum_ob'=>$totals['bb']+$totals['hbp'],'cum_sf'=>$totals['sf'],'cum_tb'=>$totals['tb']];
        }
        $tmp=tempnam($dir,'league-');if($tmp===false)throw new RuntimeException('League context cache write unavailable');
        try {if(file_put_contents($tmp,json_encode(['revision'=>$revision,'years'=>$result],JSON_THROW_ON_ERROR))===false||!rename($tmp,$path))throw new RuntimeException('League context cache write failed');}
        finally {if(is_file($tmp))unlink($tmp);}
        return $result;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function profilePitcherGameContexts(PDO $db, array $rows, int $leagueLevel=1): array {
    $ids=array_values(array_unique(array_column($rows,'game_id')));if(!$ids)return [];
    $q=$db->prepare('SELECT game_id,team,COUNT(*) AS pitchers,IF(COUNT(`order`)=COUNT(*),MAX(`order`),NULL) AS last_order FROM kbo_season_pitch_records WHERE league_level='.$leagueLevel.' AND game_id IN ('.implode(',',array_fill(0,count($ids),'?')).') GROUP BY game_id,team');
    $q->execute($ids);$result=[];while($row=$q->fetch(PDO::FETCH_ASSOC))$result[$row['game_id'].'|'.profileTeam($row['team'])]=$row;
    $codes=array_values(array_unique(array_map(static fn($id)=>substr($id,0,13),$ids)));
    $q=$db->prepare('SELECT game_code,away_score,home_score FROM kbo_schedule WHERE league_level='.$leagueLevel.' AND game_code IN ('.implode(',',array_fill(0,count($codes),'?')).')');$q->execute($codes);
    $completed=[];while($row=$q->fetch(PDO::FETCH_ASSOC))$completed[$row['game_code']]=isset($row['away_score'],$row['home_score']);
    foreach($result as &$meta){$code=substr($meta['game_id'],0,13);if(array_key_exists($code,$completed))$meta['completed']=$completed[$code];}unset($meta);
    return $result;
}
