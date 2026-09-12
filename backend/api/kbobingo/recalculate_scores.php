<?php
// A single shared snapshot per grid, refreshed at most once every five minutes.
function refreshBingoScores($con, $gridId) {
    $gridId = (int)$gridId;
    $lock = 'kbobingo-score-' . $gridId;
    $escapedLock = $con->real_escape_string($lock);
    $query = function ($sql) use ($con) {
        $result = $con->query($sql);
        if ($result === false) throw new RuntimeException($con->error);
        return $result;
    };
    // No waiting: concurrent requests use the last committed scores.
    if ((int)$query("SELECT GET_LOCK('$escapedLock', 0) AS acquired")->fetch_assoc()['acquired'] !== 1) return;
    try {
        $cache = $query("SELECT calculated_at > NOW() - INTERVAL 5 MINUTE AS fresh
            FROM kbobingo_score_cache WHERE grid_index = $gridId")->fetch_assoc();
        if ($cache && (int)$cache['fresh'] === 1) return;
        $con->begin_transaction();
        $counts = $query("SELECT row_no, col_no, p_no, SUM(picked) AS picked
            FROM kbobingo_pick WHERE grid_index = $gridId GROUP BY row_no, col_no, p_no");
        $totals = [];
        $players = [];
        while ($row = $counts->fetch_assoc()) {
            $cell = (int)$row['row_no'] * 3 + (int)$row['col_no'];
            $players[$cell][(int)$row['p_no']] = (int)$row['picked'];
            $totals[$cell] = ($totals[$cell] ?? 0) + (int)$row['picked'];
        }
        $scores = [];
        foreach ($players as $cell => $entries) {
            foreach ($entries as $player => $count) {
                if ($totals[$cell] > 0) $scores[$cell][$player] = 100 - (int)floor(100 * $count / $totals[$cell]);
            }
        }
        $records = $query("SELECT user_id, picks, score FROM kbobingo_stat WHERE grid_index = $gridId");
        $updates = [];
        while ($row = $records->fetch_assoc()) {
            $picks = json_decode($row['picks'] ?? '', true);
            if (!is_array($picks) || count($picks) !== 9 || array_keys($picks) !== range(0, 8)) continue;
            $total = 0;
            foreach ($picks as $cell => $player) {
                if ($player === null) continue;
                // Preserve legacy/incomplete records when any pick has no count data.
                if (!is_numeric($player) || !isset($scores[$cell][(int)$player])) continue 2;
                $total += $scores[$cell][(int)$player];
            }
            if ($total !== (int)$row['score']) {
                $id = $con->real_escape_string($row['user_id']);
                $updates[] = "('$id', $total)";
            }
        }
        if ($updates) {
            $query("CREATE TEMPORARY TABLE bingo_score_updates (user_id VARCHAR(255) PRIMARY KEY, score INT NOT NULL)");
            foreach (array_chunk($updates, 500) as $batch) {
                $query("INSERT INTO bingo_score_updates VALUES " . implode(',', $batch));
            }
            $query("UPDATE kbobingo_stat s JOIN bingo_score_updates u ON s.user_id = u.user_id
                SET s.score = u.score WHERE s.grid_index = $gridId");
            $query("DROP TEMPORARY TABLE bingo_score_updates");
        }
        $query("INSERT INTO kbobingo_score_cache (grid_index, calculated_at) VALUES ($gridId, NOW())
            ON DUPLICATE KEY UPDATE calculated_at = VALUES(calculated_at)");
        $con->commit();
    } catch (Throwable $error) {
        $con->rollback();
        error_log('Bingo score refresh failed: ' . $error->getMessage());
    } finally {
        $query("SELECT RELEASE_LOCK('$escapedLock')");
    }
}
