<?php
header('Content-Type: application/json; charset=utf-8');
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
        if (is_string($player[$field])) $player[$field] = trim($player[$field]) ?: null;
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
        $movementColumns = 'event_date AS date, event_type AS type, team, note, old_back_no AS oldBackNo, new_back_no AS newBackNo';
        $contractColumns = ', contract_years AS contractYears, contract_term AS contractTerm, contract_total_amount AS contractTotal,
            contract_registered_amount AS contractRegistered, contract_currency AS contractCurrency, contract_details AS contractDetails, contract_source_url AS contractSources';
        $movementSql = static fn(string $columns) => "SELECT $columns FROM kbo_player_movements WHERE player_id=? ORDER BY event_date DESC, source_page, source_row, id";
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
    $stmt = $pdo->prepare('SELECT category,type,team,year,month,pos,note FROM kbo_player_career WHERE player_id=:pid ORDER BY year,month,category,type,PK');
    $stmt->execute(['pid'=>$pid]);
    $career=$stmt->fetchAll(PDO::FETCH_ASSOC);
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
        if($type!=='pitcher')$yearRecords['fielding']=profileFieldingRecords($pdo,(string)$pid,$schedule);
        if($type==='all'){foreach(['preseason','postseason','futures'] as $season)$yearRecords['seasons'][$season]=['batter'=>profileYearRecords($pdo,(string)$pid,false,$schedule,$season),'pitcher'=>profileYearRecords($pdo,(string)$pid,true,$schedule,$season)];}
        echo json_encode($yearRecords,JSON_UNESCAPED_UNICODE);exit;
    }
    if($part==='games') {
        $schedule=profileSchedule();
        require_once __DIR__.'/../lib/player-game-seasons.php';
        $availableSeasons=profilePlayerGameSeasons($pdo,$player,$schedule);
        $years=array_keys($availableSeasons);
        $year=isset($_GET['year'])?filter_var($_GET['year'],FILTER_VALIDATE_INT):($years[0]??null);
        $season=$_GET['season']??($availableSeasons[$year][0]??'regular');
        if(!in_array($season,['regular','preseason','postseason','futures'],true)||($year!==null&&(!in_array($year,$years,true)||!in_array($season,$availableSeasons[$year],true)))) {
            http_response_code(400);echo json_encode(['error'=>'잘못된 경기 조회 조건입니다.'],JSON_UNESCAPED_UNICODE);exit;
        }
        $record=$year!==null?profileRecords($pdo,$player,$schedule,$year,$season):null;
        require_once __DIR__.'/../lib/player-streaks.php';
        echo json_encode(['years'=>$years,'year'=>$year,'season'=>$season,'availableSeasons'=>(object)$availableSeasons,'games'=>$record['games']??[],'pitcher'=>str_contains((string)$player['Pos'],'투수'),'currentSeasonStreaks'=>str_contains((string)$player['Pos'],'투수')?null:profileCurrentStreaks($pdo,(string)$pid,$schedule)],JSON_UNESCAPED_UNICODE);exit;
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
