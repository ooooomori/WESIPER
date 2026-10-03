<?php
declare(strict_types=1);

/** "KIA 타이거즈" → "KIA", "kt wiz" → "KT": 이동 현황에서 쓰는 짧은 팀 이름 */
function profileTradeTeam(string $team): string {
    $short = strtok(trim($team), ' ') ?: trim($team);
    return strtolower($short) === 'kt' ? 'KT' : $short;
}

/**
 * 이동 현황에 트레이드 상세(kbo_trades, kbo_trade_assets)를 붙인다.
 * - 날짜가 3일 안으로 가까운 '트레이드' 이동 내역이 있으면 그 줄에 상세를 단다.
 * - 이동 내역이 없는 트레이드(공시 자료가 없는 2016년 이전 등)는 트레이드 줄을 새로 넣는다.
 */
function profileAttachTrades(PDO $db, string $pid, array $movements): array {
    $own = $db->prepare('SELECT t.id, t.trade_date, a.from_team, a.to_team
        FROM kbo_trade_assets a JOIN kbo_trades t ON t.id=a.trade_id
        WHERE a.player_id=? AND a.asset_type=\'player\' ORDER BY t.trade_date, a.item_order');
    $own->execute([$pid]);
    $trades = [];
    foreach ($own->fetchAll(PDO::FETCH_ASSOC) as $row) $trades[(int)$row['id']] ??= $row;
    if (!$trades) return $movements;

    $marks = implode(',', array_fill(0, count($trades), '?'));
    $assets = $db->prepare("SELECT a.trade_id, a.asset_type, a.from_team, a.to_team, a.description, a.player_name, a.player_id,
            a.linked_draftee_id, d.name AS draftee_name
        FROM kbo_trade_assets a LEFT JOIN kbo_player_data d ON d.player_id=a.linked_draftee_id
        WHERE a.trade_id IN ($marks) ORDER BY a.trade_id, a.item_order");
    $assets->execute(array_keys($trades));
    $details = [];
    foreach ($assets->fetchAll(PDO::FETCH_ASSOC) as $asset) {
        $id = (int)$asset['trade_id'];
        $details[$id] ??= ['date' => $trades[$id]['trade_date'], 'assets' => []];
        $details[$id]['assets'][] = [
            'type' => $asset['asset_type'],
            'from' => profileTradeTeam($asset['from_team']),
            'to' => profileTradeTeam($asset['to_team']),
            'text' => $asset['description'],
            'playerId' => $asset['player_id'] !== null ? (int)$asset['player_id'] : null,
            'playerName' => $asset['player_name'],
            'drafteeId' => $asset['linked_draftee_id'] !== null ? (int)$asset['linked_draftee_id'] : null,
            'drafteeName' => $asset['draftee_name'],
        ];
    }

    $used = [];
    foreach ($movements as &$movement) {
        if (($movement['type'] ?? '') !== '트레이드' || strlen((string)$movement['date']) !== 10) continue;
        $best = null; $bestGap = 4;
        foreach ($trades as $id => $trade) {
            if (isset($used[$id])) continue;
            $gap = abs((strtotime((string)$movement['date']) - strtotime((string)$trade['trade_date'])) / 86400);
            if ($gap < $bestGap) { $best = $id; $bestGap = $gap; }
        }
        if ($best !== null) { $movement['trade'] = $details[$best]; $used[$best] = true; }
    }
    unset($movement);

    foreach ($trades as $id => $trade) {
        if (isset($used[$id])) continue;
        $from = profileTradeTeam($trade['from_team']); $to = profileTradeTeam($trade['to_team']);
        $movements[] = ['date' => $trade['trade_date'], 'type' => '트레이드', 'team' => $to, 'note' => $from . '→' . $to,
            'oldBackNo' => null, 'newBackNo' => null, 'trade' => $details[$id]];
    }
    // 새로 넣은 줄이 제자리에 오도록 날짜 내림차순으로 다시 정렬한다(같은 날은 원래 순서 유지).
    $sortKey = static fn(array $movement): string => strlen((string)$movement['date']) === 4 ? $movement['date'] . '-01-01' : (string)$movement['date'];
    foreach ($movements as $index => &$movement) $movement['_order'] = $index;
    unset($movement);
    usort($movements, static fn($a, $b) => strcmp($sortKey($b), $sortKey($a)) ?: ($a['_order'] <=> $b['_order']));
    foreach ($movements as &$movement) unset($movement['_order']);
    unset($movement);
    return $movements;
}
