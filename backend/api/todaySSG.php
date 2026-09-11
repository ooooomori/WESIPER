<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

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

$postData = array(
    'leId' => '1',
    'srId' => '0,1,3,4,5,7,9',
    'date' => date('Ymd'),
);

$url = 'https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList';
$ch = curl_init($url);

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

if ($rawResponse === false) {
    respond(array('success' => false, 'error' => 'KBO 경기 정보를 불러오지 못했습니다: ' . $curlError), 502);
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    respond(array('success' => false, 'error' => 'KBO 서버가 HTTP ' . $httpStatus . ' 상태를 반환했습니다.'), 502);
}

$response = json_decode($rawResponse, true);
if (!is_array($response) || !isset($response['game']) || !is_array($response['game'])) {
    respond(array('success' => false, 'error' => 'KBO 경기 정보의 응답 형식이 올바르지 않습니다.'), 502);
}

$result = array('success' => true, 'isGameExist' => false);

foreach ($response['game'] as $game) {
    if (($game['AWAY_ID'] ?? null) !== 'SK' && ($game['HOME_ID'] ?? null) !== 'SK') {
        continue;
    }

    $inning = $game['GAME_INN_NO'] ?? null;
    $status = !empty($inning)
        ? $inning . '회' . ($game['GAME_TB_SC_NM'] ?? '')
        : ($game['G_TM'] ?? '');

    if (($game['GAME_STATE_SC'] ?? null) === '4') {
        $status = $game['CANCEL_SC_NM'] ?? '경기 취소';
    }

    $result['isGameExist'] = true;
    $result['game'] = array(
        'away' => $game['AWAY_NM'] ?? '',
        'home' => $game['HOME_NM'] ?? '',
        'away_score' => $game['T_SCORE_CN'] ?? '',
        'home_score' => $game['B_SCORE_CN'] ?? '',
        'stadium' => $game['S_NM'] ?? '',
        'status' => $status,
        'isGameFinished' => ($game['GAME_STATE_SC'] ?? null) === '3',
    );
    break;
}

respond($result);
