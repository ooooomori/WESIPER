<?php
// Dates follow common.php's Asia/Seoul timezone. No cron or manual reset needed.
// Payloads are built from the production DB (backend/lib/bingo-player.php) and stored as
// {"key": "<source>|<history version>|<year>|<player record markers>|<profile hash>", "player": {...}|null}.
// New records or a changed profile for that player, a new source/history version, a new year,
// an entry older than BINGO_CACHE_MAX_AGE_DAYS, or a legacy payload triggers a rebuild.
function bingoCacheEnvelope($row) {
    $envelope = $row ? json_decode($row['payload'] ?? 'null', true) : null;
    return is_array($envelope) && isset($envelope['key']) && array_key_exists('player', $envelope) ? $envelope : null;
}

// How long an unchanged player may be served before a rebuild, as a safety net for
// corrections that leave the per-player markers below untouched (e.g. a backfill of old games).
const BINGO_CACHE_MAX_AGE_DAYS = 7;

/**
 * Cache key for one player: changes only when something bingoBuildPlayer() reads for that player changes.
 * The previous key (crawler revision + date) rebuilt every player on the first search of each day,
 * which made the first search for a name take about a second.
 * @param array $row kbo_player_data row selected by search.php
 */
function bingoPlayerCacheKey($con, array $row, string $version): string {
    $playerId = (int)$row['player_id'];
    // Row counts change while a crawl is still loading, so a half-loaded night is never kept as final.
    $stmt = $con->prepare("SELECT CONCAT_WS('|',
        (SELECT CONCAT(COUNT(*), '@', COALESCE(MAX(game_date), '')) FROM kbo_season_records WHERE league_level IN (1, 2) AND player_id = ?),
        (SELECT CONCAT(COUNT(*), '@', COALESCE(MAX(game_date), '')) FROM kbo_season_pitch_records WHERE league_level IN (1, 2) AND player_id = ?),
        (SELECT COUNT(*) FROM kbo_fielding_records WHERE player_id = ?)) AS marker");
    $stmt->bind_param('iii', $playerId, $playerId, $playerId);
    $stmt->execute();
    $marker = (string)$stmt->get_result()->fetch_assoc()['marker'];
    $stmt->close();
    $profile = [];
    foreach (['pos', 'draft', 'backNo', 'bat', 'throw', 'team', 'is_kbodle', 'retire'] as $field) $profile[] = (string)($row[$field] ?? '');
    return $version . '|' . $marker . '|' . hash('crc32b', implode("\x1f", $profile));
}

function cachedBingoPlayer($con, $playerId, $build, $key) {
    $playerId = (int)$playerId;
    $today = date('Y-m-d');
    $oldest = date('Y-m-d', strtotime('-' . BINGO_CACHE_MAX_AGE_DAYS . ' days'));
    $read = function () use ($con, $playerId, $oldest) {
        $stmt = $con->prepare('SELECT payload, fetched_date FROM kbobingo_player_cache WHERE p_no = ?');
        $stmt->bind_param('i', $playerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $envelope = bingoCacheEnvelope($row);
        // An entry past the safety-net age keeps its payload as a fallback but no longer matches any key.
        if ($envelope && (string)($row['fetched_date'] ?? '') < $oldest) $envelope['key'] = null;
        return $envelope;
    };
    $cached = $read();
    if ($cached && $cached['key'] === $key) return $cached['player'];
    $lock = 'kbobingo-player-' . $playerId;
    $stmt = $con->prepare('SELECT GET_LOCK(?, 30) AS acquired');
    $stmt->bind_param('s', $lock);
    $stmt->execute();
    $acquired = (int)$stmt->get_result()->fetch_assoc()['acquired'] === 1;
    $stmt->close();
    if (!$acquired) return $cached ? $cached['player'] : null;
    try {
        // Another request may already have refreshed this player while we waited.
        $cached = $read();
        if ($cached && $cached['key'] === $key) return $cached['player'];
        try {
            $player = $build();
            $player = is_array($player) && isset($player['SporkId']) ? $player : null;
            $encoded = json_encode(['key' => $key, 'player' => $player], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $stmt = $con->prepare('INSERT INTO kbobingo_player_cache (p_no, payload, attempted_date, fetched_date)
                VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload),
                attempted_date = VALUES(attempted_date), fetched_date = VALUES(fetched_date)');
            $stmt->bind_param('isss', $playerId, $encoded, $today, $today);
            $stmt->execute();
            $stmt->close();
            return $player;
        } catch (Throwable $error) {
            error_log('Bingo player build failed: ' . $error->getMessage());
            // Fall back to the last DB-built payload, never to a legacy KBO-scraped one.
            return $cached ? $cached['player'] : null;
        }
    } finally {
        $stmt = $con->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lock);
        $stmt->execute();
        $stmt->close();
    }
}
