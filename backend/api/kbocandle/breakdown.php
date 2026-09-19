<?php

function candleBreakdownStats(array $events, ?array $league): array {
    $pa = $ab = $h = $tb = $bb = $hbp = $sf = $hr = $sb = $so = 0;
    $gameIds = [];
    $eff_ab = $eff_h = $eff_tb = $eff_ob = 0;

    foreach ($events as $event) {
        $gameIds[(string)($event['game_id'] ?: $event['game_date'])] = true;
        $steals = (int)($event['sb'] ?? 0);
        $caught = (int)($event['cs'] ?? 0);
        $sb += $steals;
        $parsed = parseKboResultPHP($event['pa_result'] ?? '');
        if (!$parsed) continue;
        $pa++;

        $text = trim($event['pa_result']);
        $walk = (int)(in_array($text, ['4구', '볼넷', '고4'], true) || strpos($text, '볼넷') !== false);
        $hitByPitch = (int)(strpos($text, '사구') !== false);
        $sacFly = (int)(strpos($text, '희비') !== false || strpos($text, '희플') !== false);
        $ab += $parsed['ab'];
        $h += $parsed['h'];
        $tb += $parsed['tb'];
        $bb += $walk;
        $hbp += $hitByPitch;
        $sf += $sacFly;
        $hr += (int)(mb_substr($text, -1, 1, 'UTF-8') === '홈');
        $so += (int)(strpos($text, '삼진') !== false);

        $effectiveAb = $parsed['ab'];
        $effectiveHit = $parsed['h'];
        $effectiveBases = $parsed['tb'];
        $effectiveOnBase = $walk + $hitByPitch;
        $onBase = $parsed['h'] > 0 || $parsed['obp'] > 0;
        if ($onBase && $caught > 0) {
            $effectiveHit = 0;
            $effectiveBases = 0;
            if (!$parsed['h']) {
                $effectiveAb = 1;
                $effectiveOnBase -= 1;
            }
        } elseif ($onBase && $steals > 0) {
            $effectiveBases += $steals;
        } elseif (!$onBase && $steals > 0) {
            $effectiveAb = 0;
        }
        $eff_ab += $effectiveAb;
        $eff_h += $effectiveHit;
        $eff_tb += $effectiveBases;
        $eff_ob += $effectiveOnBase;
    }

    $avg = $ab > 0 ? $h / $ab : null;
    $obpDenominator = $ab + $bb + $hbp + $sf;
    $obp = $obpDenominator > 0 ? ($h + $bb + $hbp) / $obpDenominator : null;
    $slg = $ab > 0 ? $tb / $ab : null;
    $effObpDenominator = $eff_ab + $eff_ob + $sf;
    $effObp = $effObpDenominator > 0 ? ($eff_h + $eff_ob) / $effObpDenominator : null;
    $effSlg = $eff_ab > 0 ? $eff_tb / $eff_ab : null;
    $opsPlus = $obp !== null && $slg !== null && ($league['obp'] ?? 0) > 0 && ($league['slg'] ?? 0) > 0
        ? 100 * ($obp / $league['obp'] + $slg / $league['slg'] - 1) : null;

    return [
        'games' => count($gameIds), 'plate_appearances' => $pa,
        'avg' => $avg === null ? null : round($avg, 3),
        'obp' => $obp === null ? null : round($obp, 3),
        'slg' => $slg === null ? null : round($slg, 3),
        'at_bats' => $ab, 'hits' => $h, 'home_runs' => $hr,
        'stolen_bases' => $sb, 'walks' => $bb, 'strikeouts' => $so,
        'ops' => $obp === null || $slg === null ? null : round($obp + $slg, 3),
        'eff_ops' => $effObp === null || $effSlg === null ? null : round($effObp + $effSlg, 3),
        'ops_plus' => $opsPlus === null ? null : round($opsPlus, 1),
    ];
}

function candleSeasonBreakdown(PDO $pdo, string $playerId, string $year, string $start, string $end, array $baseline): array {
    $stmt = $pdo->prepare('SELECT game_date, game_id, inning, pa_result, sb, cs
        FROM kbo_season_records WHERE player_id = :player_id AND game_date BETWEEN :start AND :end
        ORDER BY game_date ASC, game_id ASC, inning ASC');
    $stmt->execute(['player_id' => $playerId, 'start' => $start, 'end' => $end]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $leagueStmt = $pdo->prepare('SELECT game_date, cum_ab, cum_h, cum_ob, cum_sf, cum_tb
        FROM kbo_league_records WHERE year = :year AND game_date BETWEEN :start AND :end ORDER BY game_date ASC');
    $leagueStmt->execute(['year' => $year, 'start' => $start, 'end' => $end]);
    $leagueByDate = [];
    foreach ($leagueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ab = (int)$row['cum_ab'] - $baseline['ab'];
        $h = (int)$row['cum_h'] - $baseline['h'];
        $ob = (int)$row['cum_ob'] - $baseline['ob'];
        $sf = (int)$row['cum_sf'] - $baseline['sf'];
        $tb = (int)$row['cum_tb'] - $baseline['tb'];
        $leagueByDate[$row['game_date']] = [
            'obp' => $ab + $ob + $sf > 0 ? ($h + $ob) / ($ab + $ob + $sf) : 0,
            'slg' => $ab > 0 ? $tb / $ab : 0,
        ];
    }

    $summarize = static function (string $key, string $label, array $subset) use ($leagueByDate): array {
        $lastDate = $subset ? $subset[count($subset) - 1]['game_date'] : null;
        $league = $lastDate ? ($leagueByDate[$lastDate] ?? null) : null;
        return ['key' => $key, 'label' => $label, 'stats' => candleBreakdownStats($subset, $league)];
    };

    $gameIds = [];
    foreach ($events as $event) $gameIds[(string)($event['game_id'] ?: $event['game_date'])] = true;
    $allGameIds = array_keys($gameIds);
    $period = [];
    foreach ([7, 15, 30] as $count) {
        $recentIds = array_fill_keys(array_slice($allGameIds, -$count), true);
        $subset = array_values(array_filter($events, static fn($event) => isset($recentIds[(string)($event['game_id'] ?: $event['game_date'])])));
        $period[] = $summarize((string)$count, "최근 {$count}경기", $subset);
    }

    $months = [];
    foreach ($events as $event) {
        $month = (int)substr($event['game_date'], 5, 2);
        $key = ($month === 3 || $month === 4) ? 4 : $month;
        if ($key >= 4 && $key <= 11) $months[$key][] = $event;
    }
    $monthRows = [];
    for ($month = 4; $month <= 11; $month++) {
        if (empty($months[$month])) continue;
        $monthRows[] = $summarize((string)$month, $month === 4 ? '3 · 4월' : "{$month}월", $months[$month]);
    }

    $innings = [];
    foreach ($events as $event) {
        $inning = (int)$event['inning'];
        if ($inning > 0) $innings[min($inning, 10)][] = $event;
    }
    $inningRows = [];
    for ($inning = 1; $inning <= 10; $inning++) {
        $inningRows[] = $summarize((string)$inning, $inning === 10 ? '연장' : "{$inning}회", $innings[$inning] ?? []);
    }
    return ['period' => $period, 'month' => $monthRows, 'inning' => $inningRows];
}
