<?php
// Bounds allow any additional draws. For a fixed number of wins/losses,
// all remaining losses give the lower rate and all wins give the upper rate.
function seasonRemaining(array $team): int {
    return max(0, 144 - $team['wins'] - $team['losses'] - $team['draws']);
}

function guaranteedAbove(array $team, array $other, int $wins, int $otherLosses): bool {
    $denominator = max(1, 144 - $team['draws']);
    $otherDenominator = max(1, 144 - $other['draws']);
    return ($team['wins'] + $wins) * $otherDenominator
        > ($other['wins'] + seasonRemaining($other) - $otherLosses) * $denominator;
}

// The minimum own-wins + opponent-losses count that guarantees a STRICTLY
// higher final rate for EVERY feasible split, not just a favorable split.
function pairMagic(array $team, array $other): int {
    $lastUnsafe = -1;
    for ($wins = 0; $wins <= seasonRemaining($team); $wins++) {
        for ($losses = 0; $losses <= seasonRemaining($other); $losses++) {
            if (!guaranteedAbove($team, $other, $wins, $losses)) {
                $lastUnsafe = max($lastUnsafe, $wins + $losses);
            }
        }
    }
    return $lastUnsafe + 1;
}

function autumnNumbers(array $team, array $teams): array {
    $magic = []; $tragic = []; $controllable = 0; $canLoseControl = 0;
    $bestRank = 1; $worstRank = 1;
    $remaining = seasonRemaining($team);
    foreach ($teams as $other) {
        if ($other['team'] === $team['team']) continue;
        $magic[] = pairMagic($team, $other);
        $tragic[] = pairMagic($other, $team);
        if (guaranteedAbove($team, $other, $remaining, 0)) $controllable++;
        if (guaranteedAbove($other, $team, 0, $remaining)) $canLoseControl++;
        if (guaranteedAbove($other, $team, 0, 0)) $bestRank++;
        if (!guaranteedAbove($team, $other, 0, 0)) $worstRank++;
    }
    sort($magic, SORT_NUMERIC); sort($tragic, SORT_NUMERIC);
    // Top five requires finishing strictly above at least five of nine rivals.
    $remark = $bestRank > 5 ? '가을야구 불가' : ($worstRank <= 5 ? $worstRank . '위 확보'
        : ($bestRank > 1 ? ($bestRank - 1) . '위 불가' : '1위 가능'));
    return [$remaining, $controllable === 9 ? max($magic) : 'X',
        $controllable >= 5 ? $magic[4] : 'X', $canLoseControl >= 5 ? $tragic[4] : 'X', $remark];
}

function calculateStandings(array $games): array {
    $teams = [];
    foreach (['KIA', '삼성', 'LG', '두산', 'KT', 'SSG', '롯데', '한화', 'NC', '키움'] as $name) {
        $teams[$name] = ['team' => $name, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'results' => [], 'streak' => 0];
    }
    foreach ($games as $game) {
        if ($game['away_score'] === null || $game['home_score'] === null) continue;
        $away = $game['away_team']; $home = $game['home_team'];
        if (!isset($teams[$away], $teams[$home])) throw new RuntimeException('Unknown team');
        $result = (int)$game['away_score'] <=> (int)$game['home_score'];
        foreach ([$away => $result, $home => -$result] as $name => $outcome) {
            $team =& $teams[$name];
            $team[$outcome > 0 ? 'wins' : ($outcome < 0 ? 'losses' : 'draws')]++;
            $team['results'][] = $outcome > 0 ? '승' : ($outcome < 0 ? '패' : '무');
            if ($outcome !== 0) $team['streak'] = $team['streak'] * $outcome > 0 ? $team['streak'] + $outcome : $outcome;
            unset($team);
        }
    }
    $teams = array_values($teams);
    foreach ($teams as &$team) $team['rate'] = $team['wins'] / max(1, $team['wins'] + $team['losses']);
    unset($team);
    usort($teams, fn($a, $b) => ($b['rate'] <=> $a['rate']) ?: strcmp($a['team'], $b['team']));
    $leader = $teams[0]; $rank = 0; $previous = null; $rows = [];
    foreach ($teams as $index => $team) {
        if ($previous === null || $team['rate'] !== $previous) $rank = $index + 1;
        $previous = $team['rate'];
        $gap = (($leader['wins'] - $leader['losses']) - ($team['wins'] - $team['losses'])) / 2;
        $streak = $team['streak'];
        $values = [$rank, $team['team'], $team['wins'] + $team['losses'] + $team['draws'],
            $team['wins'], $team['losses'], $team['draws'], number_format($team['rate'], 3, '.', ''),
            $gap == 0 ? '-' : number_format($gap, 1, '.', ''),
            $streak === 0 ? '-' : ($streak > 0 ? '🔥 ' : '❄️ ') . abs($streak),
            implode('', array_slice($team['results'], -5)) ?: '-'];
        $values = array_merge($values, autumnNumbers($team, $teams));
        $rows[] = ['row' => array_map(fn($value) => ['Text' => (string)$value], $values)];
    }
    return $rows;
}
