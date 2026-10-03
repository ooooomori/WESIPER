<?php
// 오늘 끝난 정규시즌 경기 결과를, 새벽 크롤러가 kbo_schedule에 반영하기 전까지 팀 순위 계산에 쓰도록 보관한다.
// DB에는 쓰지 않는다: kbo_schedule의 점수는 규정타석 계산 등에도 쓰여서, 선수 기록보다 먼저 채우면 어긋난다.
require_once __DIR__ . '/today-games-cache.php';

const KBO_TODAY_GAMES_KEY = '1:0,1,3,4,5,7,9';
const KBO_TODAY_GAMES_SERIES = '0,1,3,4,5,7,9';
const KBO_LIVE_RESULT_KEEP_DAYS = 7;
const KBO_LIVE_RESULT_TEAMS = ['KIA', '삼성', 'LG', '두산', 'KT', 'SSG', '롯데', '한화', 'NC', '키움'];

function fetchKboGameList($leagueId, $seriesIds, $day)
{
    $postData = array('leId' => $leagueId, 'srId' => $seriesIds, 'date' => $day);
    $ch = curl_init('https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WESIPER/1.0; +https://wesiper.xyz)',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://www.koreabaseball.com',
            'Referer: https://www.koreabaseball.com/',
            'X-Requested-With: XMLHttpRequest',
        ),
    ));
    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false) throw new RuntimeException('KBO 경기 정보를 불러오지 못했습니다: ' . $curlError);
    if ($httpStatus < 200 || $httpStatus >= 300) throw new RuntimeException('KBO 서버가 HTTP ' . $httpStatus . ' 상태를 반환했습니다.');

    $response = json_decode($rawResponse, true);
    if (!is_array($response) || !isset($response['game']) || !is_array($response['game'])) {
        throw new RuntimeException('KBO 경기 정보의 응답 형식이 올바르지 않습니다.');
    }
    return $response['game'];
}

// KBO 경기 목록에서 종료된 정규시즌 경기만 kbo_schedule 행과 같은 모양으로 뽑는다. 키는 game_code(G_ID).
function liveResultsFromGames(array $games): array {
    $results = [];
    foreach ($games as $game) {
        if (!is_array($game) || (string)($game['SR_ID'] ?? '') !== '0' || (string)($game['GAME_STATE_SC'] ?? '') !== '3') continue;
        // 순위 결정전처럼 날짜 자리가 가상 값인 코드는 이 형식에 맞지 않아 빠진다.
        $code = (string)($game['G_ID'] ?? '');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})[A-Z]{4}\d$/D', $code, $date) || !checkdate((int)$date[2], (int)$date[3], (int)$date[1])) continue;
        if (preg_replace('/\D/', '', (string)($game['G_DT'] ?? '')) !== substr($code, 0, 8)) continue;
        $scores = [(string)($game['T_SCORE_CN'] ?? ''), (string)($game['B_SCORE_CN'] ?? '')];
        $teams = [trim((string)($game['AWAY_NM'] ?? '')), trim((string)($game['HOME_NM'] ?? ''))];
        // 순위표가 모르는 팀 이름은 계산을 깨뜨리므로 받지 않는다.
        if (!ctype_digit($scores[0]) || !ctype_digit($scores[1]) || array_diff($teams, KBO_LIVE_RESULT_TEAMS) || $teams[0] === $teams[1]) continue;
        $results[$code] = ['game_code' => $code, 'game_date' => "$date[1]-$date[2]-$date[3]", 'away_team' => $teams[0], 'home_team' => $teams[1],
            'away_score' => (int)$scores[0], 'home_score' => (int)$scores[1]];
    }
    return $results;
}

function liveResultsPath(?string $directory = null): string {
    $directory = $directory ?? todayGamesCacheDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create live results directory');
    return $directory . '/live-results.json';
}

function pendingLiveResults(?string $directory = null): array {
    $path = liveResultsPath($directory);
    $stored = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    return is_array($stored) ? array_filter($stored, 'is_array') : [];
}

// 종료 결과를 누적 저장한다. 자정이 지나 '오늘 경기' 목록이 바뀌어도 크롤러가 돌 때까지 결과가 남는다.
function recordLiveResults(array $results, ?int $now = null, ?string $directory = null): void {
    if (!$results) return;
    $path = liveResultsPath($directory);
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock live results');
    try {
        $stored = pendingLiveResults($directory);
        $cutoff = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('Asia/Seoul'))->modify('-' . KBO_LIVE_RESULT_KEEP_DAYS . ' days')->format('Y-m-d');
        $merged = array_filter(array_merge($stored, $results), static fn($row) => ($row['game_date'] ?? '') >= $cutoff);
        if ($merged == $stored) return;
        $temporary = tempnam(dirname($path), 'live-');
        if ($temporary === false) throw new RuntimeException('Cannot create live results snapshot');
        try {
            if (file_put_contents($temporary, json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot save live results');
            }
        } finally { if (is_file($temporary)) unlink($temporary); }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

// DB에 이미 점수가 있는 경기(크롤러 반영분)는 DB 값을 쓰고, 아직 없는 경기만 보관한 결과로 채운다.
// 같은 game_code는 한 번만 들어가므로 크롤러가 뒤늦게 반영해도 두 번 계산되지 않는다.
function mergeLiveResults(array $storedGames, array $pending, string $start, string $end): array {
    $scored = [];
    foreach ($storedGames as $game) {
        if (($game['away_score'] ?? null) !== null && ($game['home_score'] ?? null) !== null) $scored[(string)$game['game_code']] = true;
    }
    foreach ($pending as $result) {
        $code = (string)($result['game_code'] ?? '');
        $date = (string)($result['game_date'] ?? '');
        if ($code === '' || isset($scored[$code]) || $date < $start || $date > $end) continue;
        $storedGames = array_values(array_filter($storedGames, static fn($game) => (string)$game['game_code'] !== $code));
        $storedGames[] = $result;
        $scored[$code] = true;
    }
    // 연속 기록과 최근 5경기는 경기 순서를 따른다.
    usort($storedGames, static fn($a, $b) => [(string)$a['game_date'], (string)$a['game_code']] <=> [(string)$b['game_date'], (string)$b['game_code']]);
    return $storedGames;
}
