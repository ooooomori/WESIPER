<?php
declare(strict_types=1);

function profileGameSeasonAvailability(array $dates, array $schedule): array {
    $available=[];
    foreach($dates as $row) {
        if((int)($row['is_allstar']??0)===1)continue;
        $date=(string)$row['game_date'];$year=(int)substr($date,0,4);
        if((int)$row['league_level']===2) {
            $available[$year]['futures']=true;
        } elseif((int)$row['league_level']===1) {
            foreach(['regular','postseason','preseason'] as $season) {
                [$start,$end]=$schedule[$year][$season]??['',''];
                if($start&&$end&&$date>=$start&&$date<=$end)$available[$year][$season]=true;
            }
        }
    }
    krsort($available,SORT_NUMERIC);
    foreach($available as &$seasons)$seasons=array_values(array_filter(['regular','futures','postseason','preseason'],static fn($season)=>isset($seasons[$season])));
    unset($seasons);
    return $available;
}

function profilePlayerGameSeasons(PDO $db, array $player, array $schedule): array {
    $table=str_contains((string)$player['Pos'],'투수')?'kbo_season_pitch_records':'kbo_season_records';
    // Match the schedule's game_code charset/collation on the record side so
    // PRIMARY (league_level, game_code) can resolve each game with one lookup.
    $query=$db->prepare("SELECT DISTINCT r.game_date,r.league_level,COALESCE(s.is_allstar,0) AS is_allstar
        FROM (SELECT DISTINCT game_date,league_level,LEFT(game_id,13) AS game_code
            FROM `$table` FORCE INDEX (idx_search_league_player_game)
            WHERE player_id=? AND league_level IN (1,2)) r
        LEFT JOIN kbo_schedule s ON s.league_level=r.league_level AND s.game_code=CONVERT(r.game_code USING utf8mb4) COLLATE utf8mb4_general_ci");
    $query->execute([$player['PlayerId']]);
    return profileGameSeasonAvailability($query->fetchAll(PDO::FETCH_ASSOC),$schedule);
}
