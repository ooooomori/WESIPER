<?php
declare(strict_types=1);
require_once __DIR__.'/player-career.php';
require_once __DIR__.'/player-season-schedule.php';
require_once __DIR__.'/player-year-records.php';
require_once __DIR__.'/player-search-stats.php';

/**
 * KBO BINGO player payload built only from the production DB.
 * Replaces the former koreabaseball.com HitterDetail/PitcherDetail scraping;
 * the response shape (SporkId, Season, Total, Team, Profile, ...) is unchanged.
 */
const BINGO_PLAYER_SOURCE = 'db-v4';
// Official fielding rows (kbo_fielding_records, 2001-) give positions; seasons after this year also read
// game records, which add designated hitters and games the nightly fielding crawl has not picked up yet.
const BINGO_FIELDING_LAST_YEAR = 2025;

function bingoPdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = require dirname(__DIR__).'/config/database.php';
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int)($c['port'] ?? 3306), $c['database'], $c['charset'] ?? 'utf8mb4'),
        $c['username'], $c['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $pdo;
}

/**
 * Cache key part shared by every player: payload format, historical data version, and the year
 * (active players' End and the season bounds follow the current year).
 * Per-player changes are tracked by bingoPlayerCacheKey() in api/kbobingo/player_cache.php.
 */
function bingoPlayerCacheVersion(): string {
    return BINGO_PLAYER_SOURCE.'|'.PROFILE_HISTORY_VERSION.'|'.(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
}

/** Team names/codes stored in record tables -> bingo team codes (ssg, kia, hyd, ...). */
function bingoTeamCode(?string $team): ?string {
    $team = trim((string)$team);
    if ($team === '') return null;
    $first = explode(' ', $team)[0];
    foreach ([$team, $first] as $name) {
        $code = playerCareerTeamCode($name) ?? playerCareerTeamCode(profileTeam($name));
        if ($code !== null) return $code;
    }
    if (str_contains($team, '히어로즈')) return 'kiw';
    return match (strtoupper($first)) { 'HD'=>'hyd', 'SB'=>'sbw', 'WO', 'NX'=>'kiw', default=>null };
}

/** Futures team names: the 고양 club changed owners (원더스 before 2015 is not a KBO club). */
function bingoFuturesTeamCode(?string $team, int $year): ?string {
    $first = explode(' ', trim((string)$team))[0];
    if ($first === '고양') return $year >= 2019 ? 'kiw' : ($year >= 2015 ? 'nc' : null);
    if ($first === '화성') return 'kiw';
    return bingoTeamCode($team);
}

/**
 * Career years exactly as the main-page player search shows them
 * (frontend/src/pages/Main/index.jsx SearchPlayerRow + kbocandle/get_player_list.php):
 * debut = draft year prefix; retired players: end = retire, else the last recorded year.
 * Active players (is_kbodle != 0) show the current year as the end. Unknown sides are null ("?").
 */
function bingoCareerYears(PDO $db, array $player): array {
    $active = (string)($player['is_kbodle'] ?? '0') !== '0';
    $debut = null;
    $currentYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
    if (preg_match('/^(\d{4}|\d{2})/', trim((string)($player['draft'] ?? '')), $m)) {
        $short = (int)$m[1];
        $debut = strlen($m[1]) === 4 ? $short : ($short <= $currentYear % 100 ? 2000 + $short : 1900 + $short);
    }
    if ($active) return ['active' => true, 'debut' => $debut, 'end' => $currentYear];
    $retire = trim((string)($player['retire'] ?? ''));
    $end = $retire !== '' ? $retire : null;
    if ($end === null) {
        $pid = (int)$player['player_id'];
        $end = searchPlayerRecordStats($db, [$pid])[$pid]['last_year'] ?? null;
    }
    return ['active' => $active, 'debut' => $debut, 'end' => $end];
}

function bingoAddPosition(array &$season, string $team, string $pos): void {
    $season[$team]['pos'] ??= [];
    if (!in_array($pos, $season[$team]['pos'], true)) $season[$team]['pos'][] = $pos;
    if (in_array($pos, ['LF','CF','RF'], true) && !in_array('OF', $season[$team]['pos'], true)) $season[$team]['pos'][] = 'OF';
}

function bingoNumber(array $stats, string $key): ?float {
    $value = $stats[$key] ?? null;
    return $value === null || $value === '' || !is_numeric($value) ? null : (float)$value;
}

function bingoOuts(array $stats): ?int {
    if (isset($stats['innings_outs'])) return (int)$stats['innings_outs'];
    if (!isset($stats['innings']) || $stats['innings'] === '') return null;
    try { return profileInningOuts((string)$stats['innings']); } catch (RuntimeException) { return null; }
}

/** Same thresholds the KBO-page parser used. */
function bingoSeasonFlags(array $s, bool $pitcher): array {
    $at = static fn(string $key, float $min) => ($v = bingoNumber($s, $key)) !== null && $v >= $min;
    if ($pitcher) {
        $era = bingoNumber($s, 'era');
        $outs = bingoOuts($s);
        return array_filter([
            'era_3.00_season' => $era !== null && $era <= 3.0,
            'win_10_season' => $at('wins', 10), 'win_15_season' => $at('wins', 15),
            'sv_20_season' => $at('saves', 20), 'hld_10_season' => $at('holds', 10),
            'ip_144_season' => $outs !== null && $outs >= 144 * 3,
            'so_100_season' => $at('so', 100), 'so_150_season' => $at('so', 150),
        ]);
    }
    return array_filter([
        'avg_0.300_season' => $at('avg', 0.3), 'pa_446_season' => $at('pa', 446),
        'h_150_season' => $at('h', 150), '2b_30_season' => $at('doubles', 30),
        '3b_5_season' => $at('triples', 5), 'hr_20_season' => $at('hr', 20),
        'rbi_80_season' => $at('rbi', 80), 'sb_20_season' => $at('sb', 20),
        'slg_0.500_season' => $at('slg', 0.5), 'obp_0.400_season' => $at('obp', 0.4),
        'ops_0.800_season' => $at('ops', 0.8), 'ops_0.900_season' => $at('ops', 0.9),
    ]);
}

function bingoTotalFlags(?array $s, bool $pitcher): array {
    if (!$s) return [];
    $at = static fn(string $key, float $min) => ($v = bingoNumber($s, $key)) !== null && $v >= $min;
    if ($pitcher) {
        $era = bingoNumber($s, 'era');
        return array_filter([
            'era_3.00_total' => $era !== null && $era <= 3.0,
            'win_100_total' => $at('wins', 100),
            'sv_50_total' => $at('saves', 50), 'sv_100_total' => $at('saves', 100),
            'so_800_total' => $at('so', 800),
        ]);
    }
    return array_filter([
        'avg_0.300_total' => $at('avg', 0.3),
        'h_1000_total' => $at('h', 1000), 'h_1500_total' => $at('h', 1500),
        'hr_150_total' => $at('hr', 150), 'rbi_800_total' => $at('rbi', 800),
        'sb_150_total' => $at('sb', 150),
        'slg_0.500_total' => $at('slg', 0.5), 'obp_0.400_total' => $at('obp', 0.4),
        'ops_0.800_total' => $at('ops', 0.8),
    ]);
}

/** Per-team pieces of one year row: modern multi-team splits, historical duplicate rows, or the row itself. */
function bingoYearParts(array $year): array {
    if (!empty($year['teams'])) return $year['teams'];
    if (bingoTeamCode($year['team'] ?? null) === null && !empty($year['series'])) return $year['series'];
    return [$year];
}

/**
 * @param array $player kbo_player_data row: player_id, pos, img, draft, backNo, bat, throw, team, is_kbodle
 * @return array|null null when the player has no KBO first-team (1군) regular-season record
 */
function bingoBuildPlayer(PDO $db, array $player): ?array {
    $pid = (string)(int)$player['player_id'];
    $schedule = profileSchedule();
    $season = []; $teams = []; $total = [];
    $debut = null; $end = null; $lastTeam = null; $lastYear = 0;

    foreach ([false, true] as $pitcher) {
        $records = profileYearRecords($db, $pid, $pitcher, $schedule);
        foreach ($records['rows'] as $row) {
            $year = (int)$row['year'];
            foreach (bingoYearParts($row) as $part) {
                $code = bingoTeamCode($part['team'] ?? null);
                if ($code === null) continue;
                $debut = $debut === null ? $year : min($debut, $year);
                $end = $end === null ? $year : max($end, $year);
                if ($year >= $lastYear) { $lastYear = $year; $lastTeam = $code; }
                $teams[$code] = true;
                $season[$code] ??= ['pos'=>[]];
                $season[$code] += bingoSeasonFlags($part['stats'] ?? [], $pitcher);
            }
        }
        $total += bingoTotalFlags($records['career'] ?? null, $pitcher);
    }
    // Players without a first-team record are not offered in bingo search.
    if ($debut === null) return null;

    // Any Futures (2군) appearance also counts as belonging to that club.
    $q = $db->prepare('SELECT YEAR(game_date) AS year, team FROM kbo_season_records WHERE league_level=2 AND player_id=?
        UNION SELECT YEAR(game_date) AS year, team FROM kbo_season_pitch_records WHERE league_level=2 AND player_id=?
        ORDER BY year');
    $q->execute([$pid, $pid]);
    foreach ($q->fetchAll() as $f) {
        if (($code = bingoFuturesTeamCode($f['team'], (int)$f['year'])) === null) continue;
        $teams[$code] = true;
        $season[$code] ??= ['pos'=>[]];
    }

    // 1982-2000: team-filtered official totals confirm every club of a traded player.
    foreach (['kbo_player_season_batting_totals', 'kbo_player_season_pitching_totals'] as $table) {
        $q = $db->prepare("SELECT DISTINCT team_name FROM `$table` WHERE league_level=1 AND series_id=0 AND year BETWEEN 1982 AND 2000 AND player_id=?");
        $q->execute([$pid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (($code = bingoTeamCode($name)) === null) continue;
            $teams[$code] = true;
            $season[$code] ??= ['pos'=>[]];
        }
    }

    // Positions: official fielding table, then game-record positions for later seasons.
    $names = ['포수'=>'C','1루수'=>'1B','2루수'=>'2B','3루수'=>'3B','유격수'=>'SS','좌익수'=>'LF','중견수'=>'CF','우익수'=>'RF','지명타자'=>'DH','투수'=>'P'];
    $q = $db->prepare('SELECT DISTINCT team, position FROM kbo_fielding_records WHERE player_id=?');
    $q->execute([$pid]);
    foreach ($q->fetchAll() as $f) {
        $code = bingoTeamCode($f['team']); $pos = $names[trim((string)$f['position'])] ?? null;
        if ($code === null || $pos === null) continue;
        $teams[$code] = true;
        bingoAddPosition($season, $code, $pos);
    }
    $bounds = [];
    foreach ($schedule as $year => $s) {
        [$a, $b] = $s['regular'];
        if ((int)$year > BINGO_FIELDING_LAST_YEAR && $a && $b) $bounds[] = '(game_date BETWEEN '.$db->quote($a).' AND '.$db->quote($b).')';
    }
    if ($bounds) {
        $tokens = ['2'=>'C','3'=>'1B','4'=>'2B','5'=>'3B','6'=>'SS','7'=>'LF','8'=>'CF','9'=>'RF','D'=>'DH','지'=>'DH','포'=>'C','一'=>'1B','二'=>'2B','三'=>'3B','유'=>'SS','좌'=>'LF','중'=>'CF','우'=>'RF'];
        $q = $db->prepare('SELECT DISTINCT team, pos FROM kbo_season_records WHERE league_level=1 AND player_id=? AND ('.implode(' OR ', $bounds).')');
        $q->execute([$pid]);
        foreach ($q->fetchAll() as $g) {
            if (($code = bingoTeamCode($g['team'])) === null) continue;
            foreach (preg_split('//u', trim((string)$g['pos']), -1, PREG_SPLIT_NO_EMPTY) as $token)
                if (isset($tokens[$token])) bingoAddPosition($season, $code, $tokens[$token]);
        }
    }

    // Draft club and current club (KBO active players only), as the KBO profile listed them.
    $draft = trim((string)($player['draft'] ?? ''));
    $draftParts = $draft === '' ? [] : preg_split('/\s+/u', $draft);
    if (($code = bingoTeamCode($draftParts[1] ?? null)) !== null) $teams[$code] = true;
    if (in_array((int)($player['is_kbodle'] ?? 0), [1, 2], true) && ($code = bingoTeamCode($player['team'] ?? null)) !== null) $teams[$code] = true;

    // Team fallback image such as 2026_ssg_b_r (team / pitcher-batter / hand).
    $isPitcher = ($player['pos'] ?? '') === '투수';
    $hand = mb_substr(trim((string)($isPitcher ? ($player['throw'] ?? '') : ($player['bat'] ?? ''))), 0, 1);
    $img = $lastTeam !== null
        ? $end.'_'.$lastTeam.'_'.($isPitcher ? 'p' : 'b').'_'.($hand === '' || $hand === '우' ? 'r' : 'l')
        : (string)($player['img'] ?? '');

    $teamList = array_keys($teams);
    $career = bingoCareerYears($db, $player);
    return [
        'SporkId' => (int)$pid,
        'BackNo' => trim((string)($player['backNo'] ?? '')),
        'Img' => $img,
        'Pos' => $player['pos'] ?? null,
        'Profile' => [
            'draft_1r' => isset($draftParts[2]) && in_array($draftParts[2], ['1라운드', '1차'], true),
            'one_club' => count($teamList) === 1,
            'active_2025' => $end >= 2025,
        ],
        'Team' => $teamList,
        // Display years follow the main-page search; $debut/$end above are first-team record years.
        'Debut' => $career['debut'],
        'End' => $career['end'],
        'IsActive' => $career['active'],
        'Season' => $season,
        'Total' => $total,
        'Source' => BINGO_PLAYER_SOURCE,
    ];
}
