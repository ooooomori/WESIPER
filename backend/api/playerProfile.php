<?php
header('Content-Type: application/json; charset=utf-8');
// 기록은 하루 한 번(02:00) 크롤링으로 바뀌므로 5분간 재사용한다. 오류 응답은 저장하지 않는다.
header('Cache-Control: public, max-age=300');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
$pid = $_GET['pid'] ?? '';
if (!is_string($pid) || !preg_match('/^\d{1,10}$/D', $pid)) {
    http_response_code(400);
    echo json_encode(['error' => '잘못된 선수 ID입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    require_once __DIR__ . '/kbocandle/common.php';
    require_once __DIR__ . '/../lib/player-season-schedule.php';
    require_once __DIR__ . '/../lib/player-records.php';
    $part=$_GET['part']??'all';
    $stmt = $pdo->prepare("SELECT p.player_id AS PlayerId, p.name AS Name, p.img AS Img, p.pos AS Pos,
        p.fullname AS FullName, p.oldname AS OldName, p.is_foreign AS IsForeign, p.is_kbodle AS IsKbodle,
        p.team AS StoredTeam, p.is_number_retired AS IsNumberRetired,
        p.body AS Body, p.birth AS Birth, p.school AS School, p.backNo AS BackNo,
        p.bat AS Bat, p.`throw` AS Throws, p.draft AS Draft, p.retire AS Retire, p.mainPos AS MainPos, p.subPos AS SubPos,
        CASE WHEN p.is_kbodle = 0 THEN '은퇴' ELSE COALESCE(NULLIF(p.team,''), '소속 미확인') END AS Team
        FROM kbo_player_data p WHERE p.player_id=:pid LIMIT 1");
    $stmt->execute(['pid' => $pid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$player) { http_response_code(404); echo json_encode(['error'=>'선수를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE); exit; }
    foreach (['Body','Birth','School','BackNo','Bat','Throws','Draft','MainPos','SubPos'] as $field) {
        if (is_string($player[$field])) { $trimmed = trim($player[$field]); $player[$field] = $trimmed === '' ? null : $trimmed; } // "0"(등번호 0)은 남긴다
    }
    if ($player['Body'] === 'cm, kg') $player['Body'] = null;
    $player['NumberRetiredTeam'] = (int)$player['IsNumberRetired'] === 1 ? profileTeam(trim((string)$player['StoredTeam']) ?: null) : null;
    $player['FormerTeam'] = null;
    if ((string)$player['IsKbodle'] === '0') {
        $player['FormerTeam'] = trim((string)$player['StoredTeam']) ?: null;
        if ($player['FormerTeam'] === null) {
        $lastTeam = $pdo->prepare("SELECT team FROM ((SELECT team,game_date,game_id FROM kbo_season_records WHERE league_level=1 AND player_id=? AND game_date=(SELECT MAX(game_date) FROM kbo_season_records WHERE league_level=1 AND player_id=?) ORDER BY game_id DESC LIMIT 1) UNION ALL (SELECT team,game_date,game_id FROM kbo_season_pitch_records WHERE league_level=1 AND player_id=? AND game_date=(SELECT MAX(game_date) FROM kbo_season_pitch_records WHERE league_level=1 AND player_id=?) ORDER BY game_id DESC LIMIT 1)) recent ORDER BY game_date DESC,game_id DESC LIMIT 1");
        $lastTeam->execute([$pid,$pid,$pid,$pid]);
        $player['FormerTeam'] = $lastTeam->fetchColumn() ?: null;
        }
        // 경기별 기록이 없는 1982~2000년 선수는 연도별 공식 기록의 마지막 시즌 팀을 쓴다.
        if ($player['FormerTeam'] === null) {
            try {
                $historicalTeam = $pdo->prepare("SELECT team_name FROM (
                        SELECT year, games, team_name FROM kbo_player_season_batting_totals WHERE league_level=1 AND series_id=0 AND player_id=?
                        UNION ALL SELECT year, games, team_name FROM kbo_player_season_pitching_totals WHERE league_level=1 AND series_id=0 AND player_id=?
                    ) seasons WHERE team_name IS NOT NULL AND team_name<>'' AND team_name NOT LIKE '%/%' AND team_name NOT LIKE '%,%'
                    ORDER BY year DESC, games DESC LIMIT 1");
                $historicalTeam->execute([$pid, $pid]);
                $player['FormerTeam'] = $historicalTeam->fetchColumn() ?: null;
            } catch (Throwable $historicalTeamError) { error_log('Historical former team unavailable: ' . $historicalTeamError->getMessage()); }
        }
    }
    // 은퇴·영구결번 선수의 활동 기간(선수 검색과 같은 기준): 첫·마지막 경기 기록 연도
    $player['FirstRecordYear'] = null;
    $player['LastRecordYear'] = null;
    if ((string)$player['IsKbodle'] === '0') {
        try {
            require_once __DIR__ . '/../lib/player-search-stats.php';
            $recordStats = searchPlayerRecordStats($pdo, [(int)$pid])[(int)$pid] ?? [];
            $player['FirstRecordYear'] = $recordStats['first_year'] ?? null;
            $player['LastRecordYear'] = empty($player['Retire']) ? ($recordStats['last_year'] ?? null) : null;
        } catch (Throwable $recordYearError) { error_log('Profile record years unavailable: ' . $recordYearError->getMessage()); }
    }
    unset($player['StoredTeam']);
    $career=[];
    if(in_array($part,['profile','all'],true)){
    $nicknames = $pdo->prepare('SELECT nickname FROM kbo_player_nicknames WHERE player_id=? ORDER BY `PK`');
    $nicknames->execute([$pid]);
    $player['Nicknames'] = $nicknames->fetchAll(PDO::FETCH_COLUMN);
    $titles = $pdo->prepare('SELECT type,year,record FROM kbo_player_titleholder WHERE player_id=? ORDER BY year,PK');
    $titles->execute([$pid]);
    $player['Titleholders'] = $titles->fetchAll(PDO::FETCH_ASSOC);
    // 선수 이동 현황(KBO 공시 + 조사로 보강한 계약). 최신이 위로 오고, 같은 날은 공시 원문 순서를 따른다.
    try {
        // 날짜 없이 연도만 있는 트레이드(웨이버)는 같은 해 웨이버 공시 바로 위에, 공시가 없으면 연말에 둔다. 다른 연도 전용 줄은 연초 기준이다.
        $sortDate = "COALESCE(m.event_date, IF(m.event_type='트레이드(웨이버)', COALESCE((SELECT MAX(w.event_date) FROM kbo_player_movements w WHERE w.player_id=m.player_id AND w.year=m.year AND w.event_type='웨이버'), CONCAT(m.year, '-12-31')), CONCAT(m.year, '-01-01')))";
        $movementColumns = "COALESCE(CAST(m.event_date AS CHAR), CAST(m.year AS CHAR)) AS date, m.event_type AS type, m.team, m.note, m.old_back_no AS oldBackNo, m.new_back_no AS newBackNo, $sortDate AS sortDate";
        $contractColumns = ', m.contract_years AS contractYears, m.contract_term AS contractTerm, m.contract_total_amount AS contractTotal,
            m.contract_registered_amount AS contractRegistered, m.contract_currency AS contractCurrency, m.contract_details AS contractDetails, m.contract_source_url AS contractSources';
        $movementSql = static fn(string $columns) => "SELECT $columns FROM kbo_player_movements m WHERE m.player_id=? ORDER BY $sortDate DESC, (m.event_date IS NULL AND m.event_type='트레이드(웨이버)') DESC, m.source_page, m.source_row, m.id";
        try {
            $movements = $pdo->prepare($movementSql($movementColumns . $contractColumns));
            $movements->execute([$pid]);
        } catch (PDOException $columnError) {
            // 계약 컬럼이 없는 DB에서도 기본 이동 현황은 보여준다.
            $movements = $pdo->prepare($movementSql($movementColumns));
            $movements->execute([$pid]);
        }
        $player['Movements'] = $movements->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $movementError) {
        error_log('Player movements unavailable: ' . $movementError->getMessage());
        $player['Movements'] = [];
    }
    // 트레이드 상세(주고받은 선수·현금·지명권). 표가 없거나 실패해도 기본 이동 현황은 그대로 보여준다.
    try {
        require_once __DIR__ . '/../lib/player-trades.php';
        $player['Movements'] = profileAttachTrades($pdo, (string)$pid, $player['Movements']);
        foreach ($player['Movements'] as &$movementRow) unset($movementRow['sortDate']);
        unset($movementRow);
    } catch (Throwable $tradeError) { error_log('Player trades unavailable: ' . $tradeError->getMessage()); }
    // 입단(kbo_player_data.draft, 예: "22 KIA 1차")도 가장 오래된 이동 내역으로 붙인다.
    if (preg_match('/^(\d{2})\s+(\S+)(?:\s+(.+))?$/u', (string)$player['Draft'], $draft)) {
        // "07 해외진출선수 특별지명"은 지명 구단이 없으므로 팀을 MLB로 두고 문구 전체를 비고로 쓴다.
        $overseas = $draft[2] === '해외진출선수';
        // "1차"만 적힌 경우는 "1차 지명"으로 풀어 쓴다.
        $draftNote = trim($draft[3] ?? '') === '1차' ? '1차 지명' : ($draft[3] ?? '');
        $player['Movements'][] = ['date' => (string)(((int)$draft[1] >= 82 ? 1900 : 2000) + (int)$draft[1]), 'type' => '입단', 'team' => $overseas ? 'MLB' : $draft[2], 'note' => $overseas ? trim($draft[2] . ' ' . ($draft[3] ?? '')) : $draftNote, 'oldBackNo' => null, 'newBackNo' => null];
    }
    // 가족관계: 한 쌍은 한 방향으로만 저장되므로, 상대 쪽에서 조회할 때는 관계를 뒤집어 보여준다.
    try {
        $family = $pdo->prepare('SELECT f.player_id AS OwnerId, f.relationship AS Relationship, p.player_id AS PlayerId, p.name AS Name, p.birth AS Birth, p.is_kbodle AS IsKbodle, p.team AS Team
            FROM kbo_player_family f JOIN kbo_player_data p ON p.player_id = IF(f.player_id = ?, f.relative_player_id, f.player_id)
            WHERE f.player_id = ? OR f.relative_player_id = ? ORDER BY f.PK');
        $family->execute([$pid, $pid, $pid]);
        $reverse = ['아버지'=>'아들','아들'=>'아버지','형'=>'동생','동생'=>'형','오빠'=>'동생','누나'=>'동생','장인'=>'사위','사위'=>'장인',
            '매형'=>'처남','매제'=>'처남','사촌형'=>'사촌동생','사촌동생'=>'사촌형','사촌'=>'사촌','삼촌'=>'조카','외삼촌'=>'조카','할아버지'=>'손자','외할아버지'=>'외손자','손자'=>'할아버지','외손자'=>'외할아버지','쌍둥이 형'=>'쌍둥이 동생','쌍둥이 동생'=>'쌍둥이 형'];
        $myBirth = (string)($player['Birth'] ?? '');
        $player['Family'] = array_map(static function (array $row) use ($pid, $reverse, $myBirth) {
            $relation = trim((string)$row['Relationship']);
            if ((string)$row['OwnerId'] !== (string)$pid) {
                // 처남의 반대는 나이로 매형/매제를 가른다. 표에 없는 관계는 그대로 둔다.
                if ($relation === '처남') $relation = ($row['Birth'] && $myBirth && $row['Birth'] < $myBirth) ? '매형' : '매제';
                elseif (in_array($relation, ['조카'], true)) $relation = '삼촌';
                else $relation = $reverse[$relation] ?? $relation;
            }
            return ['PlayerId'=>(int)$row['PlayerId'], 'Name'=>$row['Name'], 'Relationship'=>$relation, 'Birth'=>$row['Birth'], 'IsActive'=>(string)$row['IsKbodle'] !== '0', 'Team'=>trim((string)$row['Team']) ?: null];
        }, $family->fetchAll(PDO::FETCH_ASSOC));
        // 윗사람(나이 많은 순)부터 보여준다.
        usort($player['Family'], static fn($a, $b) => strcmp((string)$a['Birth'], (string)$b['Birth']));
    } catch (Throwable $familyError) {
        error_log('Player family unavailable: ' . $familyError->getMessage());
        $player['Family'] = [];
    }
    $stmt = $pdo->prepare('SELECT category,type,team,country,year,month,pos,note FROM kbo_player_career WHERE player_id=:pid ORDER BY year,month,category,type,PK');
    $stmt->execute(['pid'=>$pid]);
    $career=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if($part==='similar') {
        require_once __DIR__.'/../lib/player-similarity.php';
        // age: 현역 선수의 올해 시즌 나이. byAge=1이면 그 나이까지의 누적 기록으로 비교한 목록을 준다.
        $age=similarityCurrentAge($player);$byAge=($_GET['byAge']??'')==='1';
        $similar=$byAge&&$age===null?null:similarPlayers($pdo,(int)$pid,str_contains((string)$player['Pos'],'투수'),profileSchedule(),$byAge?$age:null);
        echo json_encode(['similar'=>$similar,'age'=>$age],JSON_UNESCAPED_UNICODE);exit;
    }
    if($part==='year-records') {
        $type=$_GET['type']??(str_contains((string)$player['Pos'],'투수')?'pitcher':'batter');
        if(!in_array($type,['pitcher','batter','all'],true)){http_response_code(400);echo json_encode(['error'=>'잘못된 기록 종류입니다.'],JSON_UNESCAPED_UNICODE);exit;}
        require_once __DIR__.'/../lib/player-year-records.php';
        require_once __DIR__.'/../lib/player-fielding-records.php';
        $schedule=profileSchedule();
        $yearRecords=$type==='all'
            ? ['batter'=>profileYearRecords($pdo,(string)$pid,false,$schedule),'pitcher'=>profileYearRecords($pdo,(string)$pid,true,$schedule)]
            : profileYearRecords($pdo,(string)$pid,$type==='pitcher',$schedule);
        // 비율 기록 커리어 하이 비교용 규정 타석·이닝 충족 여부
        require_once __DIR__.'/../lib/player-year-qualified.php';
        try {
            if($type==='all'){foreach(['batter','pitcher'] as $kind)$yearRecords[$kind]=profileAnnotateQualified($pdo,$yearRecords[$kind],$kind==='pitcher',$schedule);}
            else $yearRecords=profileAnnotateQualified($pdo,$yearRecords,$type==='pitcher',$schedule);
        } catch (Throwable $qualifiedError) { error_log('Year qualification unavailable: '.$qualifiedError->getMessage()); }
        // 기본 탭 리그 1위: 미리 계산된 kbo_player_year_leaders에서 읽는다(deploy/build-year-leaders.php). 표가 없으면 생략.
        $leaderKeys=['batter'=>[],'pitcher'=>[]];
        try {
            $leaderQuery=$pdo->prepare('SELECT year,role,stat FROM kbo_player_year_leaders WHERE player_id=?');$leaderQuery->execute([$pid]);
            while($leader=$leaderQuery->fetch(PDO::FETCH_ASSOC))$leaderKeys[$leader['role']][(string)$leader['year']][]=$leader['stat'];
        } catch (Throwable $leaderError) { error_log('Year leaders unavailable: '.$leaderError->getMessage()); }
        if($type==='all'){foreach(['batter','pitcher'] as $kind)$yearRecords[$kind]['leaders']=(object)$leaderKeys[$kind];}
        else $yearRecords['leaders']=(object)$leaderKeys[$type];
        if($type!=='pitcher')$yearRecords['fielding']=profileFieldingRecords($pdo,(string)$pid,$schedule);
        if($type==='all'){foreach(['preseason','postseason','futures'] as $season)$yearRecords['seasons'][$season]=['batter'=>profileYearRecords($pdo,(string)$pid,false,$schedule,$season),'pitcher'=>profileYearRecords($pdo,(string)$pid,true,$schedule,$season)];}
        echo json_encode($yearRecords,JSON_UNESCAPED_UNICODE);exit;
    }
    if($part==='games') {
        $schedule=profileSchedule();
        require_once __DIR__.'/../lib/player-game-seasons.php';
        // 경기 일지의 타자·투수 선택: 지정하지 않으면 등록 포지션을 따른다.
        $basePitcher=str_contains((string)$player['Pos'],'투수');
        $type=$_GET['type']??($basePitcher?'pitcher':'batter');
        if(!in_array($type,['pitcher','batter'],true)){http_response_code(400);echo json_encode(['error'=>'잘못된 기록 종류입니다.'],JSON_UNESCAPED_UNICODE);exit;}
        $gamePitcher=$type==='pitcher';
        if($gamePitcher!==$basePitcher)$player['Pos']=$gamePitcher?'투수':'타자';
        $availableSeasons=profilePlayerGameSeasons($pdo,$player,$schedule);
        $years=array_keys($availableSeasons);
        // year=all: 선택한 시즌 종류의 통산 경기를 연도 순서와 무관하게 모두 합쳐 돌려준다(스플릿 차트의 통산).
        $allYears=($_GET['year']??null)==='all';
        $year=$allYears?null:(isset($_GET['year'])?filter_var($_GET['year'],FILTER_VALIDATE_INT):($years[0]??null));
        $season=$_GET['season']??($allYears?'regular':($year!==null?$availableSeasons[$year][0]??'regular':'regular'));
        $seasonYears=array_values(array_filter($years,static fn($y)=>in_array($season,$availableSeasons[$y],true)));
        if(!in_array($season,['regular','preseason','postseason','futures'],true)||($allYears?!$seasonYears:($year!==null&&(!in_array($year,$years,true)||!in_array($season,$availableSeasons[$year],true))))) {
            http_response_code(400);echo json_encode(['error'=>'잘못된 경기 조회 조건입니다.'],JSON_UNESCAPED_UNICODE);exit;
        }
        require_once __DIR__.'/../lib/player-chart-cache.php';
        require_once __DIR__.'/../lib/player-streaks.php';
        // 올해가 들어간 조회(올해·통산)만 크롤러 revision을 따라 갱신한다. 연도 목록은 캐시하지 않고 매번 새로 읽는다.
        $currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
        $volatile=$allYears||$year===null||(int)$year>=$currentYear;
        $cacheKey=[(string)$pid,$type,$allYears?'all':$year,$season,$player['Pos'],(string)$player['IsKbodle'],md5(json_encode($schedule)),'v2'];
        $computed=json_decode(profileChartCachedJson('games',$cacheKey,$volatile,static function() use ($pdo,$player,$schedule,$allYears,$year,$season,$seasonYears,$gamePitcher,$basePitcher,$pid) {
            $streakRows=null;
            if($allYears) {
                $record=['games'=>[]];
                foreach($seasonYears as $seasonYear) {
                    $yearRecord=profileRecords($pdo,$player,$schedule,$seasonYear,$season,true,$streakRows);
                    foreach($yearRecord['games']??[] as $yearGame)$record['games'][]=$yearGame;
                }
            } else $record=$year!==null?profileRecords($pdo,$player,$schedule,$year,$season,true,$streakRows):null;
            // 투수 FIP 계산용 리그 상수(스플릿 기록의 FIP). 연도별로 달라 연도→상수 표로도 돌려준다. 계산할 수 없으면 null.
            $fipConstant=null;$fipConstants=[];
            if($gamePitcher&&($year!==null||$allYears)) {
                try {
                    $contexts=profileLeaguePitchingContexts($pdo,$schedule,$season==='futures'?2:1);
                    foreach($allYears?$seasonYears:[$year] as $constantYear)$fipConstants[$constantYear]=$contexts[$constantYear]['fipConstant']??null;
                    if($year!==null)$fipConstant=$fipConstants[$year]??null;
                } catch (Throwable $contextError) { error_log('FIP constant unavailable: '.$contextError->getMessage()); }
            }
            return ['fipConstant'=>$fipConstant,'fipConstants'=>(object)$fipConstants,'games'=>$record['games']??[],'currentSeasonStreaks'=>($basePitcher||$allYears)?null:profileCurrentStreaks($pdo,(string)$pid,$schedule,$streakRows)];
        }),true);
        // 전반기·후반기 스플릿용 후반기 시작일(정규시즌만).
        $halfStarts=[];
        if($season==='regular') {
            try { $halfStarts=profileHalfStarts($pdo,$schedule,$allYears?$seasonYears:($year!==null?[$year]:[])); }
            catch (Throwable $halfError) { error_log('Half-season boundaries unavailable: '.$halfError->getMessage()); }
        }
        echo json_encode(['fipConstant'=>$computed['fipConstant']??null,'fipConstants'=>(object)($computed['fipConstants']??[]),'halfStarts'=>(object)$halfStarts,'years'=>$years,'year'=>$allYears?'all':$year,'season'=>$season,'availableSeasons'=>(object)$availableSeasons,'games'=>$computed['games']??[],'pitcher'=>$gamePitcher,'currentSeasonStreaks'=>$computed['currentSeasonStreaks']??null],JSON_UNESCAPED_UNICODE);exit;
    }
    if($part==='innings') {
        // 타석 단위 스플릿: 타석 기록(kbo_season_records)을 이닝별(by=inning, 10회 이후는 연장으로 합침) 또는 타순별(by=order)로 묶어 돌려준다.
        // side=pitcher면 이 선수가 투수로 상대한 타석을 묶는다(상대 타순별).
        $schedule=profileSchedule();
        $by=$_GET['by']??'inning';$side=$_GET['side']??'batter';
        if(!in_array($by,['inning','order'],true)||!in_array($side,['batter','pitcher'],true)){http_response_code(400);echo json_encode(['error'=>'잘못된 조회 조건입니다.'],JSON_UNESCAPED_UNICODE);exit;}
        $allYears=($_GET['year']??null)==='all';
        $year=$allYears?'all':filter_var($_GET['year']??null,FILTER_VALIDATE_INT);
        $season=$_GET['season']??'regular';
        if(!in_array($season,['regular','preseason','postseason','futures'],true)||$year===false||$year===null||(!$allYears&&($year<1982||$year>2100))) {
            http_response_code(400);echo json_encode(['error'=>'잘못된 조회 조건입니다.'],JSON_UNESCAPED_UNICODE);exit;
        }
        // 통산이면 시즌 종류의 연도별 기간을 OR로 묶는다(퓨처스는 날짜 제한 없이 전체).
        $ranges=[];
        if($allYears) {
            if($season!=='futures')foreach($schedule as $scheduleYear=>$seasons){[$rangeStart,$rangeEnd]=$seasons[$season]??['',''];if($rangeStart&&$rangeEnd)$ranges[]=[$rangeStart,$rangeEnd];}
            $rangeSql=$season==='futures'?'':' AND ('.implode(' OR ',array_fill(0,max(count($ranges),1),'game_date BETWEEN ? AND ?')).')';
            if($season!=='futures'&&!$ranges){echo json_encode(['year'=>$year,'season'=>$season,'rows'=>[]],JSON_UNESCAPED_UNICODE);exit;}
        } else {
            [$start,$end]=$season==='futures'?[$year.'-01-01',$year.'-12-31']:($schedule[$year][$season]??['','']);
            if(!$start||!$end){echo json_encode(['year'=>$year,'season'=>$season,'rows'=>[]],JSON_UNESCAPED_UNICODE);exit;}
            $ranges[]=[$start,$end];
            $rangeSql=' AND game_date BETWEEN ? AND ?';
        }
        $leagueLevel=$season==='futures'?2:1;
        require_once __DIR__.'/../lib/player-chart-cache.php';
        $currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
        $volatile=$allYears||(int)$year>=$currentYear;
        echo profileChartCachedJson('innings',[(string)$pid,$allYears?'all':(int)$year,$season,md5(json_encode($schedule)),$by,$side,'v3'],$volatile,static function() use ($pdo,$pid,$year,$season,$leagueLevel,$rangeSql,$ranges,$allYears,$by,$side) {
            $params=[$pid,...($allYears&&$season==='futures'?[]:array_merge(...$ranges))];
            $where='league_level='.$leagueLevel.' AND player_id=?'.$rangeSql;
            if($side==='pitcher') {
                // pitcher_id와 날짜만으로는 느려서, 투수 기록에서 등판 경기를 먼저 찾아 그 경기의 타석만 읽는다.
                $games=$pdo->prepare('SELECT DISTINCT game_id FROM kbo_season_pitch_records WHERE '.$where);
                $games->execute($params);
                $gameIds=$games->fetchAll(PDO::FETCH_COLUMN);
                if(!$gameIds)return ['year'=>$year,'season'=>$season,'rows'=>[]];
                $where='league_level='.$leagueLevel.' AND pitcher_id=? AND game_id IN ('.implode(',',array_fill(0,count($gameIds),'?')).')';
                $params=[$pid,...$gameIds];
            }
            // 타점(rbi)은 타석이 아니라 경기 단위로 적혀 있어 타석 스플릿에는 쓰지 않는다.
            $q=$pdo->prepare('SELECT '.($by==='order'?'`order`':'inning').' AS split_key,pa_result'.($by==='order'&&$side==='batter'?',sb,cs':'').' FROM kbo_season_records WHERE '.$where.($by==='order'?' AND `order` BETWEEN 1 AND 9':' AND inning>0').profileNotTiebreakerSql());
            $q->execute($params);
            // 도루·도루자는 타순별(타자)에서만 돌려준다. 타석 없이 주루만 한 기록에도 붙어 있어 타석이 없어도 센다.
            $steals=$by==='order'&&$side==='batter';
            $rows=[];
            while($row=$q->fetch(PDO::FETCH_ASSOC)) {
                $event=profileBatEvent($row);
                if(!$event['pa']&&!($steals&&($event['sb']||$event['cs'])))continue;
                $key=$by==='order'?(int)$row['split_key']:min((int)$row['split_key'],10);
                $rows[$key]??=['key'=>$key,'pa'=>0,'ab'=>0,'h'=>0,'tb'=>0,'bb'=>0,'hbp'=>0,'sf'=>0,'doubles'=>0,'triples'=>0,'hr'=>0,'so'=>0]+($steals?['sb'=>0,'cs'=>0]:[]);
                $rows[$key]['pa']+=$event['pa'];
                foreach(['ab','h','tb','bb','hbp','sf','doubles','triples','hr','so',...($steals?['sb','cs']:[])] as $stat)$rows[$key][$stat]+=(int)$event[$stat];
            }
            ksort($rows);
            return ['year'=>$year,'season'=>$season,'rows'=>array_values($rows)];
        });
        exit;
    }
    if($part==='ranks') {
        require_once __DIR__.'/../lib/player-rankings.php';
        $year=filter_var($_GET['year']??null,FILTER_VALIDATE_INT);
        if(!$year || (!isset(profileSchedule()[$year])&&($year<1982||$year>2000))) throw new RuntimeException('Invalid ranking year');
        $rankingLeague=filter_var($_GET['league']??1,FILTER_VALIDATE_INT);
        if(!in_array($rankingLeague,[1,2],true)){http_response_code(400);echo json_encode(['error'=>'잘못된 리그입니다.'],JSON_UNESCAPED_UNICODE);exit;}
        echo json_encode(['ranks'=>profileRankings($pdo,$year,profileSchedule(),(string)$player['IsKbodle']==='0',$rankingLeague)[(string)$pid]??[]],JSON_UNESCAPED_UNICODE);exit;
    }
    require_once __DIR__.'/../lib/player-overview-cache.php';
    $records=$part==='profile'?null:profileOverviewRecords($pdo,$player,profileSchedule());
    echo json_encode(['player'=>$player,'career'=>$career,'records'=>$records], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Player profile lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'선수 정보를 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
