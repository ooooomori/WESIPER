<?php
/*
 * 오늘의 경기는 실시간 중계가 아니라 일정·결과만 보여준다. KBO에는 하루 몇 번만 묻는다.
 *  - 그날 첫 조회
 *  - 각 경기 시작 30분 전에 한 번(취소·선발 확인)
 *  - 각 경기 시작 3시간 뒤부터 그 경기가 끝나거나 취소될 때까지 30분 간격(최종 점수)
 * 경기 중에는 묻지 않는다.
 */
const TODAY_GAMES_PREGAME_CHECK = 1800;
const TODAY_GAMES_RESULT_DELAY = 10800;
const TODAY_GAMES_RESULT_INTERVAL = 1800;
// KBO 조회가 실패했을 때 다시 시도하기까지의 간격(초).
const TODAY_GAMES_RETRY = 300;

function todayGamesExpiry(array $games, int $now): int {
    $zone = new DateTimeZone('Asia/Seoul');
    $date = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
    $expires = $date->modify('tomorrow')->setTime(0, 0)->getTimestamp();
    foreach ($games as $game) {
        if (in_array((string)($game['GAME_STATE_SC'] ?? ''), ['3', '4'], true)) continue;
        $time = $game['G_TM'] ?? '';
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $expires = min($expires, $now + TODAY_GAMES_RESULT_INTERVAL); continue;
        }
        $start = (new DateTimeImmutable($date->format('Y-m-d') . ' ' . $time, $zone))->getTimestamp();
        if ($now < $start - TODAY_GAMES_PREGAME_CHECK) $next = $start - TODAY_GAMES_PREGAME_CHECK;
        elseif ($now < $start + TODAY_GAMES_RESULT_DELAY) $next = $start + TODAY_GAMES_RESULT_DELAY;
        else $next = $now + TODAY_GAMES_RESULT_INTERVAL;
        $expires = min($expires, $next);
    }
    return $expires;
}

/**
 * 화면에 내보낼 경기 상태. 진행 중인 경기는 끝났는지 알 수 없으므로 예정 경기처럼 시작 시각만 보여준다.
 * state: scheduled(예정·진행 중) | final(종료) | cancelled(취소). 점수는 종료된 경기에만 있다.
 */
function todayGamesPublicState(array $game): array {
    $state = (string)($game['GAME_STATE_SC'] ?? '');
    if ($state === '4') return ['state' => 'cancelled', 'code' => '4', 'status' => ($game['CANCEL_SC_NM'] ?? '') ?: '경기 취소', 'away_score' => '', 'home_score' => ''];
    if ($state === '3') return ['state' => 'final', 'code' => '3', 'status' => '종료', 'away_score' => (string)($game['T_SCORE_CN'] ?? ''), 'home_score' => (string)($game['B_SCORE_CN'] ?? '')];
    return ['state' => 'scheduled', 'code' => '1', 'status' => (string)($game['G_TM'] ?? ''), 'away_score' => '', 'home_score' => ''];
}

function todayGamesCacheDirectory(): string {
    return getenv('WESIPER_GAMES_CACHE_DIR') ?: sys_get_temp_dir() . '/wesiper-today-games-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'php');
}

function cachedTodayGames(string $key, callable $fetch, ?int $now = null, ?string $directory = null): array {
    $now = $now ?? time();
    $day = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Ymd');
    $directory = $directory ?? todayGamesCacheDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create games cache');
    $path = $directory . '/' . hash('sha256', $key) . '.json';
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock games cache');
    try {
        $cached = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (!is_array($cached) || ($cached['day'] ?? '') !== $day) $cached = null;
        $hasGames = $cached !== null && is_array($cached['games'] ?? null) && $now - ($cached['fetchedAt'] ?? 0) <= 86400;
        if (($cached['expiresAt'] ?? 0) > $now && $hasGames) return $cached;
        if (($cached['retryAt'] ?? 0) > $now) {
            if ($hasGames) return $cached;
            throw new RuntimeException('KBO retry temporarily delayed');
        }
        try {
            $games = $fetch($day);
            if (!is_array($games)) throw new RuntimeException('Invalid games response');
            foreach ($games as $game) {
                if (!is_array($game) || !isset($game['GAME_STATE_SC'])) throw new RuntimeException('Invalid game row');
            }
            $entry = ['day' => $day, 'games' => $games, 'fetchedAt' => $now,
                'expiresAt' => todayGamesExpiry($games, $now), 'stale' => false];
        } catch (Throwable $error) {
            $entry = $hasGames ? $cached : ['day' => $day, 'games' => null];
            $entry['retryAt'] = $now + TODAY_GAMES_RETRY;
            $entry['stale'] = true;
            error_log('KBO games refresh failed: ' . $error->getMessage());
        }
        $temporary = tempnam($directory, 'games-');
        if ($temporary === false) throw new RuntimeException('Cannot create cache snapshot');
        try {
            if (file_put_contents($temporary, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot save games cache');
            }
        } finally { if (is_file($temporary)) unlink($temporary); }
        if ($entry['games'] === null) throw new RuntimeException('KBO games temporarily unavailable');
        return $entry;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
