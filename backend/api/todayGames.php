<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../lib/today-games-cache.php';
require_once __DIR__ . '/../lib/game-weather.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

date_default_timezone_set('Asia/Seoul');

function respond($result, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('curl_init')) {
    respond(array('success' => false, 'error' => '서버에서 cURL을 사용할 수 없습니다.'), 500);
}

function fetchGames($leagueId, $seriesIds, $day)
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

function normalizeGame($game, $weather = null)
{
    $inning = $game['GAME_INN_NO'] ?? null;
    $status = !empty($inning)
        ? $inning . '회' . ($game['GAME_TB_SC_NM'] ?? '')
        : ($game['G_TM'] ?? '');

    if (($game['GAME_STATE_SC'] ?? null) === '4') {
        $status = $game['CANCEL_SC_NM'] ?? '경기 취소';
    }

    return array_merge(array_intersect_key($game, array_flip(array(
        'GAME_STATE_SC', 'GAME_TB_SC', 'T_P_NM', 'B_P_NM',
        'T_PIT_P_NM', 'B_PIT_P_NM', 'W_PIT_P_NM', 'L_PIT_P_NM'
    ))), array(
        'away' => $game['AWAY_NM'] ?? '',
        'home' => $game['HOME_NM'] ?? '',
        'away_score' => $game['T_SCORE_CN'] ?? '',
        'home_score' => $game['B_SCORE_CN'] ?? '',
        'stadium' => $game['S_NM'] ?? '',
        'gameDate' => $game['G_DT'] ?? '',
        'gameStartTime' => $game['G_TM'] ?? '',
        'weather' => $weather,
        'status' => $status,
        'isGameFinished' => ($game['GAME_STATE_SC'] ?? null) === '3',
    ));
}

try {
    $requestTime = time();
    $kboCache = cachedTodayGames('1:0,1,3,4,5,7,9', fn($day) => fetchGames('1', '0,1,3,4,5,7,9', $day), $requestTime);
    $futuresCache = cachedTodayGames('2:0,1,9,10,15', fn($day) => fetchGames('2', '0,1,9,10,15', $day), $requestTime);
    $kboRawGames = $kboCache['games'];
    $futuresRawGames = $futuresCache['games'];
} catch (RuntimeException $error) {
    respond(array('success' => false, 'error' => $error->getMessage()), 502);
}

$weatherProvider = null;
try { $weatherProvider = new KmaGameWeather(kmaServiceKey()); }
catch (Throwable $error) { error_log('KMA configuration unavailable'); }
$normalizeWithWeather = function ($game) use ($weatherProvider) {
    try { $weather = $weatherProvider ? $weatherProvider->forGame($game) : null; }
    catch (Throwable $error) { $weather = null; }
    return normalizeGame($game, $weather);
};
$result = array(
    'success' => true,
    'date' => date('Y-m-d', $requestTime),
    'cache' => array('kbo' => array('stale' => $kboCache['stale'], 'fetchedAt' => $kboCache['fetchedAt']),
                     'futures' => array('stale' => $futuresCache['stale'], 'fetchedAt' => $futuresCache['fetchedAt'])),
    'isGameExist' => false,
    'kboGames' => array_map($normalizeWithWeather, $kboRawGames),
    'futuresGames' => array_map($normalizeWithWeather, $futuresRawGames),
);

// 기존 메인페이지가 사용하던 SSG 단일 경기 응답도 계속 제공한다.
foreach ($kboRawGames as $game) {
    if (($game['AWAY_ID'] ?? null) !== 'SK' && ($game['HOME_ID'] ?? null) !== 'SK') continue;
    $result['isGameExist'] = true;
    $result['game'] = normalizeGame($game);
    break;
}

respond($result);
