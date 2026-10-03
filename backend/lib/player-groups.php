<?php
declare(strict_types=1);
require_once __DIR__ . '/player-search-stats.php';

/** 이름이 바뀐 학교: 대표 이름 => 같은 학교로 보는 이름들 */
const ALUMNI_SCHOOL_ALIASES = [
    '덕수고' => ['덕수고', '덕수정보고', '덕수정보산업고', '덕수상고'],
    '군산상일고' => ['군산상일고', '군산상고'],
];

/**
 * 학력 문자열("화곡초(강서구리틀)-덕수중-덕수고-(방송통신대)")을 학교 이름 목록으로 나눈다.
 * 괄호 안의 리틀야구단·대학도 이름으로 친다: 화곡초, 강서구리틀, 덕수중, 덕수고, 방송통신대
 */
function alumniSchoolNames(?string $school): array {
    $names = [];
    foreach (explode('-', (string)$school) as $part) {
        if (preg_match_all('/\(([^)]*)\)/u', $part, $inner)) foreach ($inner[1] as $name) if (trim($name) !== '') $names[] = trim($name);
        $name = trim((string)preg_replace('/\([^)]*\)/u', '', $part));
        if ($name !== '') $names[] = $name;
    }
    return $names;
}

/** 같은 학교로 보는 이름들. 첫 번째가 대표 이름이다. */
function alumniSchoolGroup(string $name): array {
    foreach (ALUMNI_SCHOOL_ALIASES as $canonical => $aliases) if (in_array($name, $aliases, true)) return [$canonical, ...array_values(array_diff($aliases, [$canonical]))];
    return [$name];
}

/** 조건에 맞는 선수를 목록용 줄로 만든다. 코치로 등록된 사람은 뺀다. (현역 먼저, 그 안에서는 나이 어린 순). 은퇴 선수는 마지막 팀을 찾아 붙인다. */
function playerGroupPlayers(PDO $db, string $where, array $params, ?callable $filter = null): array {
    $stmt = $db->prepare("SELECT p.player_id, p.name, p.pos, p.mainPos, p.team AS stored_team, p.is_kbodle, p.birth, p.school, p.draft, p.retire, p.backNo
        FROM kbo_player_data p WHERE ($where) AND (p.pos IS NULL OR p.pos NOT LIKE '%코치%')");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($filter) $rows = array_values(array_filter($rows, $filter));
    $retired = array_filter($rows, static fn($row) => (string)$row['is_kbodle'] === '0');
    $lastTeams = searchPlayerLastTeams($db, array_column($retired, 'player_id'));
    $needsHistory = array_filter($retired, static fn($row) => !isset($lastTeams[$row['player_id']]) && trim((string)$row['stored_team']) === '');
    $historicalTeams = $needsHistory ? searchPlayerHistoricalTeams($db, array_column($needsHistory, 'player_id')) : [];
    $players = array_map(static function (array $row) use ($lastTeams, $historicalTeams) {
        $active = (string)$row['is_kbodle'] !== '0';
        $stored = trim((string)$row['stored_team']) ?: null;
        $id = $row['player_id'];
        // 검색 목록과 같은 표기: 세부 포지션이 "선발"·"구원"이면 "선발투수"·"구원투수"로 쓴다.
        $position = trim((string)$row['mainPos']) ?: trim((string)$row['pos']);
        if (trim((string)$row['mainPos']) !== '' && str_contains((string)$row['pos'], '투수') && !str_ends_with($position, '투수')) $position .= '투수';
        return [
            'PlayerId' => (int)$id, 'Name' => $row['name'], 'Pos' => $position, 'BasePos' => trim((string)$row['pos']) ?: null,
            'IsActive' => $active, 'Team' => $active ? $stored : (trim((string)($lastTeams[$id] ?? $stored ?? $historicalTeams[$id] ?? '')) ?: null),
            'BackNo' => $active && $row['backNo'] !== null && trim((string)$row['backNo']) !== '' ? trim((string)$row['backNo']) : null,
            'Birth' => $row['birth'], 'School' => $row['school'], 'Draft' => trim((string)$row['draft']) ?: null,
        ];
    }, $rows);
    usort($players, static fn($a, $b) => ($b['IsActive'] <=> $a['IsActive']) ?: strcmp((string)$b['Birth'], (string)$a['Birth']) ?: strcmp($a['Name'], $b['Name']));
    return $players;
}

/** 같은 학교(초·중·고·대)나 리틀야구단을 나온 선수 목록. */
function alumniPlayers(PDO $db, string $name): array {
    $group = alumniSchoolGroup($name);
    $where = implode(' OR ', array_fill(0, count($group), 'p.school LIKE ?'));
    $params = array_map(static fn($alias) => '%' . strtr($alias, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%', $group);
    // LIKE는 "덕수고"로 "남덕수고"도 잡으므로 학교 이름이 정확히 같은 선수만 남긴다.
    $players = playerGroupPlayers($db, $where, $params, static fn($row) => (bool)array_intersect($group, alumniSchoolNames($row['school'])));
    return ['school' => $group[0], 'players' => $players];
}

/** 같은 해에 입단한 선수 목록. draft는 "07 롯데 2차 4라운드 29순위"처럼 입단 연도 두 자리로 시작한다. */
function draftClassPlayers(PDO $db, int $year): array {
    $players = playerGroupPlayers($db, 'p.draft LIKE ? OR p.draft LIKE ?', [sprintf('%02d', $year % 100) . ' %', $year . ' %']);
    return ['year' => $year, 'players' => $players];
}

/** 생일(월-일)이 같은 선수 목록. */
function birthdayPlayers(PDO $db, string $monthDay): array {
    $players = playerGroupPlayers($db, 'p.birth LIKE ?', ['%-' . $monthDay]);
    return ['birthday' => $monthDay, 'players' => $players];
}
