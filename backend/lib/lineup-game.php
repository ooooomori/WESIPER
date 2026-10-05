<?php
declare(strict_types=1);
require_once __DIR__ . '/player-records.php';

// 라인업 맞추기: 정규시즌 한 경기의 선발 타순을 kbo_season_records에서 복원한다.
// 타석 단위 기록이라 is_gs=1인 행을 타순(order)별로 묶으면 선발 9명이 나온다.

const LINEUP_DIFFICULTIES = ['easy', 'normal', 'hard', 'extreme'];
const LINEUP_FIRST_YEAR = 2001;
// 구단: 고유 id => [시기별 팀명(일정·기록 테이블에 저장된 이름), 첫 해, 마지막 해(null = 현재)]
const LINEUP_TEAMS = [
    'kia' => [['KIA'], 2001, null],
    'sam' => [['삼성'], 2001, null],
    'lg' => [['LG'], 2001, null],
    'doo' => [['두산'], 2001, null],
    'lot' => [['롯데'], 2001, null],
    'han' => [['한화'], 2001, null],
    'ssg' => [['SK', 'SSG'], 2001, null],
    'kiw' => [['히어로즈', '넥센', '키움'], 2008, null],
    'nc' => [['NC'], 2013, null],
    'kt' => [['KT'], 2015, null],
    'hyd' => [['현대'], 2001, 2007],
];
// 선발 포지션은 pos의 첫 글자다("좌중"은 좌익수로 나와 중견수로 옮긴 선수).
const LINEUP_POSITIONS = ['포' => 'C', '一' => '1B', '二' => '2B', '三' => '3B', '유' => 'SS', '좌' => 'LF', '중' => 'CF', '우' => 'RF', '지' => 'DH', '투' => 'P'];

function lineupLastYear(array $schedule): int
{
    return max(array_map('intval', array_keys($schedule)));
}

// 기록지 표기(유땅, 좌중2, 3희번 …)를 타석 결과의 종류로 바꾼다. 앞의 글자는 타구 방향·수비 위치라서 뒤쪽만 본다.
function lineupResultLabel(string $result): string
{
    $result = trim($result);
    $exact = ['삼진' => '삼진', '스낫' => '낫아웃', '4구' => '볼넷', '고4' => '고의4구', '사구' => '사구', '타방' => '타격방해', '야선' => '야수선택', '아웃' => '아웃'];
    if (isset($exact[$result])) return $exact[$result];
    if (str_contains($result, '삼중')) return '삼중살';
    // 긴 표기부터 본다: '희비'는 '비'(뜬공)보다, '희번'은 '번'(번트)보다 먼저.
    $suffixes = ['희비' => '희생플라이', '희번' => '희생번트', '희실' => '실책', '희선' => '야수선택', '병' => '병살', '직' => '직선타', '파' => '파울플라이',
        '땅' => '땅볼', '비' => '뜬공', '번' => '번트', '실' => '실책', '홈' => '홈런', '안' => '1루타', '2' => '2루타', '3' => '3루타'];
    // 스리번트 아웃 등 '삼'으로 시작하는 나머지는 삼진이다.
    if (str_starts_with($result, '삼')) return '삼진';
    foreach ($suffixes as $suffix => $label) if (str_ends_with($result, (string)$suffix)) return $label; // 숫자 키(2, 3)는 PHP가 정수로 바꿔 둔다.
    return $result;
}

// 경기 코드의 13번째 글자: 0 = 그날 한 경기, 1·2 = 더블헤더 1·2차전
function lineupGameNumber(string $code): int
{
    return (int)substr($code, 12, 1);
}

// 일정 테이블은 'kt', 기록 테이블은 'KT'로 적는다.
function lineupSameTeam(?string $a, ?string $b): bool
{
    return $a !== null && $b !== null && strtoupper($a) === strtoupper($b);
}

// 기록의 선수명은 네 글자에서 잘려 있거나(페르난데) 개명 전 이름이다(한동민). 잘린 이름만 현재 이름으로 바꾼다.
function lineupDisplayName(?string $recordName, ?string $currentName): string
{
    $recordName = trim((string)$recordName);
    $currentName = trim((string)$currentName);
    if ($recordName === '' || ($currentName !== '' && str_starts_with($currentName, $recordName))) return $currentName !== '' ? $currentName : $recordName;
    return $recordName;
}

/** 조건에 맞는 정규시즌 경기 하나를 무작위로 고른다. 연도가 null이면 연도부터 고르게 뽑는다. */
function lineupRandomGame(PDO $db, array $schedule, ?int $year, ?string $teamId): ?array
{
    $last = lineupLastYear($schedule);
    $team = $teamId === null ? null : LINEUP_TEAMS[$teamId];
    $from = max(LINEUP_FIRST_YEAR, $team[1] ?? LINEUP_FIRST_YEAR);
    $to = min($last, $team[2] ?? $last);
    if ($year === null) $year = random_int($from, $to);
    if ($year < $from || $year > $to) return null;
    [$start, $end] = $schedule[(string)$year]['regular'] ?? ['', ''];
    if (!$start || !$end) return null;

    // 순위 결정전·포스트시즌은 game_code가 연도로 시작하지 않는다(6666…, 7777…).
    $sql = 'SELECT game_code,game_date,away_team,home_team,away_score,home_score,stadium FROM kbo_schedule'
        . ' WHERE league_level=1 AND is_allstar=0 AND game_date BETWEEN ? AND ? AND LEFT(game_code,4)=?'
        . ' AND away_score IS NOT NULL AND home_score IS NOT NULL';
    $params = [$start, $end, (string)$year];
    if ($team !== null) {
        $marks = implode(',', array_fill(0, count($team[0]), '?'));
        $sql .= " AND (away_team IN ($marks) OR home_team IN ($marks))";
        $params = [...$params, ...$team[0], ...$team[0]];
    }
    $query = $db->prepare($sql . ' ORDER BY RAND() LIMIT 1');
    $query->execute($params);
    $game = $query->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($game === null) return null;

    $isHome = $team === null ? random_int(0, 1) === 1 : in_array(strtoupper($game['home_team']), array_map('strtoupper', $team[0]), true);
    $game['team'] = $isHome ? $game['home_team'] : $game['away_team'];
    return $game;
}

function lineupGameByCode(PDO $db, string $code): ?array
{
    $query = $db->prepare('SELECT game_code,game_date,away_team,home_team,away_score,home_score,stadium FROM kbo_schedule WHERE league_level=1 AND game_code=?');
    $query->execute([$code]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/*
 * 오늘의 라인업: 하루에 한 문제, 모두에게 같은 문제. 가장 최근 시즌의 정규시즌 경기 중 하나를 날짜로 정한다(팀은 무작위, 난이도는 보통).
 * 따로 저장하지 않고 날짜에서 계산한다. 하루 동안 문제가 바뀌지 않도록, 기록이 이미 다 들어온 그제까지의 경기만 대상으로 삼는다.
 */
const LINEUP_DAILY_DIFFICULTY = 'normal';
// 1번 문제가 나간 날
const LINEUP_DAILY_FIRST_DATE = '2026-10-05';

function lineupDailyNumber(string $today): int
{
    return (int)round((strtotime($today) - strtotime(LINEUP_DAILY_FIRST_DATE)) / 86400) + 1;
}

/** 오늘의 경기와 그 팀의 라인업 [game, lineup]. 대상 경기가 없으면 null. */
function lineupDailyGame(PDO $db, array $schedule, string $today): ?array
{
    $limit = date('Y-m-d', strtotime($today . ' -2 days'));
    $seed = crc32('lineup-daily|' . $today);
    krsort($schedule);
    // 새 시즌이 막 시작해 대상 경기가 아직 없으면 지난 시즌에서 고른다.
    foreach ($schedule as $year => $seasons) {
        [$start, $end] = $seasons['regular'] ?? ['', ''];
        $end = min($end, $limit);
        if (!$start || $end < $start) continue;
        $query = $db->prepare('SELECT game_code,game_date,away_team,home_team,away_score,home_score,stadium FROM kbo_schedule'
            . ' WHERE league_level=1 AND is_allstar=0 AND game_date BETWEEN ? AND ? AND LEFT(game_code,4)=?'
            . ' AND away_score IS NOT NULL AND home_score IS NOT NULL ORDER BY game_code');
        $query->execute([$start, $end, (string)$year]);
        $games = $query->fetchAll(PDO::FETCH_ASSOC);
        // 뽑힌 경기의 기록이 비어 있으면 그다음 경기로 넘어간다(누구에게나 같은 순서).
        for ($step = 0; $step < min(5, count($games)); $step++) {
            $game = $games[($seed + $step) % count($games)];
            $game['team'] = $game[(($seed >> 16) & 1) ? 'home_team' : 'away_team'];
            $lineup = lineupForTeam($db, $game, $game['team']);
            if ($lineup !== null) return [$game, $lineup];
        }
        if ($games) return null;
    }
    return null;
}

/**
 * 한 팀의 선발 9명(그날의 타석 결과 포함)과 교체 출전 타자, 양 팀 선발투수.
 * 선발이 정확히 9명(1~9번)으로 복원되지 않으면 null (기록이 아직 들어오지 않은 경기 등).
 */
function lineupForTeam(PDO $db, array $game, string $team): ?array
{
    // 기록의 game_id는 일정의 game_code 그대로이거나 뒤에 연도가 붙는다.
    $ids = [$game['game_code'], $game['game_code'] . substr($game['game_date'], 0, 4)];
    // 타석마다 한 행이다. 타석 순서(batting_index)대로 읽어 선수별로 묶는다.
    $query = $db->prepare('SELECT r.team,r.player_id,r.player_name,r.pos,r.`order`,r.is_gs,r.pa_result,d.name,d.oldname,d.fullname,d.birth,d.bat'
        . ' FROM kbo_season_records r LEFT JOIN kbo_player_data d ON d.player_id=r.player_id'
        . ' WHERE r.league_level=1 AND r.game_id IN (?,?) ORDER BY r.batting_index');
    $query->execute($ids);
    $starters = [];
    $subs = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!lineupSameTeam($row['team'], $team)) continue;
        $id = (int)$row['player_id'];
        $player = [
            'id' => $id,
            'name' => lineupDisplayName($row['player_name'], $row['name']),
            // 이름 입력으로 찾을 때 함께 맞춰 보는 이름들: 기록의 이름, 현재 이름, 개명 전 이름, 풀네임
            'names' => array_values(array_unique(array_filter([$row['player_name'], $row['name'], $row['oldname'], $row['fullname']]))),
            'birthYear' => preg_match('/^(\d{4})/', (string)$row['birth'], $match) ? (int)$match[1] : null,
            // 타석: 우타 R, 좌타 L, 양타(스위치) S
            'bat' => ['우타' => 'R', '좌타' => 'L', '양타' => 'S'][$row['bat']] ?? null,
        ];
        if ((int)$row['is_gs'] !== 1) {
            $subs[$id] = $player;
            continue;
        }
        $order = (int)$row['order'];
        if ($order < 1 || $order > 9) return null;
        $starters[$order] ??= $player + ['order' => $order, 'pos' => LINEUP_POSITIONS[mb_substr((string)$row['pos'], 0, 1, 'UTF-8')] ?? null, 'records' => []];
        if ($starters[$order]['id'] !== $id) return null;
        // 대주자처럼 타석이 없는 출장 기록은 pa_result가 NULL이다.
        if (trim((string)$row['pa_result']) !== '') $starters[$order]['records'][] = lineupResultLabel((string)$row['pa_result']);
    }
    if (count($starters) !== 9) return null;
    ksort($starters);
    // 선발로 나온 선수가 교체 명단에 다시 잡히지 않게 한다.
    foreach ($starters as $starter) unset($subs[$starter['id']]);

    $query = $db->prepare('SELECT p.team,d.name FROM kbo_season_pitch_records p LEFT JOIN kbo_player_data d ON d.player_id=p.player_id'
        . ' WHERE p.league_level=1 AND p.game_id IN (?,?) AND p.`order`=1');
    $query->execute($ids);
    $pitchers = ['starter' => null, 'opponentStarter' => null];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $pitchers[lineupSameTeam($row['team'], $team) ? 'starter' : 'opponentStarter'] = $row['name'];

    return ['starters' => array_values($starters), 'subs' => array_values($subs)] + $pitchers;
}

/** 선수들의 별명 (player_id => 별명 목록). 메인 페이지의 선수 검색이 쓰는 kbo_player_nicknames를 함께 쓴다. */
function lineupPlayerNicknames(PDO $db, array $ids): array
{
    if (!$ids) return [];
    $query = $db->prepare('SELECT player_id,nickname FROM kbo_player_nicknames WHERE player_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $query->execute(array_values($ids));
    $nicknames = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $nicknames[(int)$row['player_id']][] = $row['nickname'];
    return $nicknames;
}

/**
 * 선발 타자들의 그 경기 직전까지의 정규시즌 누적 타율·OPS (player_id => ['avg' => '0.312', 'ops' => '0.845']).
 * 전광판처럼 경기 전 기록이라 그 경기의 타석은 넣지 않는다. 더블헤더 2차전이면 같은 날 1차전까지는 넣는다.
 * 시즌 첫 경기처럼 앞선 타수가 없으면 null.
 */
function lineupSeasonStats(PDO $db, array $schedule, array $game, array $starters): array
{
    $ids = array_column($starters, 'id');
    $start = $schedule[substr($game['game_date'], 0, 4)]['regular'][0] ?? '';
    if (!$ids || !$start) return [];
    $query = $db->prepare('SELECT player_id,pa_result,game_id,game_date FROM kbo_season_records WHERE league_level=1 AND player_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        . ' AND game_date BETWEEN ? AND ?' . profileNotTiebreakerSql());
    $query->execute([...$ids, $start, $game['game_date']]);
    $number = lineupGameNumber($game['game_code']);
    $keys = ['ab', 'h', 'tb', 'bb', 'hbp', 'sf'];
    $totals = array_fill_keys($ids, array_fill_keys($keys, 0));
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // 같은 날 기록은 더블헤더의 앞 경기 것만 넣는다.
        if ($row['game_date'] === $game['game_date'] && lineupGameNumber((string)$row['game_id']) >= $number) continue;
        $event = profileBatEvent($row);
        foreach ($keys as $key) $totals[(int)$row['player_id']][$key] += $event[$key];
    }
    return array_map(static function ($total) {
        $onBaseChances = $total['ab'] + $total['bb'] + $total['hbp'] + $total['sf'];
        if ($total['ab'] === 0) return ['avg' => null, 'ops' => null];
        return [
            'avg' => number_format($total['h'] / $total['ab'], 3),
            'ops' => number_format(($total['h'] + $total['bb'] + $total['hbp']) / $onBaseChances + $total['tb'] / $total['ab'], 3),
        ];
    }, $totals);
}

/**
 * 제출한 9칸을 채점한다. correct = 그 타순의 선수, present = 선발이지만 다른 타순, absent = 선발이 아님.
 * 선수는 id로만 가린다. 이름이 같아도 다른 선수면 다른 선수다(2010년 LG의 두 이병규처럼 한 명만 선발인 경우가 있다).
 */
function lineupGrade(array $starters, array $picks): array
{
    $orders = array_flip(array_column($starters, 'id'));
    $results = [];
    foreach ($starters as $index => $starter) {
        $pick = $picks[$index] ?? null;
        $results[] = $pick === null ? null : ($starter['id'] === $pick ? 'correct' : (isset($orders[$pick]) ? 'present' : 'absent'));
    }
    return $results;
}

/** 정규시즌 경기인지(포스트시즌·순위 결정전은 game_code가 연도로 시작하지 않고, 시범경기는 기간 밖이다). */
function lineupIsRegularGame(array $schedule, array $game): bool
{
    $year = substr($game['game_date'], 0, 4);
    [$start, $end] = $schedule[$year]['regular'] ?? ['', ''];
    return $start && $end && substr($game['game_code'], 0, 4) === $year && $game['game_date'] >= $start && $game['game_date'] <= $end;
}

/*
 * 문제(경기·팀·난이도)별 결과 통계. 테이블은 deploy/migrate-lineup-results.php로 만든다.
 * 통계는 부가 기능이라, 테이블이 아직 없거나 저장에 실패해도 게임은 그대로 진행한다.
 */

/** 한 판의 결과를 남긴다. 같은 사람이 같은 문제를 다시 풀어도 처음 결과만 남는다. */
function lineupRecordResult(PDO $db, string $code, string $side, string $difficulty, string $client, bool $solved, int $attempts, ?int $milliseconds): void
{
    if (!preg_match('/^[0-9a-f]{32}$/D', $client) || !in_array($difficulty, LINEUP_DIFFICULTIES, true)) return;
    try {
        $query = $db->prepare('INSERT IGNORE INTO lineup_results (game_code,side,difficulty,client_id,solved,attempts,milliseconds) VALUES (?,?,?,?,?,?,?)');
        // 횟수·시간은 브라우저가 알려준 값이라 말이 되는 범위로만 받는다.
        $query->execute([$code, $side, $difficulty, hex2bin($client), (int)$solved, max(0, min(999, $attempts)), $milliseconds === null ? null : max(0, min(86400000, $milliseconds))]);
    } catch (PDOException $error) {
        error_log('Lineup result not recorded: ' . $error->getMessage());
    }
}

/** 이 문제를 끝낸 사람 수·맞힌 사람 수와, 맞힌 사람들의 평균 제출 횟수·평균 시간(밀리초). 통계를 낼 수 없으면 null. */
function lineupPuzzleStats(PDO $db, string $code, string $side, string $difficulty): ?array
{
    try {
        $query = $db->prepare('SELECT COUNT(*) played, COALESCE(SUM(solved),0) solved, AVG(CASE WHEN solved=1 THEN attempts END) attempts, AVG(CASE WHEN solved=1 THEN milliseconds END) milliseconds'
            . ' FROM lineup_results WHERE game_code=? AND side=? AND difficulty=?');
        $query->execute([$code, $side, $difficulty]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        error_log('Lineup stats unavailable: ' . $error->getMessage());
        return null;
    }
    return [
        'played' => (int)$row['played'],
        'solved' => (int)$row['solved'],
        'averageAttempts' => $row['attempts'] === null ? null : round((float)$row['attempts'], 1),
        'averageMilliseconds' => $row['milliseconds'] === null ? null : (int)round((float)$row['milliseconds']),
    ];
}

/*
 * 오늘의 라인업 랭킹. 테이블은 deploy/migrate-lineup-results.php로 만든다.
 * 하루에 한 사람(브라우저 식별자)당 처음 결과 하나만 남긴다. 순위는 맞힌 사람끼리 제출 횟수가 적은 순, 같으면 빨리 푼 순이다.
 */
function lineupRecordDaily(PDO $db, string $date, string $client, bool $solved, int $attempts, ?int $milliseconds): void
{
    if (!preg_match('/^[0-9a-f]{32}$/D', $client)) return;
    try {
        $query = $db->prepare('INSERT IGNORE INTO lineup_daily_results (daily_date,client_id,solved,attempts,milliseconds) VALUES (?,?,?,?,?)');
        $query->execute([$date, hex2bin($client), (int)$solved, max(0, min(999, $attempts)), $milliseconds === null ? null : max(0, min(86400000, $milliseconds))]);
    } catch (PDOException $error) {
        error_log('Lineup daily result not recorded: ' . $error->getMessage());
    }
}

/**
 * 닉네임은 크보빙고와 같이 쓴다(kbobingo_nickname). 식별자는 빙고의 uuid에서 줄표를 뺀 16바이트라, 다시 줄표를 넣어 찾는다.
 * 닉네임을 정하지 않은 사람은 빙고처럼 식별자 앞 8자로 보여준다. (16바이트 식별자 => 닉네임)
 */
function lineupNicknames(PDO $db, array $clients): array
{
    $uuids = [];
    foreach (array_unique($clients) as $client) {
        $hex = bin2hex($client);
        $uuids[$client] = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
    if (!$uuids) return [];
    $query = $db->prepare('SELECT user_id,nickname FROM kbobingo_nickname WHERE user_id IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')');
    $query->execute(array_values($uuids));
    $saved = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $saved[strtolower($row['user_id'])] = $row['nickname'];
    return array_map(static fn($uuid) => $saved[$uuid] ?? substr($uuid, 0, 8), $uuids);
}

/** 그날의 참가자 수·정답자 수, 상위 10명(닉네임 포함), 내 순위와 닉네임. 랭킹을 낼 수 없으면 null. */
function lineupDailyRanking(PDO $db, string $date, string $client): ?array
{
    $mine = preg_match('/^[0-9a-f]{32}$/D', $client) ? hex2bin($client) : null;
    try {
        $query = $db->prepare('SELECT COUNT(*) played, COALESCE(SUM(solved),0) solved FROM lineup_daily_results WHERE daily_date=?');
        $query->execute([$date]);
        $counts = $query->fetch(PDO::FETCH_ASSOC);
        $query = $db->prepare('SELECT client_id,attempts,milliseconds FROM lineup_daily_results WHERE daily_date=? AND solved=1 ORDER BY attempts,milliseconds,created_at LIMIT 10');
        $query->execute([$date]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $nicknames = lineupNicknames($db, [...array_column($rows, 'client_id'), ...($mine === null ? [] : [$mine])]);
        $top = [];
        foreach ($rows as $index => $row) {
            $top[] = ['rank' => $index + 1, 'nickname' => $nicknames[$row['client_id']], 'attempts' => (int)$row['attempts'],
                'milliseconds' => $row['milliseconds'] === null ? null : (int)$row['milliseconds'], 'me' => $row['client_id'] === $mine];
        }
        $me = null;
        if ($mine !== null) {
            $query = $db->prepare('SELECT solved,attempts,milliseconds,created_at FROM lineup_daily_results WHERE daily_date=? AND client_id=?');
            $query->execute([$date, $mine]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['solved'] === 1) {
                // 위 목록과 같은 순서로, 나보다 앞선 사람 수를 센다.
                $query = $db->prepare('SELECT COUNT(*) FROM lineup_daily_results WHERE daily_date=? AND solved=1 AND (attempts<? OR (attempts=? AND (milliseconds<? OR (milliseconds=? AND created_at<?))))');
                $query->execute([$date, $row['attempts'], $row['attempts'], $row['milliseconds'], $row['milliseconds'], $row['created_at']]);
                $me = ['rank' => (int)$query->fetchColumn() + 1, 'nickname' => $nicknames[$mine], 'attempts' => (int)$row['attempts'], 'milliseconds' => $row['milliseconds'] === null ? null : (int)$row['milliseconds']];
            }
        }
    } catch (PDOException $error) {
        error_log('Lineup daily ranking unavailable: ' . $error->getMessage());
        return null;
    }
    return ['date' => $date, 'played' => (int)$counts['played'], 'solved' => (int)$counts['solved'], 'top' => $top, 'me' => $me, 'nickname' => $mine === null ? null : $nicknames[$mine]];
}
