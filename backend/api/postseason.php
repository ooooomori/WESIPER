<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=60');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
require_once __DIR__ . '/../lib/today-games-cache.php';
require_once __DIR__ . '/../lib/kbo-live-results.php';

// 포스트시즌 경기 결과. KBO 시리즈 번호: 4 와일드카드 결정전, 3 준플레이오프, 5 플레이오프, 7 한국시리즈.
const POSTSEASON_SERIES = ['4' => 'wildcard', '3' => 'semi', '5' => 'playoff', '7' => 'final'];

// 지난 날짜는 다시 묻지 않고 보관한다. 오늘과 앞으로의 날짜는 1분만 재사용한다.
function postseasonDay(string $day, string $today, string $directory): array {
    $path = "$directory/postseason-$day.json";
    $cached = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (is_array($cached) && is_array($cached['games'] ?? null) && ($day < $today || time() - ($cached['fetchedAt'] ?? 0) < 60)) return $cached['games'];
    try {
        $games = fetchKboGameList('1', '3,4,5,7', $day);
    } catch (Throwable $error) {
        if (is_array($cached['games'] ?? null)) return $cached['games'];
        throw $error;
    }
    $rows = [];
    foreach ($games as $game) {
        $series = POSTSEASON_SERIES[(string)($game['SR_ID'] ?? '')] ?? null;
        if (!$series) continue;
        $state = todayGamesPublicState($game);
        $rows[] = ['series' => $series, 'id' => (string)($game['G_ID'] ?? ''), 'date' => $day, 'time' => (string)($game['G_TM'] ?? ''), 'stadium' => (string)($game['S_NM'] ?? ''),
            'away' => (string)($game['AWAY_NM'] ?? ''), 'home' => (string)($game['HOME_NM'] ?? ''), 'state' => $state['state'],
            'awayScore' => $state['state'] === 'final' ? (int)$state['away_score'] : null, 'homeScore' => $state['state'] === 'final' ? (int)$state['home_score'] : null];
    }
    $temporary = tempnam($directory, 'ps-');
    if ($temporary !== false) {
        if (@file_put_contents($temporary, json_encode(['fetchedAt' => time(), 'games' => $rows], JSON_UNESCAPED_UNICODE)) !== false) @rename($temporary, $path);
        if (is_file($temporary)) @unlink($temporary);
    }
    return $rows;
}

try {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL unavailable');
    $zone = new DateTimeZone('Asia/Seoul');
    $now = new DateTimeImmutable('now', $zone);
    $directory = todayGamesCacheDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create cache directory');
    $today = $now->format('Ymd');
    $year = $now->format('Y');
    $last = min($now->modify('+2 days'), new DateTimeImmutable("$year-12-15", $zone));
    $series = ['wildcard' => [], 'semi' => [], 'playoff' => [], 'final' => []];
    for ($day = new DateTimeImmutable("$year-10-01", $zone); $day <= $last; $day = $day->modify('+1 day')) {
        foreach (postseasonDay($day->format('Ymd'), $today, $directory) as $game) $series[$game['series']][] = $game;
    }
    foreach ($series as &$games) usort($games, static fn($a, $b) => [$a['date'], $a['time'], $a['id']] <=> [$b['date'], $b['time'], $b['id']]);
    unset($games);
    echo json_encode(['code' => '100', 'year' => (int)$year, 'series' => $series], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Postseason: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '포스트시즌 정보를 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
