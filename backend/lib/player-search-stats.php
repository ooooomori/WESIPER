<?php
declare(strict_types=1);
require_once __DIR__.'/player-season-schedule.php';

/** Resolve latest first-team/futures clubs with one round trip per batch. */
function searchPlayerLastTeams(PDO $db,array $ids): array {
    $teams=[];
    foreach (array_chunk(array_values(array_unique(array_map('intval',$ids))),50) as $chunk) {
        $parts=[]; $params=[];
        foreach ($chunk as $id) foreach (['kbo_season_records','kbo_season_pitch_records'] as $table) foreach ([1,2] as $league) {
            // Separate league ranges let the player/date index find the latest
            // row directly, instead of sorting the player's entire career.
            $parts[]="(SELECT player_id,team,game_date,game_id,league_level FROM `$table` WHERE league_level=$league AND player_id=? AND NULLIF(TRIM(team),'') IS NOT NULL ORDER BY game_date DESC,game_id DESC LIMIT 1)";
            $params[]=$id;
        }
        $q=$db->prepare('SELECT player_id,team FROM (SELECT recent.*,ROW_NUMBER() OVER (PARTITION BY player_id ORDER BY game_date DESC,game_id DESC,league_level) rn FROM ('.implode(' UNION ALL ',$parts).') recent) ranked WHERE rn=1');
        $q->execute($params);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $teams[$row['player_id']]=$row['team'];
    }
    return $teams;
}

/** Batch only matching players, never scan all careers for every keystroke. */
function searchPlayerRecordStats(PDO $db, array $ids, bool $useCache=true): array {
    $ids=array_values(array_unique(array_map('intval',$ids)));
    if (!$ids) return [];
    $schedule=profileSchedule();
    $revisionPath=getenv('WESIPER_CANDLE_REVISION_FILE') ?: '/tmp/wesiper-candle-data-revision';
    $revision=is_readable($revisionPath)?trim((string)file_get_contents($revisionPath)):'initial';
    $dir=sys_get_temp_dir().'/wesiper-player-search-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    $cache=[]; $path=null; $createdAt=time();
    if ($useCache && (is_dir($dir)||@mkdir($dir,0700,true))) {
        $scope=$db->query('SELECT DATABASE(),@@hostname')->fetch(PDO::FETCH_NUM);
        $path=$dir.'/v2-'.hash('sha256',json_encode([$scope,getenv('WESIPER_DB_CONFIG'),$schedule])).'.json';
        $saved=is_file($path)?json_decode((string)@file_get_contents($path),true):null;
        if (is_array($saved) && ($saved['revision']??null)===$revision && ($saved['createdAt']??0)>time()-300 && ($saved['createdAt']??0)<=time()) { $cache=$saved['players']??[]; $createdAt=(int)$saved['createdAt']; }
    }
    $missing=array_values(array_filter($ids,static fn($id)=>!isset($cache[$id])));
    if ($missing) {
        $marks=implode(',',array_fill(0,count($missing),'?'));
        $bounds=[];
        foreach ($schedule as $season) {
            [$start,$end]=$season['regular'];
            $bounds[]='game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end);
        }
        $regular='league_level=1 AND ('.implode(' OR ',$bounds).')';
        // LEFT strips row/role suffixes; pitcher batting and pitching count once.
        $detail="SELECT player_id,COUNT(DISTINCT regular_game) games FROM (";
        $parts=[];
        foreach (['kbo_season_records','kbo_season_pitch_records'] as $table) {
            $parts[]="SELECT player_id,LEFT(game_id,13) regular_game FROM `$table` WHERE $regular AND player_id IN ($marks)";
        }
        $q=$db->prepare($detail.implode(' UNION ALL ',$parts).') records GROUP BY player_id');
        $q->execute(array_merge($missing,$missing));
        foreach ($missing as $id) $cache[$id]=['games'=>0,'last_year'=>null,'futures_games'=>0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $cache[$row['player_id']]['games']=(int)$row['games'];
        // Only indexed player/date columns are needed for the all-league year.
        $parts=[];
        foreach (['kbo_season_records','kbo_season_pitch_records'] as $table) {
            $parts[]="SELECT player_id,YEAR(MAX(game_date)) last_year FROM `$table` WHERE league_level IN (1,2) AND player_id IN ($marks) GROUP BY player_id";
        }
        $q=$db->prepare('SELECT player_id,MAX(last_year) last_year FROM ('.implode(' UNION ALL ',$parts).') records GROUP BY player_id');
        $q->execute(array_merge($missing,$missing));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $cache[$row['player_id']]['last_year']=$row['last_year']!==null?(int)$row['last_year']:null;
        $parts=[];
        foreach (['kbo_player_season_batting_totals','kbo_player_season_pitching_totals'] as $table) {
            $parts[]="SELECT player_id,year,CASE WHEN league_level=1 AND row_scope='total' AND series_id=0 AND year BETWEEN 1982 AND 2000 THEN games ELSE 0 END games FROM `$table` WHERE player_id IN ($marks) AND games>0";
        }
        // Annual total rows already include transfers; use the larger batting or
        // pitching total so historical pitchers' batting does not double-count.
        $q=$db->prepare('SELECT player_id,SUM(games) games,MAX(year) last_year FROM (SELECT player_id,year,MAX(games) games FROM ('.implode(' UNION ALL ',$parts).') totals GROUP BY player_id,year) years GROUP BY player_id');
        $q->execute(array_merge($missing,$missing));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id=$row['player_id']; $cache[$id]['games']+=(int)$row['games'];
            $cache[$id]['last_year']=max($cache[$id]['last_year']??0,(int)$row['last_year']);
        }
        // Futures counts are needed only for players with no first-team games.
        // Keep the covering-index query limited to those matching players.
        $futuresIds=array_values(array_filter($missing,static fn($id)=>$cache[$id]['games']===0));
        if ($futuresIds) {
            $futuresMarks=implode(',',array_fill(0,count($futuresIds),'?'));
            $parts=[];
            foreach (['kbo_season_records','kbo_season_pitch_records'] as $table) {
                $parts[]="SELECT player_id,LEFT(game_id,13) game FROM `$table` WHERE league_level=2 AND player_id IN ($futuresMarks)";
            }
            $q=$db->prepare('SELECT player_id,COUNT(DISTINCT game) games FROM ('.implode(' UNION ALL ',$parts).') records GROUP BY player_id');
            $q->execute(array_merge($futuresIds,$futuresIds));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $cache[$row['player_id']]['futures_games']=(int)$row['games'];
        }
        if ($path!==null) {
            $currentRevision=is_readable($revisionPath)?trim((string)file_get_contents($revisionPath)):'initial';
            if ($revision===$currentRevision) {
                $tmp=tempnam($dir,'search-');
                if ($tmp!==false) {
                    try { if (@file_put_contents($tmp,json_encode(['revision'=>$revision,'createdAt'=>$createdAt,'players'=>$cache],JSON_THROW_ON_ERROR))!==false) @rename($tmp,$path); }
                    finally { if (is_file($tmp)) unlink($tmp); }
                }
            }
        }
    }
    return array_intersect_key($cache,array_flip($ids));
}
