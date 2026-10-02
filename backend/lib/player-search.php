<?php
declare(strict_types=1);
require_once __DIR__.'/player-search-stats.php';

function searchKboPlayers(PDO $db, string $keyword, bool $useStatsCache=true): array {
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $literal = strtr($keyword, ['\\'=>'\\\\', '%'=>'\\%', '_'=>'\\_']);
    $contains = '%' . $literal . '%';
    $nicknameKeyword = preg_replace('/\s+/u', '', $keyword);
    $nicknameContains = '%' . strtr($nicknameKeyword, ['\\'=>'\\\\', '%'=>'\\%', '_'=>'\\_']) . '%';
    $stmt = $db->prepare("SELECT p.name, p.player_id, p.img, p.pos, p.mainPos,
            p.draft, p.retire, p.team AS stored_team, p.backNo AS back_no, p.is_kbodle, p.is_number_retired,
            CASE WHEN p.is_kbodle = 0 THEN '은퇴' ELSE COALESCE(NULLIF(p.team,''), '소속 미확인') END AS current_status,
            CASE WHEN p.name LIKE :rank_name THEN 0 WHEN p.fullname LIKE :rank_fullname THEN 1 WHEN p.oldname LIKE :rank_oldname THEN 2 ELSE 3 END AS match_field,
            CASE WHEN p.name=:exact_name THEN 0 WHEN p.name LIKE :prefix_name THEN 1 ELSE 2 END AS match_name
        FROM kbo_player_data p
        WHERE p.name LIKE :name OR p.fullname LIKE :fullname OR p.oldname LIKE :oldname
            OR EXISTS (SELECT 1 FROM kbo_player_nicknames n WHERE n.player_id=p.player_id AND REGEXP_REPLACE(n.nickname, '[[:space:]]+', '') LIKE :nickname)
        ORDER BY match_field,match_name,
            p.name,
            CASE WHEN p.img REGEXP '^[0-9]{4}_' THEN CAST(SUBSTRING_INDEX(p.img, '_', 1) AS UNSIGNED) ELSE 0 END DESC,
            p.player_id");
    $stmt->execute([
        'name'=>$contains, 'fullname'=>$contains, 'oldname'=>$contains, 'nickname'=>$nicknameContains,
        'rank_name'=>$contains, 'rank_fullname'=>$contains, 'rank_oldname'=>$contains,
        'exact_name'=>$keyword, 'prefix_name'=>$literal . '%',
    ]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats=searchPlayerRecordStats($db,array_column($rows,'player_id'),$useStatsCache);
    foreach ($rows as $index=>&$row) {
        $row['first_team_games']=$stats[$row['player_id']]['games']??0;
        $row['futures_games']=$stats[$row['player_id']]['futures_games']??0;
        $row['last_record_year']=$stats[$row['player_id']]['last_year']??null;
        $row['first_record_year']=$stats[$row['player_id']]['first_year']??null;
        $row['_stable_order']=$index;
    }
    unset($row);
    usort($rows,static fn($a,$b)=>($a['match_field']<=>$b['match_field']) ?: ($a['match_name']<=>$b['match_name']) ?: ($b['first_team_games']<=>$a['first_team_games']) ?: (($a['first_team_games']===0 && $b['first_team_games']===0)?($b['futures_games']<=>$a['futures_games']):0) ?: ($a['_stable_order']<=>$b['_stable_order']));
    foreach ($rows as &$row) unset($row['_stable_order'],$row['match_field'],$row['match_name']);
    unset($row);
    return $rows;
}

/** Retirement fallback uses every league and season, for batters and pitchers. */
function profileLastRecordYear(PDO $db, string $pid): ?int {
    $stmt=$db->prepare("SELECT MAX(last_year) FROM (
        SELECT YEAR(MAX(game_date)) AS last_year FROM kbo_season_records WHERE player_id=?
        UNION ALL SELECT YEAR(MAX(game_date)) FROM kbo_season_pitch_records WHERE player_id=?
        UNION ALL SELECT MAX(year) FROM kbo_player_season_batting_totals WHERE player_id=? AND games>0
        UNION ALL SELECT MAX(year) FROM kbo_player_season_pitching_totals WHERE player_id=? AND games>0
    ) recorded_years");
    $stmt->execute([$pid,$pid,$pid,$pid]);
    $year=$stmt->fetchColumn();
    return $year!==false&&$year!==null?(int)$year:null;
}
