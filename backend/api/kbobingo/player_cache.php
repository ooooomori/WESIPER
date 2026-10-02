<?php
// Dates follow common.php's Asia/Seoul timezone. No cron or manual reset needed.
// Payloads are built from the production DB (backend/lib/bingo-player.php) and stored as
// {"key": "<source>|<crawler revision>|<date>", "player": {...}|null}. A new crawler revision,
// a new day, or a legacy payload parsed from the KBO website triggers a rebuild.
function bingoCacheEnvelope($row) {
    $envelope = $row ? json_decode($row['payload'] ?? 'null', true) : null;
    return is_array($envelope) && isset($envelope['key']) && array_key_exists('player', $envelope) ? $envelope : null;
}

function cachedBingoPlayer($con, $playerId, $build, $version) {
    $playerId = (int)$playerId;
    $today = date('Y-m-d');
    $key = $version . '|' . $today;
    $read = function () use ($con, $playerId) {
        $stmt = $con->prepare('SELECT payload FROM kbobingo_player_cache WHERE p_no = ?');
        $stmt->bind_param('i', $playerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return bingoCacheEnvelope($row);
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
