<?php
declare(strict_types=1);
require_once __DIR__.'/player-search-stats.php';

/**
 * 초성이 섞인 검색어(ㄱㄷㅇ, 김ㄷㅇ)를 이름 앞부분에 맞추는 정규식으로 바꾼다. 초성이 없거나 한글이 아닌 글자가 있으면 null.
 */
function playerSearchInitialPattern(string $keyword): ?string {
    $chars=preg_split('//u',preg_replace('/\s+/u','',$keyword),-1,PREG_SPLIT_NO_EMPTY);
    $initials=['ㄱ','ㄲ','ㄴ','ㄷ','ㄸ','ㄹ','ㅁ','ㅂ','ㅃ','ㅅ','ㅆ','ㅇ','ㅈ','ㅉ','ㅊ','ㅋ','ㅌ','ㅍ','ㅎ'];
    $pattern='^'; $hasInitial=false;
    foreach ($chars as $char) {
        $index=array_search($char,$initials,true);
        if ($index!==false) {
            $start=0xAC00+$index*588;
            $pattern.='['.mb_chr($start,'UTF-8').'-'.mb_chr($start+587,'UTF-8').']';
            $hasInitial=true;
        } elseif (preg_match('/^[가-힣]$/u',$char)) $pattern.=$char;
        else return null;
    }
    return $hasInitial?$pattern:null;
}

function searchKboPlayers(PDO $db, string $keyword, bool $useStatsCache=true): array {
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $initialPattern = playerSearchInitialPattern($keyword);
    if ($initialPattern !== null) return searchKboPlayersByInitials($db, $initialPattern, $useStatsCache);
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
        WHERE COALESCE(p.pos, '') <> '코치'
            AND (p.name LIKE :name OR p.fullname LIKE :fullname OR p.oldname LIKE :oldname
            OR EXISTS (SELECT 1 FROM kbo_player_nicknames n WHERE n.player_id=p.player_id AND REGEXP_REPLACE(n.nickname, '[[:space:]]+', '') LIKE :nickname))
        ORDER BY match_field,match_name,
            p.name,
            CASE WHEN p.img REGEXP '^[0-9]{4}_' THEN CAST(SUBSTRING_INDEX(p.img, '_', 1) AS UNSIGNED) ELSE 0 END DESC,
            p.player_id");
    $stmt->execute([
        'name'=>$contains, 'fullname'=>$contains, 'oldname'=>$contains, 'nickname'=>$nicknameContains,
        'rank_name'=>$contains, 'rank_fullname'=>$contains, 'rank_oldname'=>$contains,
        'exact_name'=>$keyword, 'prefix_name'=>$literal . '%',
    ]);
    return searchPlayerRankRows($db,$stmt->fetchAll(PDO::FETCH_ASSOC),$useStatsCache);
}

/** 초성 검색: 이름 앞부분이 초성과 맞는 선수. 글자 수까지 같은 이름을 먼저 둔다. */
function searchKboPlayersByInitials(PDO $db, string $pattern, bool $useStatsCache=true): array {
    $length=preg_match_all('/\[|[가-힣]/u',$pattern);
    $stmt=$db->prepare("SELECT p.name, p.player_id, p.img, p.pos, p.mainPos,
            p.draft, p.retire, p.team AS stored_team, p.backNo AS back_no, p.is_kbodle, p.is_number_retired,
            CASE WHEN p.is_kbodle = 0 THEN '은퇴' ELSE COALESCE(NULLIF(p.team,''), '소속 미확인') END AS current_status,
            0 AS match_field,
            CASE WHEN CHAR_LENGTH(p.name)=:length THEN 0 ELSE 1 END AS match_name
        FROM kbo_player_data p
        WHERE COALESCE(p.pos, '') <> '코치' AND p.name REGEXP :pattern
        ORDER BY match_name, p.name, p.player_id");
    $stmt->execute(['pattern'=>$pattern,'length'=>$length]);
    return searchPlayerRankRows($db,$stmt->fetchAll(PDO::FETCH_ASSOC),$useStatsCache);
}

/** 검색 결과에 기록 통계를 붙이고, 일치 정도 → 1군 경기 수 → 퓨처스 경기 수 순으로 정렬한다. */
function searchPlayerRankRows(PDO $db, array $rows, bool $useStatsCache): array {
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
