<?php
header('Content-Type: application/json; charset=UTF-8');
// 경기가 끝나면 순위가 바로 바뀌므로 1분만 재사용한다. 오류 응답은 저장하지 않는다.
header('Cache-Control: public, max-age=60');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
require_once __DIR__ . '/../lib/team-standings.php';
require_once __DIR__ . '/../lib/kbo-live-results.php';
require_once __DIR__ . '/kbocandle/common.php';
try {
    $configPath = __DIR__ . '/../config/database.php';
    $config = require (is_file($configPath) ? $configPath : '/opt/bitnami/apache/conf/wesiper-db.php');
    $db = new PDO("mysql:host={$config['host']};port=" . ($config['port'] ?? 3306) . ";dbname={$config['database']};charset=utf8mb4",
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $year = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
    [$start,$end] = getKBOSchedule()[$year]['regular'] ?? [null,null];
    if (!$start || !$end) throw new RuntimeException('Missing regular season dates');
    $query = $db->prepare('SELECT * FROM kbo_schedule WHERE league_level=1 AND game_date BETWEEN ? AND ? AND away_score IS NOT NULL AND home_score IS NOT NULL AND game_code NOT IN (\'66661031KTSS02021\',\'66661001SKKT02024\') ORDER BY game_date, game_code'); // 순위 결정전(타이브레이커)은 정규시즌 성적에서 제외
    $query->execute([$start,$end]);
    $games=$query->fetchAll(PDO::FETCH_ASSOC);
    // 크롤러(매일 02:00)가 아직 저장하지 않은 종료 경기를 더한다. 오늘 경기 목록은 todayGames와 같은 캐시를 쓴다.
    // KBO 조회나 보관 파일에 문제가 있어도 저장된 결과만으로 순위를 낸다.
    try {
        if (function_exists('curl_init')) {
            $today = cachedTodayGames(KBO_TODAY_GAMES_KEY, static fn($day) => fetchKboGameList('1', KBO_TODAY_GAMES_SERIES, $day));
            recordLiveResults(liveResultsFromGames($today['games']));
        }
    } catch (Throwable $error) { error_log('Team standings live refresh: ' . $error->getMessage()); }
    try { $games = mergeLiveResults($games, pendingLiveResults(), $start, $end); }
    catch (Throwable $error) { error_log('Team standings live merge: ' . $error->getMessage()); }
    foreach($games as &$game) foreach(['away_team','home_team'] as $field) { $game[$field]=match(strtoupper($game[$field])) {'KT'=>'KT','LG'=>'LG','NC'=>'NC','KIA'=>'KIA','SSG'=>'SSG','SK'=>'SSG','HT'=>'KIA','OB'=>'두산','SS'=>'삼성','LT'=>'롯데','HH'=>'한화','WO'=>'키움',default=>$game[$field]}; } unset($game);
    echo json_encode(['code' => '100', 'title' => "$year 저장된 경기 결과 기준", 'rows' => calculateStandings($games)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Team standings: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '팀 순위를 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
