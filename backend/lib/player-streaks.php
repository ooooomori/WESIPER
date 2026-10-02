<?php
declare(strict_types=1);
require_once __DIR__ . '/player-records.php';

/** First-team regular-season rows, newest game first, including earlier seasons. */
function profileStreakGames(array $rows): array {
    $games = [];
    foreach ($rows as $row) {
        $key = $row['game_date'] . '|' . $row['game_id'];
        $games[$key] ??= ['pa'=>0, 'h'=>0, 'bb'=>0, 'hbp'=>0, 'hr'=>0, 'sb'=>0, 'cs'=>0];
        $event = profileBatEvent($row);
        foreach ($games[$key] as $stat=>$value) $games[$key][$stat] += $event[$stat];
    }
    return $games;
}

function profileCalculateStreaks(array $rows): array {
    $games=profileStreakGames($rows);
    $values = ['h'=>[], 'ob'=>[], 'hr'=>[], 'sb'=>[]];
    foreach ($games as $id=>$game) {
        $date=explode('|',$id,2)[0];
        // A defensive appearance or pinch-running appearance without a PA
        // does not end a batting streak, but its steal attempts still count.
        if ($game['pa'] > 0) {
            $values['h'][] = ['positive'=>$game['h'] > 0,'date'=>$date];
            $values['ob'][] = ['positive'=>$game['h'] + $game['bb'] + $game['hbp'] > 0,'date'=>$date];
            $values['hr'][] = ['positive'=>$game['hr'] > 0,'date'=>$date];
        }
        // Count games with attempts. Any caught stealing breaks success,
        // including games that also contain a successful steal.
        if ($game['sb'] + $game['cs'] > 0) $values['sb'][] = ['positive'=>$game['cs'] === 0,'date'=>$date];
    }
    $result = [];
    foreach (['h'=>'안타', 'ob'=>'출루', 'hr'=>'홈런', 'sb'=>'도루'] as $key=>$label) {
        $states = $values[$key];
        $positive = $states[0]['positive'] ?? null;
        $count = 0;
        $startDate=null;$endDate=null;
        foreach ($states as $state) { if ($state['positive'] !== $positive) break; $count++;$endDate??=$state['date'];$startDate=$state['date']; }
        if($key==='sb'&&$positive===null&&$games){
            $dates=array_map(static fn($id)=>explode('|',$id,2)[0],array_keys($games));
            $count=count($dates);$endDate=$dates[0];$startDate=$dates[$count-1];
        }
        $result[] = ['key'=>$key, 'label'=>$label, 'count'=>$count, 'positive'=>$positive,'startDate'=>$startDate,'endDate'=>$endDate];
    }
    return $result;
}

/** Preserve this season's gray no-attempt state while counting into prior seasons. */
function profileNoAttemptStreak(array $games): array {
    $result=['key'=>'sb','label'=>'도루','count'=>0,'positive'=>null,'startDate'=>null,'endDate'=>null];
    foreach($games as $id=>$game){
        if($game['sb']+$game['cs']>0)break;
        $date=explode('|',$id,2)[0];
        $result['count']++;$result['endDate']??=$date;$result['startDate']=$date;
    }
    return $result;
}

/** Fetch another season only while a current streak reaches the loaded boundary. */
function profileExtendStreaks(array $currentRows, array $previousYears, callable $loadSeason): array {
    $initial=profileCalculateStreaks($currentRows);
    $rows=$currentRows;$result=$initial;
    $calculate=static function(array $rows)use($initial):array {
        $result=profileCalculateStreaks($rows);
        foreach($initial as $index=>$streak){
            if($streak['positive']!==null)continue;
            $result[$index]=$streak['key']==='sb'?profileNoAttemptStreak(profileStreakGames($rows)):$streak;
        }
        return $result;
    };
    foreach($previousYears as $year){
        $games=profileStreakGames($rows);$batting=0;$attempts=0;
        foreach($games as $game){$batting+=(int)($game['pa']>0);$attempts+=(int)($game['sb']+$game['cs']>0);}
        $pending=false;
        foreach($result as $streak){
            $eligible=$streak['key']==='sb'?($streak['positive']===null?count($games):$attempts):$batting;
            if($streak['count']>0&&$streak['count']===$eligible){$pending=true;break;}
        }
        if(!$pending)break;
        $olderRows=$loadSeason($year);
        if(!$olderRows)continue;
        $rows=array_merge($rows,$olderRows);$result=$calculate($rows);
    }
    return $result;
}

function profileCurrentStreaks(PDO $db, string $pid, array $schedule): ?array {
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Seoul'));
    $year = (int)$today->format('Y');
    [$start, $end] = $schedule[$year]['regular'] ?? ['', ''];
    if (!$start || !$end || $start > $today->format('Y-m-d')) return null;
    $end = min($end, $today->format('Y-m-d'));
    $stmt = $db->prepare('SELECT game_id,game_date,pa_result,sb,cs FROM kbo_season_records
        WHERE league_level=1 AND player_id=? AND game_date BETWEEN ? AND ?'.profileNotTiebreakerSql().'
        ORDER BY game_date DESC,game_id DESC,PK DESC');
    $stmt->execute([$pid, $start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)return null;
    $previousYears=array_filter(array_keys($schedule),static fn($y)=>(int)$y<$year);
    rsort($previousYears,SORT_NUMERIC);
    $loadSeason=static function($y)use($stmt,$pid,$schedule):array {
        [$start,$end]=$schedule[$y]['regular']??['',''];
        if(!$start||!$end)return [];
        $stmt->execute([$pid,$start,$end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    return ['year'=>$year, 'rows'=>profileExtendStreaks($rows,$previousYears,$loadSeason)];
}
