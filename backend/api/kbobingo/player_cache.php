<?php
// Dates follow common.php's Asia/Seoul timezone. No cron or manual reset needed.
function cachedBingoPlayer($con, $playerId, $fetch) {
    $playerId = (int)$playerId;
    $today = date('Y-m-d');
    $read = function () use ($con, $playerId) {
        $stmt = $con->prepare('SELECT payload, attempted_date FROM kbobingo_player_cache WHERE p_no = ?');
        $stmt->bind_param('i', $playerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    };
    $row = $read();
    if ($row && $row['attempted_date'] === $today) return json_decode($row['payload'] ?? 'null', true);
    $lock = 'kbobingo-player-' . $playerId;
    $stmt = $con->prepare('SELECT GET_LOCK(?, 30) AS acquired');
    $stmt->bind_param('s', $lock);
    $stmt->execute();
    $acquired = (int)$stmt->get_result()->fetch_assoc()['acquired'] === 1;
    $stmt->close();
    if (!$acquired) {
        $row = $read();
        return $row ? json_decode($row['payload'] ?? 'null', true) : null;
    }
    try {
        // Another request may already have refreshed this player while we waited.
        $row = $read();
        if ($row && $row['attempted_date'] === $today) return json_decode($row['payload'] ?? 'null', true);
        $payload = $row['payload'] ?? null;
        // Record the attempt first so failures do not repeatedly hit KBO today.
        $stmt = $con->prepare('INSERT INTO kbobingo_player_cache (p_no, attempted_date)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE attempted_date = VALUES(attempted_date)');
        $stmt->bind_param('is', $playerId, $today);
        $stmt->execute();
        $stmt->close();
        try {
            $fresh = $fetch();
            if (is_array($fresh) && !isset($fresh['error']) && isset($fresh['SporkId'])) {
                $encoded = json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $stmt = $con->prepare('UPDATE kbobingo_player_cache SET payload = ?, fetched_date = ? WHERE p_no = ?');
                $stmt->bind_param('ssi', $encoded, $today, $playerId);
                $stmt->execute();
                $stmt->close();
                return $fresh;
            }
        } catch (Throwable $error) {
            error_log('Bingo player refresh failed: ' . $error->getMessage());
        }
        return json_decode($payload ?? 'null', true);
    } finally {
        $stmt = $con->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lock);
        $stmt->execute();
        $stmt->close();
    }
}
