<?php
/*
 * 선수 기록실: 1군 정규시즌 전체 선수 기록 순위.
 *   type   batter | pitcher | fielding(2001년 이후, 기간 검색 없음)
 *   season regular(기본) | preseason | postseason | futures — 정규시즌이 아니면 수비 기록·전반기/후반기/월·골든글러브 후보 조건은 쓸 수 없다.
 *   players  결과에 남길 선수 ID(쉼표로 여러 명)
 *   mode   year(year=YYYY) | range(from=YYYY&to=YYYY) | career | period(start=YYYY-MM-DD&end=YYYY-MM-DD, 2001년 이후)
 *   min    비율 기록으로 정렬할 때의 최소 조건: 규정 타석·이닝 대비 % (100·70·50·30·0)
 *   minScope  rate(기본: 비율 기록으로 정렬할 때만 최소 조건 적용) | all(모든 기록 정렬에 적용)
 *   minValue  최소 조건을 직접 입력(타석·이닝). 주면 min 대신 쓴다. 이닝은 야구 표기(소수점 .0·.1·.2만)로 받고, 타석·경기는 정수만 받는다. 수비 기록은 minUnit=innings|games로 단위를 고르고, min=utility(유틸리티 수비상 조건)도 받는다.
 *   part  firstHalf | secondHalf | month(month=3~11): year·range·career에서 고른 시즌마다 그 부분만 본다(2001년 이후)
 *   stadium(구장), opp(상대팀 구단): 2001년 이후 경기 기록만
 *   vs  상대 선수 ID(쉼표로 여러 명, 합산. 타자 기록이면 상대 투수, 투수 기록이면 상대 타자). 2001년 이후 경기 기록만 쓰고 규정 기준은 적용하지 않는다.
 *   mergePos=1  수비 기록에서 포지션별로 나누지 않고 선수의 모든 포지션을 한 줄로 합친다.
 *   gg=1(골든글러브 후보 시즌만, 1984년~), award=1(수비상 후보만, 수비 기록·2023년~). 둘 중 하나라도 켜면 최소 조건은 적용하지 않는다.
 *   active=1(현역만), foreign=1(외국인 선수만), prospect=1(유망주 명단의 선수만)
 *   pos(주 포지션: 포지션 코드·OF·IF·SP·RP), hand(타자는 타석 R·L·S, 투수는 투구 R·L·U(언더)·D(사이드)), birthMin, birthMax(출생 연도)
 *   team(구단), splitSeasons=1, splitTeams=1, ageMin, ageMax(시즌 나이), active=1(현역만)
 *   sort, dir(desc|asc), offset, limit
 *   meta=1 고를 수 있는 연도와 시즌 날짜만 돌려준다.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
$fail = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};
try {
    require_once __DIR__ . '/kbocandle/common.php';
    require_once __DIR__ . '/../lib/player-season-schedule.php';
    require_once __DIR__ . '/../lib/record-room.php';
    $schedule = profileSchedule();
    $season = $_GET['season'] ?? 'regular';
    if (!in_array($season, ['regular', 'preseason', 'postseason', 'futures'], true)) $fail('잘못된 시즌 종류입니다.');
    recordRoomSeason($season);
    // 시즌 종류별 기간: 시범경기·포스트시즌은 일정의 그 구간, 퓨처스리그는 그해 전체(프로필의 연도별 기록과 같다).
    $seasonSchedule = static function (string $kind) use ($schedule): array {
        $out = $schedule;
        if ($kind !== 'regular') foreach ($out as $year => &$ranges) $ranges['regular'] = $kind === 'futures' ? [$year . '-01-01', $year . '-12-31'] : ($ranges[$kind] ?? ['', '']);
        return $out;
    };
    // 시즌 합계 자료(1982~2000)에는 시범경기·퓨처스리그가 없고, 퓨처스 경기 기록은 2010년부터 있다.
    $seasonYears = static function (string $kind) use ($seasonSchedule): array {
        $first = ['preseason' => 2001, 'futures' => 2010][$kind] ?? 1982;
        return array_values(array_filter(recordRoomYears($seasonSchedule($kind)), static fn($year) => $year >= $first));
    };
    $regularSchedule = $schedule;
    $schedule = $seasonSchedule($season);
    $years = $seasonYears($season);
    $currentYear = recordRoomCurrentYear();
    if (($_GET['meta'] ?? '') === '1') {
        echo json_encode(['years' => $years, 'currentYear' => $currentYear, 'today' => recordRoomToday(), 'seasons' => (object)recordRoomSeasons($pdo, $regularSchedule), 'seasonYears' => ['preseason' => $seasonYears('preseason'), 'postseason' => $seasonYears('postseason'), 'futures' => $seasonYears('futures')], 'stadiums' => recordRoomStadiums($pdo), 'prospects' => array_diff_key(recordRoomProspects(), ['ids' => true])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $type = $_GET['type'] ?? 'batter';
    if (!in_array($type, ['batter', 'pitcher', 'fielding'], true)) $fail('잘못된 기록 종류입니다.');
    if ($type === 'fielding' && $season !== 'regular') $fail('수비 기록은 정규시즌만 볼 수 있습니다.');
    // 공식 수비 기록은 2001년부터 시즌 단위로만 있다.
    if ($type === 'fielding') $years = array_values(array_filter($years, static fn($year) => $year >= PROFILE_FIELDING_FIRST_YEAR));
    $mode = $_GET['mode'] ?? 'year';
    $yearParam = static function (string $name) use ($years) {
        $value = filter_var($_GET[$name] ?? null, FILTER_VALIDATE_INT);
        return $value !== false && in_array($value, $years, true) ? $value : null;
    };
    $filter = ['career' => false];
    if ($mode === 'year') {
        $year = isset($_GET['year']) ? $yearParam('year') : max($years);
        if ($year === null) $fail('잘못된 연도입니다.');
        $filter['segments'] = recordRoomYearSegments([$year], $schedule);
    } elseif ($mode === 'range') {
        $from = $yearParam('from'); $to = $yearParam('to');
        if ($from === null || $to === null || $from > $to) $fail('잘못된 연도 범위입니다.');
        $filter['segments'] = recordRoomYearSegments(array_values(array_filter($years, static fn($year) => $year >= $from && $year <= $to)), $schedule);
    } elseif ($mode === 'career') {
        $filter = ['career' => true, 'segments' => recordRoomYearSegments($years, $schedule)];
    } elseif ($mode === 'period') {
        if ($type === 'fielding') $fail('수비 기록은 기간 검색을 할 수 없습니다.');
        $date = static function (string $name): ?string {
            $value = $_GET[$name] ?? '';
            if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
            return $value;
        };
        $start = $date('start'); $end = $date('end');
        if ($start === null || $end === null || $start > $end) $fail('잘못된 기간입니다.');
        // 경기별 기록은 2001년부터 있다.
        if ($start < '2001-01-01') $fail('기간 검색은 2001년 이후만 가능합니다.');
        $filter['segments'] = recordRoomPeriodSegments($start, $end, $schedule);
    } else $fail('잘못된 검색 방식입니다.');
    $minUtility = $type === 'fielding' && ($_GET['min'] ?? '') === 'utility';
    $min = $minUtility ? 0 : filter_var($_GET['min'] ?? 100, FILTER_VALIDATE_INT);
    if (!in_array($min, [0, 30, 50, 70, 100], true)) $fail('잘못된 최소 조건입니다.');
    $minValue = null;
    if (($_GET['minValue'] ?? '') !== '') {
        $minValue = $_GET['minValue'];
        if (!is_string($minValue) || !preg_match('/^\d{1,5}(\.[012])?$/D', $minValue)) $fail('잘못된 최소 조건입니다.');
    }
    $team = $_GET['team'] ?? '';
    if (!is_string($team) || !mb_check_encoding($team, 'UTF-8') || mb_strlen($team) > 10) $fail('잘못된 팀입니다.');
    $part = $_GET['part'] ?? '';
    if ($part !== '') {
        $month = filter_var($_GET['month'] ?? 0, FILTER_VALIDATE_INT);
        if (!in_array($part, ['firstHalf', 'secondHalf', 'month'], true) || $mode === 'period' || $type === 'fielding' || $season !== 'regular' || ($part === 'month' && ($month === false || $month < 1 || $month > 12))) $fail('잘못된 기간입니다.');
        $filter['segments'] = recordRoomPartSegments($filter['segments'], $part, (int)$month, recordRoomSeasons($pdo, $regularSchedule));
        $filter['career'] = false;
    }
    $stadium = $_GET['stadium'] ?? '';
    $opponent = $_GET['opp'] ?? '';
    if (!is_string($stadium) || !mb_check_encoding($stadium, 'UTF-8') || mb_strlen($stadium) > 20 || !is_string($opponent) || !mb_check_encoding($opponent, 'UTF-8') || mb_strlen($opponent) > 10) $fail('잘못된 조건입니다.');
    if ($type === 'fielding') $stadium = $opponent = '';
    if ($stadium !== '' || $opponent !== '') {
        // 경기별 기록은 2001년부터 있다.
        $filter['segments'] = array_values(array_filter($filter['segments'], static fn($segment) => $segment[0] > 2000));
        if (!$filter['segments']) $fail('구장·상대팀 기록은 2001년 이후만 가능합니다.');
    }
    $versus = null;
    if (($_GET['vs'] ?? '') !== '') {
        if (!is_string($_GET['vs']) || !preg_match('/^\d{1,10}(,\d{1,10}){0,9}$/D', $_GET['vs']) || $type === 'fielding') $fail('잘못된 상대 선수입니다.');
        $versus = array_values(array_unique(array_map('intval', explode(',', $_GET['vs']))));
        sort($versus);
        // 경기별 기록은 2001년부터 있다.
        $filter['segments'] = array_values(array_filter($filter['segments'], static fn($segment) => $segment[0] > 2000));
        if (!$filter['segments']) $fail('상대 선수 기록은 2001년 이후만 가능합니다.');
        $min = 0;
    }
    $position = $_GET['pos'] ?? '';
    if (!is_string($position) || !preg_match('/^[A-Z0-9]{0,3}$/D', $position)) $fail('잘못된 포지션입니다.');
    $hand = $_GET['hand'] ?? '';
    if (!in_array($hand, ['', 'R', 'L', 'S', 'U', 'D'], true)) $fail('잘못된 조건입니다.');
    $only = ['active' => ($_GET['active'] ?? '') === '1', 'foreign' => ($_GET['foreign'] ?? '') === '1', 'prospect' => ($_GET['prospect'] ?? '') === '1', 'pos' => $position, 'hand' => $hand];
    foreach (['birthMin', 'birthMax'] as $name) {
        if (($_GET[$name] ?? '') === '') continue;
        $value = filter_var($_GET[$name], FILTER_VALIDATE_INT);
        if ($value === false || $value < 1900 || $value > 2100) $fail('잘못된 출생 연도입니다.');
        $only[$name] = $value;
    }
    $goldenGlove = $season === 'regular' && ($_GET['gg'] ?? '') === '1';
    $award = $type === 'fielding' && ($_GET['award'] ?? '') === '1';
    if (($_GET['players'] ?? '') !== '') {
        if (!is_string($_GET['players']) || !preg_match('/^\d{1,10}(,\d{1,10}){0,29}$/D', $_GET['players'])) $fail('잘못된 선수입니다.');
        $only['players'] = array_map('intval', explode(',', $_GET['players']));
    }
    if ($goldenGlove || $award) { $min = 0; $minValue = null; }
    if ($type === 'fielding') {
        if (!in_array($_GET['minUnit'] ?? 'innings', ['innings', 'games'], true)) $fail('잘못된 최소 조건입니다.');
        $only['minUnit'] = $_GET['minUnit'] ?? 'innings';
        $only['minUtility'] = $minUtility && !$goldenGlove && !$award;
    }
    if (!in_array($_GET['minScope'] ?? 'rate', ['rate', 'all'], true)) $fail('잘못된 최소 조건입니다.');
    $only['minAll'] = ($_GET['minScope'] ?? 'rate') === 'all';
    $kind = $versus !== null && $type === 'pitcher' ? 'versus' : $type;
    if ($minValue !== null) {
        // 이닝으로 받는 값은 아웃 수로 바꾼다(6.2이닝 = 20아웃). 타석·경기 수에는 소수점을 받지 않는다.
        $innings = $kind === 'pitcher' || ($type === 'fielding' && $only['minUnit'] === 'innings');
        if (!$innings && str_contains($minValue, '.')) $fail('잘못된 최소 조건입니다.');
        $minValue = $innings ? profileInningOuts($minValue) : (int)$minValue;
    }
    $age = static function (string $name) use ($fail): ?int {
        if (($_GET[$name] ?? '') === '') return null;
        $value = filter_var($_GET[$name], FILTER_VALIDATE_INT);
        if ($value === false || $value < 10 || $value > 60) $fail('잘못된 나이입니다.');
        return $value;
    };
    $filter += ['team' => $team, 'splitSeasons' => ($_GET['splitSeasons'] ?? '') === '1', 'splitTeams' => ($_GET['splitTeams'] ?? '') === '1', 'ageMin' => $age('ageMin'), 'ageMax' => $age('ageMax'), 'versus' => $versus, 'stadium' => $stadium, 'opponent' => $opponent, 'goldenGlove' => $goldenGlove, 'award' => $award, 'mergePositions' => $type === 'fielding' && ($_GET['mergePos'] ?? '') === '1', 'season' => $season];
    $sort = $_GET['sort'] ?? ['batter' => 'avg', 'pitcher' => 'era', 'fielding' => 'innings', 'versus' => 'pa'][$kind];
    if (!is_string($sort) || !in_array($sort, RECORD_ROOM_KEYS[$kind], true)) $fail('잘못된 정렬 기준입니다.');
    $direction = $_GET['dir'] ?? 'desc';
    if (!in_array($direction, ['asc', 'desc'], true)) $fail('잘못된 정렬 방향입니다.');
    $offset = max(0, (int)(filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT) ?: 0));
    $limit = min(200, max(1, (int)(filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT) ?: 50)));
    $rows = $filter['segments'] ? recordRoomDataset($pdo, $filter, $type, $schedule) : [];
    $result = recordRoomQuery($rows, $kind, $sort, $direction === 'desc', $min / 100, $offset, $limit, $minValue === null ? null : (float)$minValue, $only);
    echo json_encode(['type' => $type, 'mode' => $mode, 'sort' => $sort, 'dir' => $direction, 'min' => $min, 'offset' => $offset, 'limit' => $limit,
        // 경기별 기록이 없는 1982~2000년이 낀 조회는 일부 항목(선발·심화 지표 등)이 비어 있다.
        'historical' => (bool)array_filter($filter['segments'], static fn($segment) => $segment[0] <= 2000),
        'rateSort' => $result['rate'], 'total' => $result['total'], 'rows' => $result['rows']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Player record room failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '기록을 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
