<?php
declare(strict_types=1);
// 선수 프로필 조회수 집계. 원본 IP는 저장하지 않고, 날짜별 방문자 해시로 중복만 거른다.

const PLAYER_VIEW_WINDOW_DAYS = 7;
const PLAYER_VIEW_VISITOR_RETENTION_DAYS = 2;

function playerViewIsBot(string $userAgent): bool {
    return $userAgent === '' || (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|curl|wget|python|httpclient|monitor/i', $userAgent);
}

function playerViewVisitorHash(string $ip, string $userAgent, string $date): string {
    // 하루 단위 해시라 다른 날과 연결되지 않고, 원래 IP로 되돌릴 수 없다.
    return hash('sha256', 'wesiper-player-view|' . $date . '|' . $ip . '|' . $userAgent);
}

/** 조회를 기록하고, 오늘 처음 본 방문자라 실제로 집계됐으면 true를 돌려준다. */
function recordPlayerView(PDO $db, string $playerId, string $visitorHash, string $date): bool {
    $exists = $db->prepare('SELECT 1 FROM kbo_player_data WHERE player_id=? LIMIT 1');
    $exists->execute([$playerId]);
    if (!$exists->fetchColumn()) return false;
    $visitor = $db->prepare('INSERT IGNORE INTO kbo_player_view_visitors (view_date, player_id, visitor_hash) VALUES (?, ?, ?)');
    $visitor->execute([$date, $playerId, hex2bin($visitorHash)]);
    if ($visitor->rowCount() !== 1) return false;
    $daily = $db->prepare('INSERT INTO kbo_player_views_daily (view_date, player_id, views) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1');
    $daily->execute([$date, $playerId]);
    // 중복 확인용 해시는 이틀만 남긴다. 매 요청마다 지우지 않도록 확률적으로 정리한다.
    if (random_int(1, 200) === 1) {
        $db->prepare('DELETE FROM kbo_player_view_visitors WHERE view_date < ? LIMIT 5000')
            ->execute([date('Y-m-d', strtotime($date . ' -' . PLAYER_VIEW_VISITOR_RETENTION_DAYS . ' days'))]);
    }
    return true;
}

/** 최근 N일 조회수 상위 선수. 검색 API(get_player_list.php)와 같은 필드 이름으로 돌려준다. */
function popularPlayers(PDO $db, string $today, int $limit = 10, int $days = PLAYER_VIEW_WINDOW_DAYS): array {
    $since = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
    // 집계는 하위 쿼리에서 먼저 끝내 ONLY_FULL_GROUP_BY 설정에서도 동작하게 한다.
    $stmt = $db->prepare("SELECT v.player_id, v.views,
            p.name, p.img, p.pos, p.mainPos, p.draft, p.retire, p.team AS stored_team, p.backNo AS back_no,
            p.is_kbodle, p.is_number_retired,
            CASE WHEN p.is_kbodle = 0 THEN '은퇴' ELSE COALESCE(NULLIF(p.team,''), '소속 미확인') END AS current_status
        FROM (
            SELECT player_id, SUM(views) AS views, MAX(view_date) AS last_viewed
            FROM kbo_player_views_daily
            WHERE view_date BETWEEN :since AND :today
            GROUP BY player_id
            ORDER BY views DESC, last_viewed DESC, player_id
            LIMIT " . (max(1, min(30, $limit)) + 10) . "
        ) v
        JOIN kbo_player_data p ON p.player_id = v.player_id
        ORDER BY v.views DESC, v.last_viewed DESC, v.player_id");
    $stmt->execute(['since' => $since, 'today' => $today]);
    $rows = array_slice($stmt->fetchAll(PDO::FETCH_ASSOC), 0, max(1, min(30, $limit)));
    $retired = array_filter($rows, static fn($row) => (string)$row['is_kbodle'] === '0' && trim((string)$row['stored_team']) === '');
    $lastTeams = $retired && function_exists('searchPlayerLastTeams') ? searchPlayerLastTeams($db, array_column($retired, 'player_id')) : [];
    // 경기 기록이 없는 옛 선수는 연도별 공식 기록의 마지막 팀으로 채운다.
    $withoutTeam = array_filter($retired, static fn($row) => !isset($lastTeams[$row['player_id']]));
    if ($withoutTeam && function_exists('searchPlayerHistoricalTeams')) $lastTeams += searchPlayerHistoricalTeams($db, array_column($withoutTeam, 'player_id'));
    // 은퇴 연도가 비어 있는 은퇴 선수는 검색 API와 같은 방식(경기 기록·연도별 통산 기록의 마지막 연도)으로 채운다.
    $needYear = array_filter($rows, static fn($row) => (string)$row['is_kbodle'] === '0');
    $recordStats = $needYear && function_exists('searchPlayerRecordStats') ? searchPlayerRecordStats($db, array_column($needYear, 'player_id')) : [];
    return array_map(static function (array $row) use ($lastTeams, $recordStats): array {
        $active = (string)$row['is_kbodle'] !== '0';
        $storedTeam = trim((string)($row['stored_team'] ?? '')) ?: null;
        $formerTeam = $active ? null : ($storedTeam ?? (isset($lastTeams[$row['player_id']]) ? trim((string)$lastTeams[$row['player_id']]) : null));
        $numberRetired = (int)($row['is_number_retired'] ?? 0) === 1;
        return [
            'PlayerId' => $row['player_id'],
            'Name' => $row['name'],
            'Img' => $row['img'],
            'Pos' => $row['pos'] ?? '',
            'MainPos' => $row['mainPos'] ?? null,
            'FormerTeam' => $formerTeam,
            'Draft' => $row['draft'] ?? null,
            'Retire' => $row['retire'] ?? null,
            'LastRecordYear' => !$active && empty($row['retire']) ? ($recordStats[$row['player_id']]['last_year'] ?? null) : null,
            'FirstRecordYear' => !$active ? ($recordStats[$row['player_id']]['first_year'] ?? null) : null,
            'BackNo' => $row['back_no'],
            'IsActive' => $active,
            'IsNumberRetired' => $numberRetired ? 1 : 0,
            'NumberRetiredTeam' => $numberRetired ? ($formerTeam ?: $storedTeam) : null,
            'Team' => $row['current_status'],
            'Views' => (int)$row['views'],
        ];
    }, $rows);
}
