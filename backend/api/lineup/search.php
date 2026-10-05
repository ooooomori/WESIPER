<?php
// 라인업 맞추기(어려움·익스트림): 선수명 자동완성. kbo_player_data의 선수 중 그해 1군 정규시즌에 타석 기록이 있는 선수만 보여준다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$body = json_decode(file_get_contents('php://input') ?: '', true);
$keyword = is_array($body) ? trim((string)($body['keyword'] ?? '')) : '';
$year = is_array($body) ? (string)($body['year'] ?? '') : '';
if ($keyword === '' || mb_strlen($keyword, 'UTF-8') > 20) {
    echo json_encode(['success' => true, 'list' => []]);
    exit;
}

try {
    require_once __DIR__ . '/../kbocandle/common.php';
    // LIKE의 와일드카드 문자는 글자 그대로 찾는다.
    $like = '%' . addcslashes($keyword, '\\%_') . '%';
    // 메인 페이지 검색처럼 풀네임·개명 전 이름·별명으로도 찾는다. 별명은 띄어쓰기를 무시하고 맞춘다.
    $nickname = '%' . addcslashes(preg_replace('/\s+/u', '', $keyword), '\\%_') . '%';
    $query = $pdo->prepare("SELECT player_id,name,oldname,pos,team,birth FROM kbo_player_data p"
        . " WHERE COALESCE(pos,'') <> '코치' AND (name LIKE ? OR oldname LIKE ? OR fullname LIKE ?"
        . " OR EXISTS (SELECT 1 FROM kbo_player_nicknames n WHERE n.player_id=p.player_id AND REGEXP_REPLACE(n.nickname, '[[:space:]]+', '') LIKE ?))"
        // 타자를 먼저, 이름이 검색어로 시작하는 선수를 먼저 보여준다.
        . " ORDER BY pos='투수', name LIKE ? DESC, CHAR_LENGTH(name), name, birth");
    $query->execute([$like, $like, $like, $nickname, addcslashes($keyword, '\%_') . '%']);
    $rows = $query->fetchAll();

    // 그해 정규시즌 타석 기록(kbo_season_records)에 있는 선수로 좁힌다. 연도를 모르면 전체에서 찾는다.
    // 한 쿼리의 EXISTS로 묶으면 그해 기록 전체를 훑어 느려서, 이름으로 찾은 선수들만 따로 확인한다.
    [$start, $end] = getKBOSchedule()[$year]['regular'] ?? ['', ''];
    if ($rows && $start && $end) {
        $ids = array_column($rows, 'player_id');
        $query = $pdo->prepare('SELECT DISTINCT player_id FROM kbo_season_records WHERE league_level=1 AND player_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            . ' AND game_date BETWEEN ? AND ?');
        $query->execute([...$ids, $start, $end]);
        $played = array_flip($query->fetchAll(PDO::FETCH_COLUMN));
        $rows = array_filter($rows, static fn($row) => isset($played[$row['player_id']]));
    }
    $list = array_map(static fn($row) => [
        'id' => (int)$row['player_id'],
        'name' => $row['name'],
        'oldName' => $row['oldname'] ?: null,
        'pos' => $row['pos'] ?: null,
        'team' => $row['team'] ?: null,
        'birthYear' => preg_match('/^(\d{4})/', (string)$row['birth'], $match) ? (int)$match[1] : null,
    ], array_slice(array_values($rows), 0, 12));
    echo json_encode(['success' => true, 'list' => $list], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Lineup search failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '선수를 검색하지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
