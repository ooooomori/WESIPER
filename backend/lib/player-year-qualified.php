<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';

/*
 * 연도별 기록 행마다 규정 타석(팀 경기×3.1)·규정 이닝(팀 경기×1) 충족 여부(qualified)를 붙인다.
 * 비율 기록(타율·ERA 등)의 커리어 하이는 규정을 채운 시즌끼리만 비교하기 위해 쓴다.
 * 팀 경기 수: 2001년 이후는 일정표의 완료 경기, 1982~2000년은 시즌 합계 자료에서 그 팀 선수의 최다 출장 경기.
 * 한 해 여러 팀이면 마지막 팀 경기 수를 기준으로 본다(순위 계산과 같은 방식).
 */
function profileTeamGamesByYear(PDO $db, array $schedule): array {
    static $memo=null;if($memo!==null)return $memo;
    require_once __DIR__.'/player-rankings.php';
    // 크롤러 갱신(revision)마다 한 번만 집계해 파일에 둔다.
    $dir=sys_get_temp_dir().'/wesiper-profile-team-games-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir))@mkdir($dir,0700,true);
    $path=$dir.'/v1-'.hash('sha256',json_encode($schedule)).'.json';$revision=profileRankingRevision();
    if(is_file($path)){$cached=json_decode((string)file_get_contents($path),true);if(is_array($cached)&&($cached['revision']??null)===$revision&&is_array($cached['games']??null))return $memo=$cached['games'];}
    $games=[];$bounds=[];
    foreach($schedule as $season){[$start,$end]=$season['regular']??['',''];if($start&&$end)$bounds[]='(game_date BETWEEN '.$db->quote($start).' AND '.$db->quote($end).')';}
    if($bounds){
        $q=$db->query('SELECT YEAR(game_date) y,away_team,home_team,COUNT(*) n FROM kbo_schedule WHERE league_level=1 AND away_score IS NOT NULL AND home_score IS NOT NULL AND ('.implode(' OR ',$bounds).') GROUP BY y,away_team,home_team');
        while($row=$q->fetch(PDO::FETCH_ASSOC))foreach([$row['away_team'],$row['home_team']] as $team){$team=(string)profileTeam($team);$games[(int)$row['y']][$team]=($games[(int)$row['y']][$team]??0)+(int)$row['n'];}
    }
    try{
        $h=$db->query("SELECT year,team_name,MAX(games) g FROM kbo_player_season_batting_totals WHERE league_level=1 AND row_scope='total' AND year BETWEEN 1982 AND 2000 AND series_id=0 GROUP BY year,team_name");
        while($row=$h->fetch(PDO::FETCH_ASSOC)){$year=(int)$row['year'];if(!isset($games[$year][$row['team_name']]))$games[$year][(string)$row['team_name']]=(int)$row['g'];}
    }catch(Throwable $error){error_log('Historical team games unavailable: '.$error->getMessage());}
    $tmp=@tempnam($dir,'team-games-');
    if($tmp!==false){@file_put_contents($tmp,json_encode(['revision'=>$revision,'games'=>$games]));@rename($tmp,$path);if(is_file($tmp))@unlink($tmp);}
    return $memo=$games;
}

function profileAnnotateQualified(PDO $db, array $records, bool $pitcher, array $schedule): array {
    if(empty($records['rows']))return $records;
    $teamGames=profileTeamGamesByYear($db,$schedule);
    foreach($records['rows'] as &$row){
        $year=(int)($row['year']??0);
        $team=!empty($row['teams'])?(string)end($row['teams'])['team']:(string)($row['team']??'');
        $g=$teamGames[$year][profileTeam($team)]??$teamGames[$year][$team]??0;
        $s=$row['stats']??[];
        if($g<=0){$row['qualified']=null;continue;}
        $row['qualified']=$pitcher
            ? profileInningOuts((string)($s['innings']??'0'))>=$g*3
            : (int)($s['pa']??0)>=floor($g*3.1);
    }
    unset($row);
    return $records;
}
