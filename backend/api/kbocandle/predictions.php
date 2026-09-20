<?php
// Read-only: schema creation and probability calculation belong to the Python batch.
function candlePredictionEligible($year, $season, $preset, $start, $end, array $schedule): bool {
    $bounds = $schedule['2026']['regular'] ?? null;
    return (string)$year === '2026' && $season === 'regular' && $preset === 'whole' && $bounds
        && (($start === '' && $end === '') || ($start === $bounds[0] && $end === $bounds[1]));
}

function candlePrediction(PDO $pdo, string $playerId, array $baseline, string $lastPlayerDate): array {
    try {
        $stmt = $pdo->prepare('SELECT payload, source_revision FROM kbo_player_predictions
            WHERE player_id=:player AND season_year=2026 AND season_type=\'regular\'
              AND model_version=\'batting-distribution-v2-events\'
            ORDER BY as_of_date DESC, generated_at DESC LIMIT 1');
        $stmt->execute(['player' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'pending'];
        $p = json_decode($row['payload'], true);
        if (!is_array($p)) return ['status' => 'unavailable'];
        $revisionPath = getenv('WESIPER_CANDLE_REVISION_FILE') ?: '/tmp/wesiper-candle-data-revision';
        $revision = is_readable($revisionPath) ? trim(file_get_contents($revisionPath)) : 'initial';
        $matches = ($p['last_player_date'] ?? '') === $lastPlayerDate;
        foreach ($baseline as $key => $value) {
            $matches = $matches && isset($p['baseline'][$key]) && (int)$p['baseline'][$key] === (int)$value;
        }
        if (!$matches || $row['source_revision'] !== $revision) return ['status' => 'updating'];
        // Batch failures should not leave old probabilities looking current indefinitely.
        $generated = strtotime($p['generated_at'] ?? '');
        if (!$generated || time() - $generated > 48 * 3600) return ['status' => 'stale', 'as_of_date' => $p['as_of_date'] ?? null];
        return $p;
    } catch (Throwable $error) {
        error_log('Candle prediction unavailable: ' . $error->getMessage());
        return ['status' => 'unavailable'];
    }
}
