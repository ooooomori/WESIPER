<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';

// 공식 수비 기록은 2001년부터 있다(kbo_fielding_records, 매일 02:10 크롤러가 올해 시즌을 갱신).
const PROFILE_FIELDING_FIRST_YEAR = 2001;
// 합산 가능한 기록: 응답 키 => kbo_fielding_records 컬럼
const PROFILE_FIELDING_COUNTS = ['errors'=>'errors','pickoffs'=>'pickoffs','putouts'=>'putouts','assists'=>'assists','doublePlays'=>'double_plays',
    'passedBalls'=>'passed_balls','stolenBases'=>'stolen_bases','caughtStealing'=>'caught_stealing'];

function profileFieldingEmptyStats(?string $position): array {
    return ['games'=>null,'starts'=>null,'position'=>$position,'innings'=>null]+array_fill_keys(array_keys(PROFILE_FIELDING_COUNTS),null)+['fieldingPct'=>null,'caughtStealingPct'=>null];
}

// 포일·도루 허용/저지는 포수만, 견제사는 투수·포수만 기록된다. 해당 없는 포지션은 0이 아니라 빈칸으로 둔다.
function profileFieldingApplies(string $key, ?string $position): bool {
    if(in_array($key,['passedBalls','stolenBases','caughtStealing','caughtStealingPct'],true))return $position==='포수';
    if($key==='pickoffs')return in_array($position,['투수','포수'],true);
    return true;
}

function profileFieldingRates(array $stats): array {
    $chances=$stats['putouts']===null||$stats['assists']===null||$stats['errors']===null?0:$stats['putouts']+$stats['assists']+$stats['errors'];
    $stats['fieldingPct']=$chances>0?number_format(($stats['putouts']+$stats['assists'])/$chances,3,'.',''):null;
    $attempts=($stats['stolenBases']??0)+($stats['caughtStealing']??0);
    $stats['caughtStealingPct']=$stats['position']==='포수'&&$attempts>0?number_format($stats['caughtStealing']*100/$attempts,1,'.',''):null;
    return $stats;
}

function profileFormatFieldingRecords(array $rows): array {
    $groups=[];$careerOuts=0;
    foreach($rows as $row){
        $year=(int)$row['year'];$team=trim((string)($row['team']??''));$key=$year.'|'.$team;
        $groups[$key]??=['year'=>$year,'team'=>$team,'positions'=>[]];
        $position=trim((string)($row['position']??''))?:null;
        $stats=profileFieldingEmptyStats($position);
        $stats['games']=(int)$row['games'];$stats['starts']=(int)$row['starts'];
        $outs=(int)$row['innings_outs'];$stats['innings']=profileInningText($outs);$careerOuts+=$outs;
        foreach(PROFILE_FIELDING_COUNTS as $target=>$source)if(profileFieldingApplies($target,$position))$stats[$target]=(int)$row[$source];
        foreach(['fielding_pct'=>['fieldingPct',3],'caught_stealing_pct'=>['caughtStealingPct',1]] as $source=>[$target,$precision]){
            $value=$row[$source]??null;if($value!==null&&$value!==''&&is_numeric($value)&&profileFieldingApplies($target,$position))$stats[$target]=number_format((float)$value,$precision,'.','');
        }
        $groups[$key]['positions'][]=$stats;
    }
    // Position game counts overlap, so only innings are totalled across positions.
    $career=profileFieldingEmptyStats(null);$career['innings']=$rows?profileInningText($careerOuts):null;
    return profileFieldingCareerPositions(['rows'=>array_values($groups),'career'=>$career]);
}

function profileFieldingCareerPositions(array $fielding): array {
    $totals=[];
    foreach($fielding['rows'] as $row)foreach($row['positions'] as $stats){
        $position=$stats['position']??'';
        if(!isset($totals[$position])){$totals[$position]=profileFieldingEmptyStats($position);$totals[$position]['outs']=0;foreach(['games','starts',...array_keys(PROFILE_FIELDING_COUNTS)] as $key)$totals[$position][$key]=0;}
        // 한 시즌이라도 값이 없으면(지명타자의 선발 수 등) 통산도 비운다.
        foreach(['games','starts',...array_keys(PROFILE_FIELDING_COUNTS)] as $key)$totals[$position][$key]=$totals[$position][$key]===null||$stats[$key]===null?null:$totals[$position][$key]+$stats[$key];
        $totals[$position]['outs']=$totals[$position]['outs']===null||$stats['innings']===null?null:$totals[$position]['outs']+profileInningOuts($stats['innings']);
    }
    foreach($totals as &$stats){$stats['innings']=$stats['outs']===null?null:profileInningText($stats['outs']);unset($stats['outs']);$stats=profileFieldingRates($stats);}unset($stats);
    $fielding['careerPositions']=array_values($totals);
    return $fielding;
}

function profileFieldingTeamIdentity(string $team): string {
    return match($team){'우리','우리히어로즈','서울히어로즈','히어로즈','넥센'=>'키움',default=>profileTeam($team)??$team};
}

function profileAddDesignatedHitterRecords(array $fielding, array $events, array $schedule): array {
    $games=[];
    foreach($events as $event){
        $date=(string)$event['game_date'];$year=(int)substr($date,0,4);
        if($year<PROFILE_FIELDING_FIRST_YEAR)continue;
        [$start,$end]=$schedule[$year]['regular']??['',''];if(!$start||!$end||$date<$start||$date>$end)continue;
        $pos=trim((string)($event['pos']??''));
        if(!str_contains($pos,'지')&&!str_contains(strtoupper($pos),'D'))continue;
        $team=trim((string)($event['team']??''));$key=$year.'|'.profileFieldingTeamIdentity($team);$id=$event['game_id'];
        $games[$key]??=['year'=>$year,'team'=>$team,'games'=>[]];
        $games[$key]['games'][$id]??=['known'=>false,'starter'=>false];
        if(isset($event['is_gs'])){
            $games[$key]['games'][$id]['known']=true;
            $first=preg_replace('/^[타주교수]+/u','',$pos);
            if((int)$event['is_gs']===1&&(str_starts_with($first,'지')||str_starts_with(strtoupper($first),'D')))$games[$key]['games'][$id]['starter']=true;
        }
    }
    foreach($games as $group){
        $index=null;
        foreach($fielding['rows'] as $i=>$row)if($row['year']===$group['year']&&profileFieldingTeamIdentity($row['team'])===profileFieldingTeamIdentity($group['team'])){$index=$i;break;}
        if($index===null){$index=count($fielding['rows']);$fielding['rows'][]=['year'=>$group['year'],'team'=>$group['team'],'positions'=>[]];}
        $starts=0;$known=true;foreach($group['games'] as $game){$known=$known&&$game['known'];$starts+=(int)$game['starter'];}
        $stats=profileFieldingEmptyStats('지명타자');$stats['games']=count($group['games']);$stats['starts']=$known?$starts:null;
        $existing=null;foreach($fielding['rows'][$index]['positions'] as $i=>$position)if(in_array($position['position'],['지명타자','지명','DH','D'],true)){$existing=$i;break;}
        if($existing===null)$fielding['rows'][$index]['positions'][]=$stats;else $fielding['rows'][$index]['positions'][$existing]=$stats;
    }
    usort($fielding['rows'],static fn($a,$b)=>($a['year']<=>$b['year'])?:strcmp($a['team'],$b['team']));
    return profileFieldingCareerPositions($fielding);
}

function profileFieldingRecords(PDO $db, string $pid, array $schedule): array {
    // 지명타자 집계가 선수별 수천 행을 읽으므로 파일에 둔다. 이 선수의 수비 기록이나 1군 경기 기록이
    // 새로 들어왔을 때(또는 PROFILE_HISTORY_VERSION이 바뀔 때)만 다시 계산한다.
    $dir=sys_get_temp_dir().'/wesiper-profile-fielding-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir))@mkdir($dir,0700,true);
    $path=$dir.'/v2-'.hash('sha256',$pid.'|'.json_encode($schedule)).'.json';
    $marks=$db->prepare("SELECT (SELECT CONCAT(COUNT(*),'@',COALESCE(MAX(updated_at),'')) FROM kbo_fielding_records WHERE player_id=?),
        (SELECT MAX(game_date) FROM kbo_season_records WHERE league_level=1 AND player_id=?)");
    $marks->execute([$pid,$pid]);
    $revision='history-'.PROFILE_HISTORY_VERSION.'|'.implode('|',array_map('strval',$marks->fetch(PDO::FETCH_NUM)));
    if(is_file($path)){$cached=json_decode((string)file_get_contents($path),true);if(is_array($cached)&&($cached['revision']??null)===$revision&&is_array($cached['fielding']??null))return $cached['fielding'];}
    $fielding=profileComputeFieldingRecords($db,$pid,$schedule);
    $tmp=@tempnam($dir,'fielding-');
    if($tmp!==false){@file_put_contents($tmp,json_encode(['revision'=>$revision,'fielding'=>$fielding]));@rename($tmp,$path);if(is_file($tmp))@unlink($tmp);}
    return $fielding;
}

function profileComputeFieldingRecords(PDO $db, string $pid, array $schedule): array {
    // 같은 해·팀 안에서는 많이 뛴 포지션이 먼저 온다.
    $query=$db->prepare('SELECT year,team,position,games,starts,innings_outs,errors,pickoffs,putouts,assists,double_plays,fielding_pct,passed_balls,stolen_bases,caught_stealing,caught_stealing_pct
        FROM kbo_fielding_records WHERE player_id=? ORDER BY year,team,innings_outs DESC,games DESC,id');
    $query->execute([$pid]);
    $fielding=profileFormatFieldingRecords($query->fetchAll(PDO::FETCH_ASSOC));
    $bounds=[];foreach($schedule as $year=>$season){if((int)$year<PROFILE_FIELDING_FIRST_YEAR)continue;[$start,$end]=$season['regular'];if($start&&$end)$bounds[]='(game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end).')';}
    if(!$bounds)return $fielding;
    $query=$db->prepare("SELECT game_id,game_date,team,pos,is_gs FROM kbo_season_records WHERE league_level=1 AND player_id=? AND (".implode(' OR ',$bounds).")".profileNotTiebreakerSql()." AND (pos LIKE '%지%' OR pos LIKE '%D%') ORDER BY game_date,game_id,PK");
    $query->execute([$pid]);
    return profileAddDesignatedHitterRecords($fielding,$query->fetchAll(PDO::FETCH_ASSOC),$schedule);
}
