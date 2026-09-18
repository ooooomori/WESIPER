<?php
// Uses the same PA parser and effective-OPS rules as get_data.php.
function candleRankings(PDO $pdo, string $seasonStart, string $seasonEnd, string $start, string $end, string $playerId): array {
    $revisionPath = getenv('WESIPER_CANDLE_REVISION_FILE') ?: '/tmp/wesiper-candle-data-revision';
    $revision = is_readable($revisionPath) ? trim(file_get_contents($revisionPath)) : 'initial';
    $key = hash('sha256', 'v6|' . implode('|', [$seasonStart, $seasonEnd, $start, $end]));
    $path = sys_get_temp_dir() . '/wesiper-candle-ranks-' . $key . '.json';
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Ranking cache lock failed');
    try {
        clearstatcache(true, $path);
        if (is_file($path)) {
            $cached = json_decode(file_get_contents($path), true);
            if (is_array($cached) && ($cached['revision'] ?? null) === $revision && isset($cached['players'])) return $cached['players'][$playerId] ?? ['season' => null, 'period' => null];
        }
        $sets = ['season' => [], 'period' => []];
        $teamGames = ['season' => [], 'period' => []];
        $stmt = $pdo->prepare('SELECT player_id,game_id,game_date,pa_result,sb,cs FROM kbo_season_records WHERE game_date BETWEEN :start AND :end');
        $stmt->execute(['start' => min($seasonStart, $start), 'end' => max($seasonEnd, $end)]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $text = trim($row['pa_result'] ?? '');
            $p = parseKboResultPHP($text);
            if (!$p) continue;
            $bb = (in_array($text, ['4구','볼넷','고4'], true) || strpos($text, '볼넷') !== false) ? 1 : 0;
            $hbp = strpos($text, '사구') !== false ? 1 : 0;
            $sf = (strpos($text, '희비') !== false || strpos($text, '희플') !== false) ? 1 : 0;
            $sb = (int)$row['sb']; $cs = (int)$row['cs'];
            $eab = $p['ab']; $eh = $p['h']; $etb = $p['tb']; $eob = $bb + $hbp;
            if ($p['h'] > 0 || $p['obp'] > 0) {
                if ($cs > 0) { $eh = 0; $etb = 0; if (!$p['h']) { $eab = 1; $eob--; } }
                elseif ($sb > 0) $etb += $sb;
            } elseif ($sb > 0) $eab = 0;
            $id = (string)$row['player_id']; $game = $row['game_id'];
            foreach (['season' => [$seasonStart,$seasonEnd], 'period' => [$start,$end]] as $scope => $bounds) {
                if ($row['game_date'] < $bounds[0] || $row['game_date'] > $bounds[1]) continue;
                if (!isset($sets[$scope][$id])) $sets[$scope][$id] = array_fill_keys(['pa','ab','hits','tb','bb','hbp','sf','doubles','triples','cs','home_runs','stolen_bases','so','eab','eh','etb','eob'], 0) + ['games'=>[]];
                $s =& $sets[$scope][$id];
                $s['pa']++; $s['ab'] += $p['ab']; $s['hits'] += $p['h']; $s['tb'] += $p['tb'];
                $s['bb'] += $bb; $s['hbp'] += $hbp; $s['sf'] += $sf;
                $s['home_runs'] += mb_substr($text,-1,1,'UTF-8') === '홈' ? 1 : 0;
                $s['doubles'] += mb_substr($text,-1,1,'UTF-8') === '2' ? 1 : 0;
                $s['triples'] += mb_substr($text,-1,1,'UTF-8') === '3' ? 1 : 0;
                $s['cs'] += $cs;
                $s['stolen_bases'] += $sb; $s['so'] += strpos($text,'삼진') !== false ? 1 : 0;
                $s['eab'] += $eab; $s['eh'] += $eh; $s['etb'] += $etb; $s['eob'] += $eob;
                $s['games'][$game] = true;
                foreach ([substr($game,8,2),substr($game,10,2)] as $team) $teamGames[$scope][$team][$game] = true;
                unset($s);
            }
        }
        $all = [];
        foreach ($sets as $scope => $players) {
            $bounds = $scope === 'season' ? [$seasonStart,$seasonEnd] : [$start,$end];
            // Match the chart's season-based league denominator for OPS+.
            $leagueStmt = $pdo->prepare('SELECT * FROM kbo_league_records WHERE year = :year AND game_date <= :end ORDER BY game_date DESC LIMIT 1');
            $leagueStmt->execute(['year'=>substr($seasonStart,0,4),'end'=>$bounds[1]]); $leagueEnd = $leagueStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $leagueStmt = $pdo->prepare('SELECT * FROM kbo_league_records WHERE year = :year AND game_date < :start ORDER BY game_date DESC LIMIT 1');
            $leagueStmt->execute(['year'=>substr($seasonStart,0,4),'start'=>$seasonStart]); $base = $leagueStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $l = []; foreach (['ab','h','ob','sf','tb','eff_ab','eff_h','eff_ob','eff_tb'] as $k) $l[$k] = ($leagueEnd['cum_'.$k] ?? 0) - ($base['cum_'.$k] ?? 0);
            $lobp = ($l['h']+$l['ob']) / max(1,$l['ab']+$l['ob']+$l['sf']); $lslg = $l['tb']/max(1,$l['ab']);
            $leobp = ($l['eff_h']+$l['eff_ob']) / max(1,$l['eff_ab']+$l['eff_ob']+$l['sf']); $leslg = $l['eff_tb']/max(1,$l['eff_ab']);
            foreach ($players as $id => &$s) {
                // Narrow the latest game's two candidates using older opponents.
                $games=array_keys($s['games']); rsort($games,SORT_STRING);
                $candidates=[];
                foreach($games as $g) {
                    $pair=[substr($g,8,2),substr($g,10,2)];
                    if(!$candidates) $candidates=$pair;
                    else {
                        $intersection=array_values(array_intersect($candidates,$pair));
                        if(!$intersection) break;
                        $candidates=$intersection;
                    }
                    if(count($candidates)===1) break;
                }
                $team=count($candidates)===1 ? $candidates[0] : null;
                $s['team_code']=$team;
                $gameCount=0; foreach($candidates as $candidate) $gameCount=max($gameCount,count($teamGames[$scope][$candidate]??[]));
                $required=max(1,(int)floor($gameCount*3.1));
                $s['qualified']=$s['pa'] >= $required; $s['required_pa']=$required;
                $s['avg']=$s['ab'] ? $s['hits']/$s['ab'] : null;
                $den=$s['ab']+$s['bb']+$s['hbp']+$s['sf']; $s['obp']=$den ? ($s['hits']+$s['bb']+$s['hbp'])/$den : null;
                $s['slg']=$s['ab'] ? $s['tb']/$s['ab'] : null; $s['ops']=$s['obp']!==null && $s['slg']!==null ? $s['obp']+$s['slg'] : null;
                $eden=$s['eab']+$s['eob']+$s['sf']; $eobp=$eden>0 ? ($s['eh']+$s['eob'])/$eden : 0; $eslg=$s['eab']>0 ? $s['etb']/$s['eab'] : 0;
                $s['eff_ops']=$eobp+$eslg; $s['ops_plus']=$lobp>0 && $lslg>0 ? 100*($s['obp']/$lobp+$s['slg']/$lslg-1) : null;
                $s['eff_ops_plus']=$leobp>0 && $leslg>0 ? 100*($eobp/$leobp+$eslg/$leslg-1) : null;
                $s['bb_per_k']=$s['so'] ? $s['bb']/$s['so'] : null;
                $babipDen=$s['ab']-$s['so']-$s['home_runs']+$s['sf'];
                $s['babip']=$babipDen>0 ? ($s['hits']-$s['home_runs'])/$babipDen : null;
                $s['plate_appearances']=$s['pa']; $s['walks']=$s['bb']; $s['games']=count($s['games']);
                $s['stolen_base_percentage']=$s['stolen_bases']+$s['cs']>0 ? $s['stolen_bases']/($s['stolen_bases']+$s['cs']) : null;
            } unset($s);
            $countMetrics=['games','plate_appearances','hits','doubles','triples','home_runs','walks','stolen_bases'];
            $metrics=array_merge(['avg','obp','slg','ops','eff_ops','ops_plus','eff_ops_plus','bb_per_k','babip','stolen_base_percentage'],$countMetrics);
            foreach ($players as $id => $s) {
                $ranks=[];
                foreach ($metrics as $metric) {
                    $rate=!in_array($metric,$countMetrics,true);
                    if ($s[$metric]===null || ($rate && !$s['qualified'])) { $ranks[$metric]=null; continue; }
                    $rank=1; foreach ($players as $other) if ((!$rate || $other['qualified']) && $other[$metric]!==null && $other[$metric] > $s[$metric]+1e-10) $rank++;
                    $ranks[$metric]=$rank;
                }
                $all[$id][$scope]=['qualified'=>$s['qualified'],'required_pa'=>$s['required_pa'],'plate_appearances'=>$s['pa'],'team_code'=>$s['team_code'],'ranks'=>$ranks];
            }
        }
        $tmp=tempnam(sys_get_temp_dir(),'candle-rank-');
        if ($tmp!==false) { file_put_contents($tmp,json_encode(['revision'=>$revision,'players'=>$all])); rename($tmp,$path); }
        return $all[$playerId] ?? ['season'=>null,'period'=>null];
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
