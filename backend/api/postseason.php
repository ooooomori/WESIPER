<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=60');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
require_once __DIR__ . '/../lib/today-games-cache.php';
require_once __DIR__ . '/../lib/kbo-live-results.php';

// 포스트시즌 경기 결과. KBO 시리즈 번호: 4 와일드카드 결정전, 3 준플레이오프, 5 플레이오프, 7 한국시리즈.
const POSTSEASON_SERIES = ['4' => 'wildcard', '3' => 'semi', '5' => 'playoff', '7' => 'final'];
// 월간 일정의 비고 칸("준PO 1차전")에서 시리즈를 가린다. 준PO가 PO보다 먼저 검사돼야 한다.
const POSTSEASON_LABELS = ['/^(WC|와일드카드)/u' => 'wildcard', '/^(준PO|준플레이오프)/u' => 'semi', '/^(PO|플레이오프)/u' => 'playoff', '/^(KS|한국시리즈)/u' => 'final'];
// KBO 일정 조회가 실패했을 때 다시 시도하기까지의 간격(초).
const POSTSEASON_RETRY = 300;

// KBO 월간 일정 한 달치. 행마다 날짜·시간·대진(점수)·구장·비고 칸이 HTML 조각으로 온다.
function fetchKboMonthSchedule(string $year, string $month): array {
    $ch = curl_init('https://www.koreabaseball.com/ws/Schedule.asmx/GetScheduleList');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['leId' => '1', 'srIdList' => '3,4,5,7', 'seasonId' => $year, 'gameMonth' => $month, 'teamId' => '']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WESIPER/1.0; +https://wesiper.xyz)',
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://www.koreabaseball.com', 'Referer: https://www.koreabaseball.com/Schedule/Schedule.aspx', 'X-Requested-With: XMLHttpRequest'],
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('KBO 일정을 불러오지 못했습니다: ' . $error);
    if ($status < 200 || $status >= 300) throw new RuntimeException("KBO 서버가 HTTP $status 상태를 반환했습니다.");
    $response = json_decode($raw, true);
    if (!is_array($response) || !is_array($response['rows'] ?? null)) throw new RuntimeException('KBO 일정의 응답 형식이 올바르지 않습니다.');
    return $response['rows'];
}

// 일정 행을 화면 경기 모양으로 바꾼다. 시리즈 비고가 없거나 팀이 아직 정해지지 않은 행("준PO 승리팀")은 뺀다.
function parsePostseasonRows(array $rows, string $year): array {
    $games = [];
    $date = '';
    foreach ($rows as $row) {
        $cells = array_map(static fn($cell) => (string)($cell['Text'] ?? ''), $row['row'] ?? []);
        // 같은 날 둘째 경기부터는 날짜 칸이 rowspan으로 빠진다.
        if (($row['row'][0]['Class'] ?? '') === 'day') {
            $day = array_shift($cells);
            $date = preg_match('/^(\d{2})\.(\d{2})/', $day, $m) ? $year . $m[1] . $m[2] : '';
        }
        if ($date === '' || count($cells) < 8 || !preg_match('#^<span>(.*?)</span><em>(.*?)</em><span>(.*?)</span>$#s', $cells[1], $play)) continue;
        $away = trim(strip_tags($play[1]));
        $home = trim(strip_tags($play[3]));
        if (!in_array($away, KBO_LIVE_RESULT_TEAMS, true) || !in_array($home, KBO_LIVE_RESULT_TEAMS, true)) continue;
        $label = trim(strip_tags(end($cells)));
        $series = null;
        foreach (POSTSEASON_LABELS as $pattern => $key) if (preg_match($pattern, $label)) { $series = $key; break; }
        if (!$series) continue;
        $scores = preg_match_all('#<span[^>]*>(\d+)</span>#', $play[2], $found) === 2 ? $found[1] : null;
        $state = $scores ? 'final' : (str_contains($label, '취소') ? 'cancelled' : 'scheduled');
        $games[] = ['series' => $series, 'id' => preg_match('/gameId=(\w+)/', implode(' ', $cells), $m) ? $m[1] : "$date$away$home", 'date' => $date,
            'time' => preg_match('/\d{1,2}:\d{2}/', $cells[0], $m) ? $m[0] : '', 'stadium' => trim(strip_tags($cells[6])), 'away' => $away, 'home' => $home, 'state' => $state,
            'awayScore' => $scores ? (int)$scores[0] : null, 'homeScore' => $scores ? (int)$scores[1] : null];
    }
    return $games;
}

// 포스트시즌 일정은 하루에 한 번(서울 날짜 기준) 첫 요청만 KBO에서 받아 보관하고, 나머지는 보관본을 쓴다.
// 받기에 실패하면 마지막으로 성공한 보관본을 계속 쓴다.
function postseasonSchedule(string $directory, string $year, int $now): array {
    $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Ymd');
    $path = "$directory/postseason-schedule.json";
    $read = static function() use ($path, $year): ?array {
        $stored = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        return is_array($stored) && ($stored['year'] ?? '') === $year && is_array($stored['games'] ?? null) ? $stored : null;
    };
    $cached = $read();
    if ($cached && ($cached['day'] ?? '') === $today) return $cached['games'];
    $lock = fopen("$path.lock", 'c');
    if (!$lock) throw new RuntimeException('Cannot open postseason lock');
    try {
        // 다른 요청이 받는 중이면 기다리지 않고 보관본을 쓴다. 보관본이 아직 없을 때만 기다린다.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            if ($cached) return $cached['games'];
            flock($lock, LOCK_EX);
        }
        $cached = $read();
        if ($cached && (($cached['day'] ?? '') === $today || ($cached['retryAt'] ?? 0) > $now)) return $cached['games'];
        try {
            $games = [];
            foreach (['10', '11'] as $month) $games = array_merge($games, parsePostseasonRows(fetchKboMonthSchedule($year, $month), $year));
            $entry = ['year' => $year, 'day' => $today, 'fetchedAt' => $now, 'games' => $games];
        } catch (Throwable $error) {
            error_log('Postseason schedule refresh failed: ' . $error->getMessage());
            if (!$cached) throw $error;
            $entry = $cached;
            $entry['retryAt'] = $now + POSTSEASON_RETRY;
        }
        $temporary = tempnam($directory, 'ps-');
        if ($temporary === false) throw new RuntimeException('Cannot create postseason snapshot');
        try {
            if (file_put_contents($temporary, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($temporary, $path)) throw new RuntimeException('Cannot save postseason schedule');
        } finally { if (is_file($temporary)) @unlink($temporary); }
        return $entry['games'];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

// 오늘 경기의 상태와 점수는 오늘의 경기 캐시에 이미 있는 것만 덮어쓴다. KBO를 새로 묻지 않는다.
function overlayTodayPostseason(array $games, string $day): array {
    $cache = readCachedTodayGames(KBO_TODAY_GAMES_KEY);
    foreach ($cache['games'] ?? [] as $live) {
        $series = POSTSEASON_SERIES[(string)($live['SR_ID'] ?? '')] ?? null;
        if (!$series) continue;
        $state = todayGamesPublicState($live);
        $final = $state['state'] === 'final';
        $fresh = ['series' => $series, 'id' => (string)($live['G_ID'] ?? ''), 'date' => $day, 'time' => (string)($live['G_TM'] ?? ''), 'stadium' => (string)($live['S_NM'] ?? ''),
            'away' => (string)($live['AWAY_NM'] ?? ''), 'home' => (string)($live['HOME_NM'] ?? ''), 'state' => $state['state'],
            'awayScore' => $final ? (int)$state['away_score'] : null, 'homeScore' => $final ? (int)$state['home_score'] : null];
        $found = false;
        foreach ($games as &$game) {
            if ($game['date'] !== $day || ($game['id'] !== $fresh['id'] && [$game['away'], $game['home']] !== [$fresh['away'], $fresh['home']])) continue;
            $game = array_merge($game, array_intersect_key($fresh, array_flip(['state', 'awayScore', 'homeScore'])));
            $found = true;
        }
        unset($game);
        if (!$found) $games[] = $fresh;
    }
    return $games;
}

try {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL unavailable');
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $directory = todayGamesCacheDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create cache directory');
    $year = $now->format('Y');
    $series = ['wildcard' => [], 'semi' => [], 'playoff' => [], 'final' => []];
    foreach (overlayTodayPostseason(postseasonSchedule($directory, $year, $now->getTimestamp()), $now->format('Ymd')) as $game) $series[$game['series']][] = $game;
    foreach ($series as &$games) usort($games, static fn($a, $b) => [$a['date'], $a['time'], $a['id']] <=> [$b['date'], $b['time'], $b['id']]);
    unset($games);
    echo json_encode(['code' => '100', 'year' => (int)$year, 'series' => $series], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Postseason: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '포스트시즌 정보를 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
