<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';

function profileFormatFieldingRecords(array $rows): array {
    $groups=[];$careerOuts=0;$inningsKnown=true;
    foreach($rows as $row){
        $year=(int)$row['p_year'];$team=trim((string)($row['p_team']??''));$key=$year.'|'.$team;
        $groups[$key]??=['year'=>$year,'team'=>$team,'positions'=>[]];
        $stats=['games'=>null,'starts'=>null,'position'=>trim((string)($row['p_pos']??''))?:null,'innings'=>null,'fieldingPct'=>null,'caughtStealingPct'=>null];
        foreach(['G'=>'games','GS'=>'starts'] as $source=>$target){$value=trim((string)($row[$source]??''));if(preg_match('/^\d+$/',$value))$stats[$target]=(int)$value;}
        $value=trim((string)($row['IP']??''));
        if($value==='')$inningsKnown=false;else {try{$outs=profileInningOuts($value);$stats['innings']=profileInningText($outs);$careerOuts+=$outs;}catch(RuntimeException $e){$inningsKnown=false;}}
        foreach(['FPCT'=>['fieldingPct',3],'CS%'=>['caughtStealingPct',1]] as $source=>[$target,$precision]){$value=trim((string)($row[$source]??''));if($value!==''&&is_numeric($value))$stats[$target]=number_format((float)$value,$precision,'.','');}
        $groups[$key]['positions'][]=$stats;
    }
    // Position game counts overlap, and rate denominators are not stored.
    $career=['games'=>null,'starts'=>null,'position'=>null,'innings'=>$rows&&$inningsKnown?profileInningText($careerOuts):null,'fieldingPct'=>null,'caughtStealingPct'=>null];
    return profileFieldingCareerPositions(['rows'=>array_values($groups),'career'=>$career]);
}

function profileFieldingCareerPositions(array $fielding): array {
    $totals=[];
    foreach($fielding['rows'] as $row)foreach($row['positions'] as $stats){
        $position=$stats['position']??'';
        $totals[$position]??=['position'=>$position,'games'=>0,'starts'=>0,'outs'=>0,'fieldingPct'=>null,'caughtStealingPct'=>null];
        foreach(['games','starts'] as $key)$totals[$position][$key]=$totals[$position][$key]===null||$stats[$key]===null?null:$totals[$position][$key]+$stats[$key];
        $totals[$position]['outs']=$totals[$position]['outs']===null||$stats['innings']===null?null:$totals[$position]['outs']+profileInningOuts($stats['innings']);
    }
    foreach($totals as &$stats){$stats['innings']=$stats['outs']===null?null:profileInningText($stats['outs']);unset($stats['outs']);}unset($stats);
    $fielding['careerPositions']=array_values($totals);
    return $fielding;
}

function profileFieldingTeamIdentity(string $team): string {
    return match($team){'우리','우리히어로즈','서울히어로즈'=>'키움',default=>profileTeam($team)??$team};
}

function profileAddDesignatedHitterRecords(array $fielding, array $events, array $schedule): array {
    $games=[];
    foreach($events as $event){
        $date=(string)$event['game_date'];$year=(int)substr($date,0,4);
        if($year<2001||$year>2025)continue;
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
        $stats=['games'=>count($group['games']),'starts'=>$known?$starts:null,'position'=>'지명타자','innings'=>null,'fieldingPct'=>null,'caughtStealingPct'=>null];
        $existing=null;foreach($fielding['rows'][$index]['positions'] as $i=>$position)if(in_array($position['position'],['지명타자','지명','DH','D'],true)){$existing=$i;break;}
        if($existing===null)$fielding['rows'][$index]['positions'][]=$stats;else $fielding['rows'][$index]['positions'][$existing]=$stats;
    }
    usort($fielding['rows'],static fn($a,$b)=>($a['year']<=>$b['year'])?:strcmp($a['team'],$b['team']));
    return profileFieldingCareerPositions($fielding);
}

function profileFieldingRecords(PDO $db, string $pid, array $schedule): array {
    $query=$db->prepare('SELECT p_year,p_team,p_pos,G,GS,IP,FPCT,`CS%` FROM kbo_fielding_2001_2025 WHERE p_no=? ORDER BY p_year,p_team,PK');
    $query->execute([$pid]);
    $fielding=profileFormatFieldingRecords($query->fetchAll(PDO::FETCH_ASSOC));
    $bounds=[];foreach($schedule as $year=>$season){if((int)$year<2001||(int)$year>2025)continue;[$start,$end]=$season['regular'];if($start&&$end)$bounds[]='(game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end).')';}
    if(!$bounds)return $fielding;
    $query=$db->prepare("SELECT game_id,game_date,team,pos,is_gs FROM kbo_season_records WHERE league_level=1 AND player_id=? AND (".implode(' OR ',$bounds).") AND (pos LIKE '%지%' OR pos LIKE '%D%') ORDER BY game_date,game_id,PK");
    $query->execute([$pid]);
    return profileAddDesignatedHitterRecords($fielding,$query->fetchAll(PDO::FETCH_ASSOC),$schedule);
}
