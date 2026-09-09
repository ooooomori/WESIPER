<?php
include_once "common.php";

// 전달받은 파라미터 (예: GET 요청)
$season     = $_GET['season'] ?? 'regular';
$year     = $_GET['year'] ?? '';
$player_id  = $_GET['player_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';

// start_date와 end_date가 모두 비어있는 경우에만 연도별/시즌별 기본 기간 자동 할당
if (empty($start_date) && empty($end_date)) {
    $schedule = [
        '2026' => [
            'preseason'  => ['0312', '0324'],
            'regular'    => ['0328', '1231'],
            'postseason' => ['', '']
        ],
        '2025' => [
            'preseason'  => ['0308', '0318'],
            'regular'    => ['0322', '1004'],
            'postseason' => ['1006', '1031']
        ],
        '2024' => [
            'preseason'  => ['0309', '0319'],
            'regular'    => ['0323', '1001'],
            'postseason' => ['1002', '1028']
        ],
        '2023' => [
            'preseason'  => ['0313', '0328'],
            'regular'    => ['0401', '1017'],
            'postseason' => ['1019', '1113']
        ]
    ];

    if (isset($schedule[$year][$season])) {
        $start_date = $schedule[$year][$season][0];
        $end_date   = $schedule[$year][$season][1];
    }
}
try {
$sql = "SELECT 
            game_date,
            player_name,
            pa_result,
            sb,
            cs
        FROM {$season_record}
        WHERE player_id = :player_id 
          AND game_date BETWEEN :start_date AND :end_date
        ORDER BY game_date ASC, inning ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'player_id'  => $player_id,
    'start_date' => $start_date,
    'end_date'   => $end_date
]);

$rows = $stmt->fetchAll();

function parseKboResultPHP($pa) {
    $clean = trim($pa);
    if ($clean === '') return null;

    if (in_array($clean, ['4구', '사구', '고4', '볼넷'], true) || strpos($clean, '사사구') !== false) {
        return ['ab' => 0, 'h' => 0, 'tb' => 0, 'obp' => 1];
    }
    if (strpos($clean, '희비') !== false || strpos($clean, '희플') !== false) {
        return ['ab' => 0, 'h' => 0, 'tb' => 0, 'obp' => 0];
    }
    if (strpos($clean, '희번') !== false || strpos($clean, '희타') !== false || strpos($clean, '희실') !== false) {
        return ['ab' => 0, 'h' => 0, 'tb' => 0, 'obp' => 0];
    }

    $last_char = mb_substr($clean, -1, 1, 'UTF-8');
    if ($last_char === '안') return ['ab' => 1, 'h' => 1, 'tb' => 1, 'obp' => 1];
    if ($last_char === '2') return ['ab' => 1, 'h' => 1, 'tb' => 2, 'obp' => 1];
    if ($last_char === '3') return ['ab' => 1, 'h' => 1, 'tb' => 3, 'obp' => 1];
    if ($last_char === '홈') return ['ab' => 1, 'h' => 1, 'tb' => 4, 'obp' => 1];

    return ['ab' => 1, 'h' => 0, 'tb' => 0, 'obp' => 0];
}

// 1. 데이터를 일자별로 그룹화
$rows_by_date = [];
foreach ($rows as $row) {
    $rows_by_date[$row['game_date']][] = $row;
}

// 2. 전체 누적 스탯 추적 변수
$cum_ab = 0; $cum_h = 0; $cum_bb = 0; $cum_hbp = 0; $cum_sf = 0; $cum_tb = 0;
$cum_eff_ab = 0; $cum_eff_tb = 0;

// 전일 종가를 다음 날 시가로 사용하기 위한 저장소
$prev_close = [
    'avg' => 0, 'obp' => 0, 'slg' => 0, 'ops' => 0, 'eff_ops' => 0
];

$result_output = [];

// 3. 일자별 타석 순회하며 실시간 OHLC 추출
foreach ($rows_by_date as $date => $day_rows) {
    
    // 오늘의 시가(Open)는 어제의 종가(Close)
    $open  = $prev_close;
    $high  = $prev_close;
    $low   = $prev_close;
    $close = $prev_close;
    
    foreach ($day_rows as $row) {
        $parsed = parseKboResultPHP($row['pa_result']);
        if (!$parsed) continue;

        $sb = (int)($row['sb'] ?? 0);
        $cs = (int)($row['cs'] ?? 0);
        $pa_txt = trim($row['pa_result']);

        // 실질 스탯 보정
        $eff_ab = $parsed['ab'];
        $eff_tb = $parsed['tb'];
        $is_on_base = ($parsed['h'] > 0 || $parsed['obp'] > 0);

        if ($is_on_base) {
            $eff_tb += $sb;
            $eff_tb -= $cs;
        } else {
            if ($sb > 0) $eff_ab = 0;
        }

        // 볼넷, 사구, 희플 세부 카운트
        $bb = (in_array($pa_txt, ['4구', '볼넷', '고4'], true) || strpos($pa_txt, '볼넷') !== false) ? 1 : 0;
        $hbp = (strpos($pa_txt, '사구') !== false) ? 1 : 0;
        $sf = (strpos($pa_txt, '희비') !== false || strpos($pa_txt, '희플') !== false) ? 1 : 0;

        // 글로벌 누적 스탯 갱신
        $cum_ab  += $parsed['ab'];
        $cum_h   += $parsed['h'];
        $cum_tb  += $parsed['tb'];
        $cum_bb  += $bb;
        $cum_hbp += $hbp;
        $cum_sf  += $sf;
        
        $cum_eff_ab += $eff_ab;
        $cum_eff_tb += $eff_tb;

        // **현재 타석 직후의 실시간 지표 계산**
        $current_avg = ($cum_ab > 0) ? ($cum_h / $cum_ab) : 0;
        
        $obp_den = ($cum_ab + $cum_bb + $cum_hbp + $cum_sf);
        $current_obp = ($obp_den > 0) ? (($cum_h + $cum_bb + $cum_hbp) / $obp_den) : 0;
        
        $current_slg = ($cum_ab > 0) ? ($cum_tb / $cum_ab) : 0;
        $current_ops = $current_obp + $current_slg;
        
        $current_eff_slg = ($cum_eff_ab > 0) ? ($cum_eff_tb / $cum_eff_ab) : 0;
        $current_eff_ops = $current_obp + $current_eff_slg;

        // **오늘의 고가(High), 저가(Low) 갱신**
        $high['avg'] = max($high['avg'], $current_avg);
        $low['avg']  = min($low['avg'], $current_avg);
        
        $high['obp'] = max($high['obp'], $current_obp);
        $low['obp']  = min($low['obp'], $current_obp);
        
        $high['slg'] = max($high['slg'], $current_slg);
        $low['slg']  = min($low['slg'], $current_slg);
        
        $high['ops'] = max($high['ops'], $current_ops);
        $low['ops']  = min($low['ops'], $current_ops);
        
        $high['eff_ops'] = max($high['eff_ops'], $current_eff_ops);
        $low['eff_ops']  = min($low['eff_ops'], $current_eff_ops);

        // 현재 타석 값을 임시 종가로 덮어쓰기 (마지막 타석 값이 최종 종가가 됨)
        $close = [
            'avg'     => $current_avg,
            'obp'     => $current_obp,
            'slg'     => $current_slg,
            'ops'     => $current_ops,
            'eff_ops' => $current_eff_ops
        ];
    }

    // 일자별 최종 OHLC 데이터를 배열에 푸시
    $result_output[] = [
        'date' => $date,
        'avg' => [
            'open'  => round($open['avg'], 3),
            'high'  => round($high['avg'], 3),
            'low'   => round($low['avg'], 3),
            'close' => round($close['avg'], 3)
        ],
        'obp' => [
            'open'  => round($open['obp'], 3),
            'high'  => round($high['obp'], 3),
            'low'   => round($low['obp'], 3),
            'close' => round($close['obp'], 3)
        ],
        'slg' => [
            'open'  => round($open['slg'], 3),
            'high'  => round($high['slg'], 3),
            'low'   => round($low['slg'], 3),
            'close' => round($close['slg'], 3)
        ],
        'ops' => [
            'open'  => round($open['ops'], 3),
            'high'  => round($high['ops'], 3),
            'low'   => round($low['ops'], 3),
            'close' => round($close['ops'], 3)
        ],
        'eff_ops' => [
            'open'  => round($open['eff_ops'], 3),
            'high'  => round($high['eff_ops'], 3),
            'low'   => round($low['eff_ops'], 3),
            'close' => round($close['eff_ops'], 3)
        ]
    ];

    // 다음 날 계산을 위해 전일 종가 업데이트
    $prev_close = $close;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'player_name' => $rows['player_name'] ?? '',
    'player_id'     => $player_id,
    'data'          => $result_output
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}