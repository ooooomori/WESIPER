<?php
include_once "common.php";

// 전달받은 파라미터 (GET 요청)
$season     = $_GET['season'] ?? 'regular';
$year       = $_GET['year'] ?? '';
$player_id  = $_GET['player_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$date_preset = $_GET['date_preset'] ?? 'whole';
$img        = $_GET['img'] ?? '';

if ($season === 'preseason' && in_array((string)$year, ['2008', '2009', '2010', '2012'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '해당 연도는 시범경기 조회를 지원하지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 타석 결과 텍스트를 요청한 규격으로 강제 변환하는 전용 함수 신설
function formatPaResult($pa_txt, $sb, $cs) {
    $clean = trim($pa_txt);
    $base = $clean;
    
    if (strpos($clean, '타방') !== false) {
        $base = '타격방해';
    } else if ($clean === '야선' || mb_substr($clean, -2, 2, 'UTF-8') === '희선') {
        $base = '야수선택';
    } else if (strpos($clean, '고4') !== false) {
        $base = '고4';
    } else if (in_array($clean, ['4구', '볼넷'], true) || strpos($clean, '볼넷') !== false) {
        $base = '볼넷';
    } else if (strpos($clean, '사구') !== false) {
        $base = '사구';
    } else {
        $last_char = mb_substr($clean, -1, 1, 'UTF-8');
        if ($last_char === '안') $base = '1루타';
        else if ($last_char === '2') $base = '2루타';
        else if ($last_char === '3') $base = '3루타';
        else if ($last_char === '홈') $base = '홈런';
        else if (strpos($clean, '땅') !== false) $base = '땅볼';
        else if (strpos($clean, '병') !== false) $base = '병살';
        else if (strpos($clean, '삼진') !== false) $base = '삼진';
        else if (strpos($clean, '삼중') !== false) $base = '삼중살';
        else if (strpos($clean, '직') !== false) $base = '직선타';
        else if (strpos($clean, '희비') !== false) $base = '희비';
        else if (strpos($clean, '희번') !== false) $base = '희번';
        else if ($last_char === '번') $base = '번트아웃';
        else if (strpos($clean, '파') !== false || strpos($clean, '비') !== false || strpos($clean, '뜬') !== false) $base = '뜬공';
        else if (strpos($clean, '실') !== false) $base = '실책';
        else $base = '기타';
    }

    $mod = [];
    if ($sb > 0) $mod[] = ($sb === 1) ? '도루' : $sb . '도루';
    if ($cs > 0) $mod[] = ($cs === 1) ? '도루자' : $cs . '도루자';
    
    if (!empty($mod)) {
        $base .= '(' . implode(' ', $mod) . ')';
    }
    
    return $base;
}

function parseKboResultPHP($pa) {
    $clean = trim($pa);
    if ($clean === '') return null;

    if (in_array($clean, ['4구', '사구', '고4', '볼넷'], true) || strpos($clean, '사사구') !== false) {
        return ['ab' => 0, 'h' => 0, 'tb' => 0, 'obp' => 1];
    }
    if (strpos($clean, '타방') !== false) {
        return ['ab' => 0, 'h' => 0, 'tb' => 0, 'obp' => 0];
    }
    if ($clean === '야선' || mb_substr($clean, -2, 2, 'UTF-8') === '희선') {
        return ['ab' => 1, 'h' => 0, 'tb' => 0, 'obp' => 0];
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

$schedule = getKBOSchedule();

if (empty($start_date) && empty($end_date)) {
    if (isset($schedule[$year][$season]) && !empty($schedule[$year][$season][0])) {
        $start_date = $schedule[$year][$season][0];
        $end_date   = $schedule[$year][$season][1];
    }
}

try {
    $season_start_bound = $schedule[$year][$season][0] ?? clone $start_date;

    $baseline_sql = "
        SELECT 
            cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
            cum_eff_ab, cum_eff_h, cum_eff_ob, cum_eff_tb
        FROM kbo_league_records
        WHERE year = :year AND game_date < :season_start
        ORDER BY game_date DESC LIMIT 1
    ";
    $b_stmt = $pdo->prepare($baseline_sql);
    $b_stmt->execute([
        'year' => $year,
        'season_start' => $season_start_bound
    ]);
    $baseline = $b_stmt->fetch(PDO::FETCH_ASSOC);

    // 베이스라인이 없으면(그 해 첫 경기) 0으로 세팅
    $base_l_ab = (int)($baseline['cum_ab'] ?? 0);
    $base_l_h  = (int)($baseline['cum_h'] ?? 0);
    $base_l_ob = (int)($baseline['cum_ob'] ?? 0);
    $base_l_sf = (int)($baseline['cum_sf'] ?? 0);
    $base_l_tb = (int)($baseline['cum_tb'] ?? 0);
    
    $base_l_eff_ab = (int)($baseline['cum_eff_ab'] ?? 0);
    $base_l_eff_h  = (int)($baseline['cum_eff_h'] ?? 0);
    $base_l_eff_ob = (int)($baseline['cum_eff_ob'] ?? 0);
    $base_l_eff_tb = (int)($baseline['cum_eff_tb'] ?? 0);

    // 💡 [개선사항 2] start_date가 숫자(7, 15, 30)로 넘어온 경우, 최근 N경기의 실제 시작 날짜를 역추적한다.
    if (is_numeric($start_date)) {
        $limit = (int)$start_date;
        
        // 2026년이 아닌 과거 시즌의 경우 역추적의 기준점(end_date)을 해당 시즌 최종일로 강제 고정
        if ($year !== '2026') {
            if (isset($schedule[$year][$season]) && !empty($schedule[$year][$season][1])) {
                $end_date = $schedule[$year][$season][1];
            }
        } else {
            $end_date = date('Y-m-d');
        }
        
        $find_date_sql = "SELECT game_date 
                          FROM (
                              SELECT game_date 
                              FROM kbo_season_records 
                              WHERE player_id = :player_id
                                AND game_date BETWEEN :season_start AND :end_date
                              GROUP BY game_date, game_id 
                              ORDER BY game_date DESC, game_id DESC 
                              LIMIT :limit
                          ) AS recent_games 
                          ORDER BY game_date ASC 
                          LIMIT 1";
        
        $fd_stmt = $pdo->prepare($find_date_sql);
        
        $fd_stmt->bindValue(':player_id', $player_id, PDO::PARAM_STR);
        $fd_stmt->bindValue(':season_start', $season_start_bound, PDO::PARAM_STR);
        $fd_stmt->bindValue(':end_date', $end_date, PDO::PARAM_STR);
        $fd_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $fd_stmt->execute();
        
        $target_date = $fd_stmt->fetchColumn();
        
        if ($target_date) {
            $start_date = $target_date;
        } else {
            // 조회 가능한 데이터가 없으면 시즌 시작일로 롤백
            $start_date = $season_start_bound;
        }
    }

    // 과거 데이터 누적 계산용 쿼리
    $prev_sql = "SELECT 
            s.game_date, 
            s.player_name, 
            s.pa_result, 
            s.sb, 
            s.cs
        FROM kbo_season_records s
        WHERE s.player_id = :player_id 
          AND s.game_date >= :season_start AND s.game_date < :start_date 
        ORDER BY s.game_date ASC, s.game_id ASC, s.inning ASC";
    
    $prev_stmt = $pdo->prepare($prev_sql);
    $prev_stmt->execute([
        'player_id'    => $player_id,
        'season_start' => $season_start_bound,
        'start_date'   => $start_date
    ]);
    $prev_rows = $prev_stmt->fetchAll(PDO::FETCH_ASSOC);

    $cum_ab = 0; $cum_h = 0; $cum_bb = 0; $cum_hbp = 0; $cum_sf = 0; $cum_tb = 0;
    $cum_eff_ab = 0; $cum_eff_tb = 0; $cum_eff_h = 0;
    $cum_eff_ob = 0;

    if (!empty($prev_rows)) {
        foreach ($prev_rows as $p_row) {
            $parsed_p = parseKboResultPHP($p_row['pa_result']); 
            if (!$parsed_p) continue;

            $sb_p = (int)($p_row['sb'] ?? 0);
            $cs_p = (int)($p_row['cs'] ?? 0);
            $pa_txt_p = trim($p_row['pa_result']);

            $eff_ab_p = $parsed_p['ab'];
            $eff_tb_p = $parsed_p['tb'];
            $eff_h_p  = $parsed_p['h'];
            $is_on_base_p = ($parsed_p['h'] > 0 || $parsed_p['obp'] > 0);

            if ($is_on_base_p) {
                if ($sb_p > 0 && $cs_p > 0) {
                    $eff_h_p = 0;
                    $eff_tb_p = 0;
                    if($parsed_p['h'] == 0) {
                        $eff_ab_p = 1;
                        $cum_eff_ob -= 1;
                    }
                } else if ($cs_p > 0) {
                    $eff_h_p = 0;
                    $eff_tb_p = 0;
                    if($parsed_p['h'] == 0) {
                        $eff_ab_p = 1;
                        $cum_eff_ob -= 1;
                    }
                } else if ($sb_p > 0) {
                    $eff_tb_p += $sb_p;
                }
            } else {
                if ($sb_p > 0) {
                    $eff_ab_p = 0;
                }
            }

            $bb_p = (in_array($pa_txt_p, ['4구', '볼넷', '고4'], true) || strpos($pa_txt_p, '볼넷') !== false) ? 1 : 0;
            $hbp_p = (strpos($pa_txt_p, '사구') !== false) ? 1 : 0;
            $sf_p = (strpos($pa_txt_p, '희비') !== false || strpos($pa_txt_p, '희플') !== false) ? 1 : 0;

            $cum_ab  += $parsed_p['ab'];
            $cum_h   += $parsed_p['h'];
            $cum_tb  += $parsed_p['tb'];
            $cum_bb  += $bb_p;
            $cum_hbp += $hbp_p;
            $cum_sf  += $sf_p;
            
            $cum_eff_ab += $eff_ab_p;
            $cum_eff_tb += $eff_tb_p;
            $cum_eff_h  += $eff_h_p;
            $cum_eff_ob += ($bb_p + $hbp_p);
        }

        $p_avg = ($cum_ab > 0) ? ($cum_h / $cum_ab) : 0;
        $p_obp_den = ($cum_ab + $cum_bb + $cum_hbp + $cum_sf);
        $p_eff_obp_den = ($cum_eff_ab + $cum_eff_ob + $cum_sf);
        $p_obp = ($p_obp_den > 0) ? (($cum_h + $cum_bb + $cum_hbp) / $p_obp_den) : 0;
        $p_slg = ($cum_ab > 0) ? ($cum_tb / $cum_ab) : 0;
        $p_ops = $p_obp + $p_slg;
        
        $p_eff_obp = ($p_eff_obp_den > 0) ? (($cum_eff_h + $cum_eff_ob) / $p_eff_obp_den) : 0;
        $p_eff_slg = ($cum_eff_ab > 0) ? ($cum_eff_tb / $cum_eff_ab) : 0;
        $p_eff_ops = $p_eff_obp + $p_eff_slg;

        $prev_close = [
            'avg'      => $p_avg,
            'obp'      => $p_obp,
            'slg'      => $p_slg,
            'ops'      => $p_ops,
            'eff_ops'  => $p_eff_ops,
            'eff_obp'  => $p_eff_obp, // 💡 누적 보존용 변수 추가
            'eff_slg'  => $p_eff_slg  // 💡 누적 보존용 변수 추가
        ];
    } else {
        $prev_close = [
            'avg' => 0, 'obp' => 0, 'slg' => 0, 'ops' => 0, 'eff_ops' => 0, 'eff_obp' => 0, 'eff_slg' => 0
        ];
    }

    // 기간 조회는 선택한 시작일을 0으로 삼는다. 따라서 첫 날짜의 캔들은
    // 시즌 누적 타율이 아니라 해당 조회 기간의 첫 경기 성적을 보여준다.
    $prev_close = [
        'avg' => 0, 'obp' => 0, 'slg' => 0, 'ops' => 0,
        'eff_ops' => 0, 'eff_obp' => 0, 'eff_slg' => 0
    ];
    $cum_ab = 0; $cum_h = 0; $cum_bb = 0; $cum_hbp = 0; $cum_sf = 0; $cum_tb = 0;
    $cum_eff_ab = 0; $cum_eff_tb = 0; $cum_eff_h = 0; $cum_eff_ob = 0;
    $period_pa = 0; $period_1b = 0; $period_2b = 0; $period_3b = 0;
    $period_hr = 0; $period_sb = 0; $period_cs = 0; $period_so = 0;
    $period_game_ids = [];
    $last_ops_plus = 0; $last_eff_ops_plus = 0;

    $sql = "SELECT 
            s.game_date, 
            s.game_id,
            s.player_name, 
            s.pa_result, 
            s.sb, 
            s.cs,
            l.cum_ab AS l_cum_ab,
            l.cum_h AS l_cum_h,
            l.cum_ob AS l_cum_ob,
            l.cum_sf AS l_cum_sf,
            l.cum_tb AS l_cum_tb,
            l.cum_eff_ab, 
            l.cum_eff_h, 
            l.cum_eff_ob, 
            l.cum_eff_tb 
        FROM kbo_season_records s
        LEFT JOIN kbo_league_records l 
            ON s.game_date = l.game_date
        WHERE s.player_id = :player_id 
          AND s.game_date BETWEEN :start_date AND :end_date 
        ORDER BY s.game_date ASC, s.game_id ASC, s.inning ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'player_id'  => $player_id,
        'start_date' => $start_date,
        'end_date'   => $end_date
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode([
            'success' => false,
            'debug_message' => '조회된 데이터가 없습니다.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $player_name = $rows[0]['player_name'] ?? '';

    $rows_by_date = [];
    foreach ($rows as $row) {
        $rows_by_date[$row['game_date']][] = $row;
    }

    $result_output = [];

    foreach ($rows_by_date as $date => $day_rows) {
        $open  = $prev_close;
        $high  = $prev_close;
        $low   = $prev_close;
        $close = $prev_close;
        
        $daily_pa_results = [];
        
        // 💡 핵심 수술 지점: 루프를 돌기 전, 첫 번째 행에서 리그 스탯을 무조건 뽑아내어 스킵 에러를 원천 차단
        $first_row = $day_rows[0];
        $l_ab = (int)($first_row['l_cum_ab'] ?? 0) - $base_l_ab;
        $l_h  = (int)($first_row['l_cum_h'] ?? 0) - $base_l_h;
        $l_ob = (int)($first_row['l_cum_ob'] ?? 0) - $base_l_ob;
        $l_sf = (int)($first_row['l_cum_sf'] ?? 0) - $base_l_sf;
        $l_tb = (int)($first_row['l_cum_tb'] ?? 0) - $base_l_tb;

        $l_eff_ab = (int)($first_row['cum_eff_ab'] ?? 0) - $base_l_eff_ab;
        $l_eff_h  = (int)($first_row['cum_eff_h'] ?? 0) - $base_l_eff_h;
        $l_eff_ob = (int)($first_row['cum_eff_ob'] ?? 0) - $base_l_eff_ob;
        $l_eff_tb = (int)($first_row['cum_eff_tb'] ?? 0) - $base_l_eff_tb;

        foreach ($day_rows as $row) {
            // 도루는 타석 결과가 비어 있는 별도 행으로 저장될 수도 있다.
            $sb = (int)($row['sb'] ?? 0);
            $cs = (int)($row['cs'] ?? 0);
            $period_sb += $sb;
            $period_cs += $cs;
            if (!empty($row['game_id'])) $period_game_ids[(string)$row['game_id']] = true;

            $parsed = parseKboResultPHP($row['pa_result']);
            if (!$parsed) continue;

            $pa_txt = trim($row['pa_result']);
            $period_pa++;
            
            $daily_pa_results[] = formatPaResult($pa_txt, $sb, $cs);

            $eff_ab = $parsed['ab'];
            $eff_tb = $parsed['tb'];
            $eff_h  = $parsed['h'];
            $is_on_base = ($parsed['h'] > 0 || $parsed['obp'] > 0);
            
            if ($is_on_base) {
                if ($sb > 0 && $cs > 0) {
                    $eff_h = 0;
                    $eff_tb = 0;
                    if($parsed['h'] == 0) {
                        $eff_ab = 1;
                        $cum_eff_ob -= 1;
                    }
                } else if ($cs > 0) {
                    $eff_h = 0;
                    $eff_tb = 0;
                    if($parsed['h'] == 0) {
                        $eff_ab = 1;
                        $cum_eff_ob -= 1;
                    }
                } else if ($sb > 0) {
                    $eff_tb += $sb;
                }
            } else {
                if ($sb > 0) { $eff_ab = 0; }
            }

            $bb = (in_array($pa_txt, ['4구', '볼넷', '고4'], true) || strpos($pa_txt, '볼넷') !== false) ? 1 : 0;
            $hbp = (strpos($pa_txt, '사구') !== false) ? 1 : 0;
            $sf = (strpos($pa_txt, '희비') !== false || strpos($pa_txt, '희플') !== false) ? 1 : 0;
            $is_hr = (mb_substr($pa_txt, -1, 1, 'UTF-8') === '홈') ? 1 : 0;
            $is_so = (strpos($pa_txt, '삼진') !== false) ? 1 : 0;
            $last_char = mb_substr($pa_txt, -1, 1, 'UTF-8');
            $period_1b += ($last_char === '안') ? 1 : 0;
            $period_2b += ($last_char === '2') ? 1 : 0;
            $period_3b += ($last_char === '3') ? 1 : 0;

            $cum_ab  += $parsed['ab'];
            $cum_h   += $parsed['h'];
            $cum_tb  += $parsed['tb'];
            $cum_bb  += $bb;
            $cum_hbp += $hbp;
            $cum_sf  += $sf;
            $period_hr += $is_hr;
            $period_so += $is_so;
            
            $cum_eff_ab += $eff_ab;
            $cum_eff_tb += $eff_tb;
            $cum_eff_h  += $eff_h;
            $cum_eff_ob += ($bb + $hbp);

            $current_avg = ($cum_ab > 0) ? ($cum_h / $cum_ab) : 0;
            $obp_den = ($cum_ab + $cum_bb + $cum_hbp + $cum_sf);
            $eff_obp_den = ($cum_eff_ab + $cum_eff_ob + $cum_sf);
            $current_obp = ($obp_den > 0) ? (($cum_h + $cum_bb + $cum_hbp) / $obp_den) : 0;
            $current_slg = ($cum_ab > 0) ? ($cum_tb / $cum_ab) : 0;
            $current_ops = $current_obp + $current_slg;
            
            $current_eff_obp = ($eff_obp_den > 0) ? (($cum_eff_h + $cum_eff_ob) / ($eff_obp_den)) : 0;
            $current_eff_slg = ($cum_eff_ab > 0) ? ($cum_eff_tb / $cum_eff_ab) : 0;
            $current_eff_ops = $current_eff_obp + $current_eff_slg;
            
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
            
            $close = [
                'avg'      => $current_avg,
                'obp'      => $current_obp,
                'slg'      => $current_slg,
                'ops'      => $current_ops,
                'eff_ops'  => $current_eff_ops,
                'eff_obp'  => $current_eff_obp,
                'eff_slg'  => $current_eff_slg
            ];
        }

        // 해당 일자 마감 리그 스탯 연산
        $l_obp_den = $l_ab + $l_ob + $l_sf;
        $league_obp = ($l_obp_den > 0) ? (($l_h + $l_ob) / $l_obp_den) : 0;
        $league_slg = ($l_ab > 0) ? ($l_tb / $l_ab) : 0;

        $l_eff_obp_den = $l_eff_ab + $l_eff_ob + $l_sf;
        $league_eff_obp = ($l_eff_obp_den > 0) ? (($l_eff_h + $l_eff_ob) / $l_eff_obp_den) : 0;
        $league_eff_slg = ($l_eff_ab > 0) ? ($l_eff_tb / $l_eff_ab) : 0;

        // 💡 증발 위험이 있는 $current_obp 대신, 무조건 값이 보존되는 $close 배열 참조로 교체
        $ops_plus = 0;
        if ($league_obp > 0 && $league_slg > 0) {
            $ops_plus = 100 * (($close['obp'] / $league_obp) + ($close['slg'] / $league_slg) - 1);
        }

        $eff_ops_plus = 0;
        if ($league_eff_obp > 0 && $league_eff_slg > 0) {
            $eff_ops_plus = 100 * (($close['eff_obp'] / $league_eff_obp) + ($close['eff_slg'] / $league_eff_slg) - 1);
        }
        $last_ops_plus = $ops_plus;
        $last_eff_ops_plus = $eff_ops_plus;

        $result_output[] = [
            'date' => $date,
            'pa_results' => $daily_pa_results,
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
            ],
            'ops_plus'     => round($ops_plus, 1),
            'eff_ops_plus' => round($eff_ops_plus, 1),
        ];

        $prev_close = $close;
    }

    $period_obp_den = $cum_ab + $cum_bb + $cum_hbp + $cum_sf;
    $period_avg = ($cum_ab > 0) ? ($cum_h / $cum_ab) : 0;
    $period_obp = ($period_obp_den > 0) ? (($cum_h + $cum_bb + $cum_hbp) / $period_obp_den) : 0;
    $period_slg = ($cum_ab > 0) ? ($cum_tb / $cum_ab) : 0;
    $period_babip_den = $cum_ab - $period_so - $period_hr + $cum_sf;
    $period_babip = ($period_babip_den > 0) ? (($cum_h - $period_hr) / $period_babip_den) : null;
    $period_stats = [
        'games' => count($period_game_ids) ?: count($rows_by_date),
        'plate_appearances' => $period_pa,
        'avg' => round($period_avg, 3),
        'obp' => round($period_obp, 3),
        'slg' => round($period_slg, 3),
        'ops' => round($period_obp + $period_slg, 3),
        'eff_ops' => round((float)($prev_close['eff_ops'] ?? 0), 3),
        'ops_plus' => round($last_ops_plus, 1),
        'eff_ops_plus' => round($last_eff_ops_plus, 1),
        'hits' => $cum_h,
        'singles' => $period_1b,
        'doubles' => $period_2b,
        'triples' => $period_3b,
        'home_runs' => $period_hr,
        'walks' => $cum_bb,
        'stolen_bases' => $period_sb,
        'caught_stealing' => $period_cs,
        'stolen_base_percentage' => (($period_sb + $period_cs) > 0)
            ? round($period_sb / ($period_sb + $period_cs), 3)
            : null,
        'bb_per_k' => ($period_so > 0) ? round($cum_bb / $period_so, 3) : null,
        'babip' => ($period_babip !== null) ? round($period_babip, 3) : null,
    ];

    $rankings = null;
    if (($_GET['include_rankings'] ?? '') === '1') {
        try {
            require_once __DIR__ . '/rankings.php';
            $seasonEnd = min($schedule[$year][$season][1] ?? $end_date, date('Y-m-d'));
            $rankings = candleRankings($pdo, $season_start_bound, $seasonEnd, $start_date, $end_date, (string)$player_id);
        } catch (Throwable $rankingError) {
            error_log('Candle ranking failed: ' . $rankingError->getMessage());
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'   => true,
        'name'      => $player_name,
        'player_id' => $player_id,
        'year'      => $year,
        'season'    => $season,
        'start_date'=> $start_date,
        'end_date'  => $end_date,
        'date_preset' => $date_preset,
        'img'       => $img,
        'period_stats' => $period_stats,
        'rankings' => $rankings,
        'data'      => $result_output
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
