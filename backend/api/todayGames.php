<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../lib/today-games-cache.php';
require_once __DIR__ . '/../lib/kbo-live-results.php';
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

// 퓨처스리그 조회 여부. 2026 시즌이 끝나 꺼 두었다. 다음 시즌이 시작되면 true로 바꾼다.
const TODAY_GAMES_FUTURES_ENABLED = false;

try {
    $requestTime = time();
    $kboCache = cachedTodayGames(KBO_TODAY_GAMES_KEY, fn($day) => fetchKboGameList('1', KBO_TODAY_GAMES_SERIES, $day), $requestTime);
    $futuresCache = TODAY_GAMES_FUTURES_ENABLED
        ? cachedTodayGames('2:0,1,9,10,15', fn($day) => fetchKboGameList('2', '0,1,9,10,15', $day), $requestTime)
        : array('games' => array(), 'stale' => false, 'fetchedAt' => null);
    $kboRawGames = $kboCache['games'];
    $futuresRawGames = $futuresCache['games'];
} catch (RuntimeException $error) {
    respond(array('success' => false, 'error' => $error->getMessage()), 502);
}
// 종료된 정규시즌 경기는 팀 순위에 바로 반영되도록 결과를 남긴다. 실패해도 경기 응답은 그대로 보낸다.
try { recordLiveResults(liveResultsFromGames($kboRawGames), $requestTime); }
catch (Throwable $error) { error_log('Live results not recorded: ' . $error->getMessage()); }

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
