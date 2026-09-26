<?php
function todayGamesExpiry(array $games, int $now): int {
    $zone = new DateTimeZone('Asia/Seoul');
    $date = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
    $expires = min($now + 1800, $date->modify('tomorrow')->setTime(0, 0)->getTimestamp());
    foreach ($games as $game) {
        $state = (string)($game['GAME_STATE_SC'] ?? '');
        if (in_array($state, ['3', '4'], true)) continue;
        if ($state !== '1') { $expires = min($expires, $now + 30); continue; }
        $time = $game['G_TM'] ?? '';
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $expires = min($expires, $now + 30); continue;
        }
        $start = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $time, $zone);
        $expires = min($expires, $start->getTimestamp() > $now ? $start->getTimestamp() : $now + 30);
    }
    return $expires;
}

function cachedTodayGames(string $key, callable $fetch, ?int $now = null, ?string $directory = null): array {
    $now = $now ?? time();
    $day = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Ymd');
    $directory = $directory ?? (getenv('WESIPER_GAMES_CACHE_DIR') ?: sys_get_temp_dir() . '/wesiper-today-games-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'php'));
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create games cache');
    $path = $directory . '/' . hash('sha256', $key) . '.json';
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock games cache');
    try {
        $cached = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (!is_array($cached) || ($cached['day'] ?? '') !== $day) $cached = null;
        $hasGames = $cached !== null && is_array($cached['games'] ?? null) && $now - ($cached['fetchedAt'] ?? 0) <= 21600;
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
            $entry['retryAt'] = $now + 30;
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
