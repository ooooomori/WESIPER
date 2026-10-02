"""Fill missing historical pitcher data from official KBO box scores.

The Naver record endpoint omits pitcher rows for a group of historical games.
This backfill uses KBO's official scoreboard/box-score endpoints, resolves the
name-only pitcher identities against already known game matchups and the local
player catalog, and writes only fully validated games.
"""
import argparse
import fcntl
import gzip
import html
import json
import os
import re
import time
from collections import defaultdict
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP
from itertools import permutations
from pathlib import Path

import pymysql
import requests

from kbo_candle_crawl import DB_CONFIG


API_ROOT = 'https://www.koreabaseball.com/ws/Schedule.asmx'
HEADERS = {
    'User-Agent': 'Mozilla/5.0',
    'Referer': 'https://www.koreabaseball.com/',
    'Origin': 'https://www.koreabaseball.com',
    'X-Requested-With': 'XMLHttpRequest',
}
PITCHER_HEADERS = [
    '선수명', '등판', '결과', '승', '패', '세', '이닝', '타자', '투구수',
    '타수', '피안타', '홈런', '4사구', '삼진', '실점', '자책', '평균자책점',
]
RECORD_NAMES = {
    '홀드': '홀', '세이브': '세', '승': '승', '패': '패', '홀': '홀', '세': '세', '무': '무',
}
# These two 2011 names have no same-season player row in the cached Naver data.
# The player catalog identifies the only active-era pitcher for each club:
# 박건우 74363 (1985 birth, 2012 Lotte roster) and 이상훈 78847
# (1985 birth, 2011 SK roster).  The other namesakes were not active in 2011.
PLAYER_ID_OVERRIDES = {
    (2011, 'LG', '박건우'): 74363,
    (2011, 'SK', '이상훈'): 78847,
}
# Official KBO Total.aspx 2011 season lines.  These totals let us distinguish
# players who shared both a display name and a club in the same game.
DUPLICATE_SEASON_TOTALS = {
    (2011, 'SK', '이승호'): (
        {'player_id': 70820, 'g': 51, 'outs': 193, 'bf': 284, 'r': 28, 'er': 25,
         'w': 6, 'l': 3, 's': 2},
        {'player_id': 99137, 'g': 26, 'outs': 180, 'bf': 262, 'r': 29, 'er': 28,
         'w': 6, 'l': 3, 's': 0},
    ),
    (2011, '롯데', '허준혁'): (
        {'player_id': 74556, 'g': 13, 'outs': 56, 'bf': 79, 'r': 10, 'er': 10,
         'w': 0, 'l': 0, 's': 0},
        {'player_id': 79535, 'g': 7, 'outs': 9, 'bf': 16, 'r': 4, 'er': 4,
         'w': 0, 'l': 0, 's': 0},
    ),
}
GAME_ID_RE = re.compile(r'^\d{8}[A-Z]{4}\d(?:\d{4})?$')


def connect():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def clean_text(value):
    return html.unescape(str(value or '')).replace('\xa0', ' ').strip()


def parse_table(value):
    table = json.loads(value) if isinstance(value, str) else value
    if not isinstance(table, dict):
        raise ValueError('KBO table is not an object')
    headers = [
        [clean_text(cell.get('Text')) for cell in group.get('row', [])]
        for group in table.get('headers', [])
    ]
    rows = [
        [clean_text(cell.get('Text')) for cell in group.get('row', [])]
        for group in table.get('rows', [])
    ]
    return headers, rows


def atomic_json(path, payload):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(payload, ensure_ascii=False), encoding='utf-8')
    temporary.replace(path)


def fetch_payload(session, method, params, path):
    if path.exists():
        return json.loads(path.read_text(encoding='utf-8'))
    for attempt in range(4):
        try:
            response = session.post(
                f'{API_ROOT}/{method}', data=params, headers=HEADERS, timeout=30,
            )
            response.raise_for_status()
            payload = response.json()
            atomic_json(path, payload)
            time.sleep(0.08)
            return payload
        except (requests.RequestException, ValueError):
            if attempt == 3:
                raise
            time.sleep(attempt + 1)


def derive_identity(game_id, game_date):
    if not GAME_ID_RE.fullmatch(game_id):
        raise ValueError(f'invalid stored game ID: {game_id}')
    base = game_id[:13]
    actual_day = game_date.replace('-', '')
    if base[:8] == actual_day:
        series_id = '0'
    elif base[:4] == base[0] * 4 and base[4:8] == actual_day[4:8] and base[0] in '1357':
        series_id = base[0]
    else:
        raise ValueError(f'{game_id}: cannot derive KBO series from date {game_date}')
    return actual_day + base[8:], series_id, actual_day[:4]


def validate_score(payload, official_id, game_date, series_id, stored_teams):
    if str(payload.get('code')) != '100':
        raise ValueError(f'KBO scoreboard rejected request: {payload.get("code")} {payload.get("msg")}')
    if payload.get('G_ID') != official_id or str(payload.get('G_DT')) != game_date:
        raise ValueError('KBO scoreboard identity/date mismatch')
    if str(payload.get('LE_ID')) != '1' or str(payload.get('SR_ID')) != series_id:
        raise ValueError('KBO scoreboard league/series mismatch')
    teams = [clean_text(payload.get('AWAY_NM')), clean_text(payload.get('HOME_NM'))]
    if set(teams) != set(stored_teams) or len(set(teams)) != 2:
        raise ValueError(f'KBO/DB team mismatch: KBO={teams}, DB={stored_teams}')

    headers, rows = parse_table(payload.get('table2'))
    if len(headers) != 1 or headers[0] != [str(index + 1) for index in range(len(headers[0]))]:
        raise ValueError('unexpected KBO inning headers')
    if len(rows) != 2 or any(len(row) != len(headers[0]) for row in rows):
        raise ValueError('unexpected KBO inning rows')
    innings = []
    for row in rows:
        parsed = []
        for value in row:
            if value in ('-', 'X', ''):
                parsed.append(None)
            elif re.fullmatch(r'\d+', value):
                parsed.append(int(value))
            else:
                raise ValueError(f'unexpected inning score: {value!r}')
        innings.append(parsed)
    scores = [int(payload['T_SCORE_CN']), int(payload['B_SCORE_CN'])]
    if any(sum(value for value in row if value is not None) != score
           for row, score in zip(innings, scores)):
        raise ValueError('inning totals do not match final score')
    while innings[0] and innings[0][-1] is None and innings[1][-1] is None:
        innings[0].pop()
        innings[1].pop()
    return {
        'official_id': official_id,
        'game_date': game_date,
        'away_team': teams[0],
        'home_team': teams[1],
        'away_score': scores[0],
        'home_score': scores[1],
        'stadium': clean_text(payload.get('S_NM')),
        'away_innings': innings[0],
        'home_innings': innings[1],
    }


def parse_pitchers(payload, score):
    if str(payload.get('code')) != '100':
        raise ValueError(f'KBO box score rejected request: {payload.get("code")} {payload.get("msg")}')
    arrays = payload.get('arrPitcher')
    if not isinstance(arrays, list) or len(arrays) != 2:
        raise ValueError('KBO box score has no two-team pitcher tables')
    output = []
    for side, team, item in zip(('away', 'home'), (score['away_team'], score['home_team']), arrays):
        headers, rows = parse_table(item.get('table'))
        if headers != [PITCHER_HEADERS] or not rows:
            raise ValueError(f'{side} pitcher table has unexpected headers or no rows')
        for appearance_order, values in enumerate(rows, 1):
            if len(values) != len(PITCHER_HEADERS):
                raise ValueError(f'{team} pitcher row has {len(values)} fields')
            name = values[0]
            inning = values[6]
            if not name or not re.fullmatch(r'(?:\d+(?: [0-2]/3)?|[0-2]/3)', inning):
                raise ValueError(f'invalid pitcher identity/inning: {values}')
            integers = {}
            for label, index in (('batters_faced', 7), ('pitched', 8), ('r', 14), ('er', 15)):
                if not re.fullmatch(r'\d+', values[index]):
                    raise ValueError(f'invalid {label} for {team} {name}: {values[index]!r}')
                integers[label] = int(values[index])
            raw_record = values[2]
            record = RECORD_NAMES.get(raw_record, raw_record or None)
            if record not in (None, '승', '패', '홀', '세', '무'):
                raise ValueError(f'unexpected pitcher decision: {raw_record!r}')
            output.append({
                'side': side,
                'team': team,
                'player_name': name,
                'appearance': values[1],
                'order': appearance_order,
                'inning': inning,
                'record': record,
                'season_w': int(values[3]),
                'season_l': int(values[4]),
                'season_s': int(values[5]),
                'season_era': values[16],
                **integers,
            })
    return output


def inning_outs(inning):
    if re.fullmatch(r'\d+', inning):
        return int(inning) * 3
    match = re.fullmatch(r'(\d+ )?([0-2])/3', inning)
    if not match:
        raise ValueError(f'invalid innings value: {inning}')
    return int((match.group(1) or '0').strip()) * 3 + int(match.group(2))


def era_matches(er, outs, displayed):
    if outs == 0:
        return displayed == '-'
    calculated = (Decimal(er * 27) / Decimal(outs)).quantize(
        Decimal('0.01'), rounding=ROUND_HALF_UP,
    )
    return displayed == f'{calculated:.2f}'


def season_state(total):
    return tuple(total[key] for key in ('g', 'outs', 'bf', 'r', 'er', 'w', 'l', 's'))


def add_appearance(state, row, total):
    values = list(state)
    values[0] += 1
    values[1] += inning_outs(row['inning'])
    values[2] += row['batters_faced']
    values[3] += row['r']
    values[4] += row['er']
    values[5] += int(row['record'] == '승')
    values[6] += int(row['record'] == '패')
    values[7] += int(row['record'] == '세')
    if any(value > limit for value, limit in zip(values, season_state(total))):
        return None
    if values[5:8] != [row['season_w'], row['season_l'], row['season_s']]:
        return None
    if not era_matches(values[4], values[1], row['season_era']):
        return None
    return tuple(values)


def resolve_duplicate_seasons(prepared):
    """Return row-specific IDs using cumulative game lines and exact season totals."""
    grouped = defaultdict(list)
    for item in prepared:
        season = int(item['target']['game_date'][:4])
        for row in item['pitchers']:
            key = (season, row['team'], row['player_name'])
            if key in DUPLICATE_SEASON_TOTALS:
                grouped[key].append((item['target']['game_id'], row))

    overrides = {}
    zero = (0, 0, 0, 0, 0, 0, 0, 0)
    for key, appearances in grouped.items():
        totals = DUPLICATE_SEASON_TOTALS[key]
        by_game = defaultdict(list)
        for game_id, row in appearances:
            by_game[game_id].append(row)
        # state -> (assignments, number of distinct ways reaching the state)
        states = {(zero, zero): ({}, 1)}
        for game_id in sorted(by_game):
            rows = sorted(by_game[game_id], key=lambda row: row['order'])
            if len(rows) > len(totals):
                raise ValueError(f'{key} {game_id}: too many same-name appearances')
            choices = list(permutations(range(len(totals)), len(rows)))
            next_states = {}
            for state, (path, path_count) in states.items():
                for choice in choices:
                    updated = list(state)
                    additions = {}
                    valid = True
                    for row, candidate_index in zip(rows, choice):
                        new_value = add_appearance(updated[candidate_index], row, totals[candidate_index])
                        if new_value is None:
                            valid = False
                            break
                        updated[candidate_index] = new_value
                        additions[(game_id, row['team'], row['player_name'], row['order'])] = totals[candidate_index]['player_id']
                    if not valid:
                        continue
                    state_key = tuple(updated)
                    candidate_path = dict(path)
                    candidate_path.update(additions)
                    if state_key in next_states:
                        prior_path, prior_count = next_states[state_key]
                        next_states[state_key] = (prior_path, min(2, prior_count + path_count))
                    else:
                        next_states[state_key] = (candidate_path, path_count)
            states = next_states
            if not states:
                raise ValueError(f'{key}: no valid identity assignment after {game_id}')
        final_state = tuple(season_state(total) for total in totals)
        result = states.get(final_state)
        if result is None:
            raise ValueError(f'{key}: no assignment matches official season totals')
        path, path_count = result
        if path_count != 1:
            raise ValueError(f'{key}: {path_count}+ assignments match official season totals')
        overrides.update(path)
        print(f'동명이인 시즌 검증: {key} appearances={len(appearances)}', flush=True)
    return overrides


def discover_targets(cursor, start_year, end_year):
    params = (f'{start_year}-01-01', f'{end_year + 1}-01-01')
    cursor.execute(
        '''SELECT DISTINCT game_id FROM kbo_season_records
           WHERE game_date >= %s AND game_date < %s
             AND pa_result IS NOT NULL AND pitcher_id IS NULL''',
        params,
    )
    ids = {row['game_id'] for row in cursor.fetchall()}
    cursor.execute(
        '''SELECT DISTINCT r.game_id
           FROM kbo_season_records r
           LEFT JOIN (SELECT DISTINCT game_id FROM kbo_season_pitch_records) p
             ON p.game_id=r.game_id
           WHERE r.game_date >= %s AND r.game_date < %s AND p.game_id IS NULL''',
        params,
    )
    ids.update(row['game_id'] for row in cursor.fetchall())
    if not ids:
        return []
    placeholders = ','.join(['%s'] * len(ids))
    cursor.execute(
        f'''SELECT game_id, MIN(game_date) game_date,
                   GROUP_CONCAT(DISTINCT team ORDER BY team SEPARATOR '\t') teams
            FROM kbo_season_records WHERE game_id IN ({placeholders})
            GROUP BY game_id ORDER BY game_date, game_id''',
        sorted(ids),
    )
    targets = []
    for row in cursor.fetchall():
        teams = str(row['teams'] or '').split('\t')
        if len(teams) != 2:
            raise ValueError(f'{row["game_id"]}: expected two stored teams, got {teams}')
        targets.append({
            'game_id': row['game_id'],
            'game_date': str(row['game_date']),
            'teams': teams,
        })
    return targets


def load_identity_catalog(cursor, start_year, end_year):
    by_exact = defaultdict(set)
    by_team = defaultdict(set)
    by_name = defaultdict(set)
    batter_exact = defaultdict(set)
    batter_team = defaultdict(set)
    cursor.execute(
        '''SELECT game_id, GROUP_CONCAT(DISTINCT team ORDER BY team SEPARATOR '\t') teams
           FROM kbo_season_records
           WHERE game_date >= %s AND game_date < %s
           GROUP BY game_id''',
        (f'{start_year}-01-01', f'{end_year + 1}-01-01'),
    )
    teams_by_game = {row['game_id']: str(row['teams'] or '').split('\t') for row in cursor.fetchall()}
    cursor.execute(
        '''SELECT DISTINCT game_id, YEAR(game_date) season, team batting_team,
                          pitcher_name, pitcher_id
           FROM kbo_season_records
           WHERE game_date >= %s AND game_date < %s
             AND pitcher_id IS NOT NULL AND pitcher_name IS NOT NULL''',
        (f'{start_year}-01-01', f'{end_year + 1}-01-01'),
    )
    for row in cursor.fetchall():
        teams = teams_by_game.get(row['game_id'], [])
        pitching_teams = [team for team in teams if team != row['batting_team']]
        if len(pitching_teams) != 1:
            continue
        key = (int(row['season']), pitching_teams[0], clean_text(row['pitcher_name']))
        player_id = int(row['pitcher_id'])
        by_exact[key].add(player_id)
        by_team[(pitching_teams[0], key[2])].add(player_id)
        by_name[key[2]].add(player_id)

    cursor.execute(
        '''SELECT DISTINCT YEAR(game_date) season, team, player_name, player_id
           FROM kbo_season_records
           WHERE game_date >= %s AND game_date < %s
             AND player_id IS NOT NULL AND player_name IS NOT NULL''',
        (f'{start_year}-01-01', f'{end_year + 1}-01-01'),
    )
    for row in cursor.fetchall():
        key = (int(row['season']), row['team'], clean_text(row['player_name']))
        player_id = int(row['player_id'])
        batter_exact[key].add(player_id)
        batter_team[(row['team'], key[2])].add(player_id)

    player_catalog = defaultdict(set)
    cursor.execute(
        '''SELECT p_name name, p_oldname oldname, p_no player_id
           FROM kbo_playerlist_20250613 WHERE p_pos='투수' AND p_no IS NOT NULL'''
    )
    for row in cursor.fetchall():
        for name in (row['name'], row['oldname']):
            name = clean_text(name)
            if name:
                player_catalog[name].add(int(row['player_id']))
    cursor.execute(
        '''SELECT name, oldname, fullname, player_id
           FROM kbo_player_data WHERE pos='투수' AND player_id IS NOT NULL'''
    )
    for row in cursor.fetchall():
        for name in (row['name'], row['oldname'], row['fullname']):
            name = clean_text(name)
            if name:
                player_catalog[name].add(int(row['player_id']))
    return by_exact, by_team, by_name, batter_exact, batter_team, player_catalog


def resolve_player_id(pitcher, season, catalogs):
    by_exact, by_team, by_name, batter_exact, batter_team, player_catalog = catalogs
    team = pitcher['team']
    name = pitcher['player_name']
    override = PLAYER_ID_OVERRIDES.get((season, team, name))
    if override is not None:
        if override not in player_catalog[name]:
            raise ValueError(f'override {override} is not a pitcher-catalog candidate for {team}/{name}')
        return override, 'reviewed active-era override'
    checks = [
        ('same-season/team matchups', by_exact[(season, team, name)]),
        ('same-season/team player identity', batter_exact[(season, team, name)]),
        ('adjacent-season/team matchups', set().union(
            by_exact[(season - 1, team, name)], by_exact[(season + 1, team, name)],
        )),
        ('all-season/team matchups', by_team[(team, name)]),
        ('all-season/team player identity', batter_team[(team, name)]),
        ('all known matchups', by_name[name]),
        ('player catalog', player_catalog[name]),
    ]
    for source, candidates in checks:
        if len(candidates) == 1:
            return next(iter(candidates)), source
    details = {source: sorted(candidates) for source, candidates in checks if candidates}
    raise ValueError(f'unresolved pitcher ID {team}/{name}: {details}')


def build_game_updates(cursor, target, pitchers):
    updates = []
    for pitching_team in target['teams']:
        opponent = next(team for team in target['teams'] if team != pitching_team)
        team_pitchers = [row for row in pitchers if row['team'] == pitching_team]
        slots = []
        for pitcher in team_pitchers:
            slots.extend([pitcher] * pitcher['batters_faced'])
        cursor.execute(
            '''SELECT PK, batting_index FROM kbo_season_records
               WHERE game_id=%s AND team=%s AND batting_index IS NOT NULL
               ORDER BY batting_index''',
            (target['game_id'], opponent),
        )
        batter_rows = cursor.fetchall()
        if len(batter_rows) != len(slots):
            raise ValueError(
                f'{opponent} PA count {len(batter_rows)} != {pitching_team} pitcher BF {len(slots)}'
            )
        updates.extend(
            (pitcher['player_id'], pitcher['player_name'], batter['PK'])
            for batter, pitcher in zip(batter_rows, slots)
        )
    return updates


def select_schedule_code(cursor, target, score):
    base = target['game_id'][:13]
    candidates = [base]
    if base[:8] != target['game_date'].replace('-', ''):
        candidates.append(base + target['game_date'][:4])
        candidates.append(score['official_id'])
    candidates = list(dict.fromkeys(candidates))
    placeholders = ','.join(['%s'] * len(candidates))
    cursor.execute(
        f'SELECT game_code FROM kbo_schedule WHERE game_code IN ({placeholders})', candidates,
    )
    existing = [row['game_code'] for row in cursor.fetchall()]
    if len(existing) > 1:
        raise ValueError(f'ambiguous schedule rows: {existing}')
    if existing:
        return existing[0]
    return base if base[:8] == target['game_date'].replace('-', '') else base + target['game_date'][:4]


def build_plan(targets, cache_dir):
    session = requests.Session()
    connection = connect()
    prepared = []
    resolved = []
    scores = []
    unresolved = []
    resolution_counts = defaultdict(int)
    try:
        with connection.cursor() as cursor:
            catalogs = load_identity_catalog(cursor, 2008, 2026)
            for index, target in enumerate(targets, 1):
                game_id = target['game_id']
                try:
                    official_id, series_id, season_id = derive_identity(game_id, target['game_date'])
                    params = {
                        'leId': '1', 'srId': series_id, 'seasonId': season_id,
                        'gameId': official_id,
                    }
                    score_payload = fetch_payload(
                        session, 'GetScoreBoardScroll', params, cache_dir / f'{game_id}.score.json',
                    )
                    score = validate_score(
                        score_payload, official_id, target['game_date'], series_id, target['teams'],
                    )
                    score['game_id'] = game_id
                    score['schedule_code'] = select_schedule_code(cursor, target, score)
                    scores.append(score)

                    box_payload = fetch_payload(
                        session, 'GetBoxScoreScroll', params, cache_dir / f'{game_id}.box.json',
                    )
                    pitchers = parse_pitchers(box_payload, score)
                    prepared.append({'target': target, 'score': score, 'pitchers': pitchers})
                except Exception as exc:
                    unresolved.append((game_id, str(exc)))
                if index % 25 == 0 or index == len(targets):
                    print(
                        f'응답 검증 {index}/{len(targets)}: prepared={len(prepared)} '
                        f'unresolved={len(unresolved)}', flush=True,
                    )

            duplicate_overrides = resolve_duplicate_seasons(prepared)
            for index, item in enumerate(prepared, 1):
                game_id = item['target']['game_id']
                try:
                    season = int(item['target']['game_date'][:4])
                    for pitcher in item['pitchers']:
                        row_key = (
                            game_id, pitcher['team'], pitcher['player_name'], pitcher['order'],
                        )
                        if row_key in duplicate_overrides:
                            player_id, source = duplicate_overrides[row_key], 'season-total disambiguation'
                        else:
                            player_id, source = resolve_player_id(pitcher, season, catalogs)
                        pitcher['player_id'] = player_id
                        resolution_counts[source] += 1
                    identity_rows = defaultdict(list)
                    for pitcher in item['pitchers']:
                        identity_rows[pitcher['player_id']].append(
                            (pitcher['team'], pitcher['player_name'], pitcher['order'])
                        )
                    collisions = {
                        player_id: rows for player_id, rows in identity_rows.items() if len(rows) > 1
                    }
                    if collisions:
                        raise ValueError(f'pitcher ID collision: {collisions}')
                    item['updates'] = build_game_updates(cursor, item['target'], item['pitchers'])
                    resolved.append(item)
                except Exception as exc:
                    unresolved.append((game_id, str(exc)))
                if index % 50 == 0 or index == len(prepared):
                    print(
                        f'ID/타석 검증 {index}/{len(prepared)}: resolved={len(resolved)} '
                        f'unresolved={len(unresolved)}', flush=True,
                    )
    finally:
        connection.close()
        session.close()
    print('선수 ID 판정 근거:', dict(sorted(resolution_counts.items())), flush=True)
    return resolved, scores, unresolved


def write_backup(cursor, resolved, scores, backup_dir):
    game_ids = sorted({item['target']['game_id'] for item in resolved})
    schedule_codes = sorted({score['schedule_code'] for score in scores})
    data = {'created_at': datetime.now().isoformat(), 'game_ids': game_ids}
    if game_ids:
        placeholders = ','.join(['%s'] * len(game_ids))
        cursor.execute(
            f'''SELECT PK, game_id, pitcher_id, pitcher_name FROM kbo_season_records
                WHERE game_id IN ({placeholders}) ORDER BY PK''', game_ids,
        )
        data['batter_matchups'] = cursor.fetchall()
        cursor.execute(
            f'''SELECT * FROM kbo_season_pitch_records
                WHERE game_id IN ({placeholders}) ORDER BY game_id, `order`''', game_ids,
        )
        data['pitcher_records'] = cursor.fetchall()
    if schedule_codes:
        placeholders = ','.join(['%s'] * len(schedule_codes))
        cursor.execute(
            f'SELECT * FROM kbo_schedule WHERE game_code IN ({placeholders}) ORDER BY game_code',
            schedule_codes,
        )
        data['schedules'] = cursor.fetchall()
    backup_dir.mkdir(parents=True, exist_ok=True)
    path = backup_dir / ('kbo-official-fallback-before-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.json.gz')
    with gzip.open(path, 'wt', encoding='utf-8') as output:
        json.dump(data, output, ensure_ascii=False, default=str)
    os.chmod(path, 0o600)
    return path


def write_plan(resolved, scores, backup_dir):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            backup = write_backup(cursor, resolved, scores, backup_dir)
            print(f'파일 백업 생성: {backup}', flush=True)
            connection.begin()
            for item in resolved:
                game_id = item['target']['game_id']
                cursor.execute(
                    'SELECT COUNT(*) count FROM kbo_season_pitch_records WHERE game_id=%s FOR UPDATE',
                    (game_id,),
                )
                if int(cursor.fetchone()['count']) != 0:
                    raise ValueError(f'{game_id}: pitcher rows appeared after plan was built')
            updates = [update for item in resolved for update in item['updates']]
            pitcher_values = []
            for item in resolved:
                target = item['target']
                for row in item['pitchers']:
                    pitcher_values.append((
                        target['game_id'], target['game_date'], row['team'], row['player_id'],
                        row['inning'], row['record'], row['pitched'], row['order'], row['er'], row['r'],
                    ))
            if updates:
                cursor.executemany(
                    '''UPDATE kbo_season_records SET pitcher_id=%s, pitcher_name=%s WHERE PK=%s''',
                    updates,
                )
            if pitcher_values:
                cursor.executemany(
                    '''INSERT INTO kbo_season_pitch_records
                       (game_id, game_date, team, player_id, inning, record, pitched, `order`, er, r)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''',
                    pitcher_values,
                )
            schedule_values = [(
                score['schedule_code'], score['game_date'], score['away_team'], score['home_team'],
                score['away_score'], score['home_score'], score['stadium'],
                json.dumps(score['away_innings']), json.dumps(score['home_innings']),
            ) for score in scores]
            if schedule_values:
                cursor.executemany(
                    '''INSERT INTO kbo_schedule
                       (game_code, game_date, away_team, home_team, away_score, home_score,
                        stadium, away_inning_scores, home_inning_scores, tv)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,NULL)
                       ON DUPLICATE KEY UPDATE
                         away_inning_scores=COALESCE(away_inning_scores, VALUES(away_inning_scores)),
                         home_inning_scores=COALESCE(home_inning_scores, VALUES(home_inning_scores))''',
                    schedule_values,
                )
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()
    return len(updates), len(pitcher_values), len(schedule_values)


def verify_after_write(expected_resolved, unresolved_ids):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT COUNT(*) rows_count, COUNT(DISTINCT game_id) games_count
                   FROM kbo_season_records
                   WHERE game_date >= '2008-01-01' AND game_date < '2027-01-01'
                     AND pa_result IS NOT NULL AND pitcher_id IS NULL'''
            )
            matchup = cursor.fetchone()
            cursor.execute(
                '''SELECT COUNT(*) games_count FROM (
                       SELECT DISTINCT r.game_id FROM kbo_season_records r
                       LEFT JOIN (SELECT DISTINCT game_id FROM kbo_season_pitch_records) p
                         ON p.game_id=r.game_id
                       WHERE r.game_date >= '2008-01-01' AND r.game_date < '2027-01-01'
                         AND p.game_id IS NULL
                   ) missing'''
            )
            pitching = cursor.fetchone()
            resolved_ids = sorted(expected_resolved)
            if resolved_ids:
                placeholders = ','.join(['%s'] * len(resolved_ids))
                cursor.execute(
                    f'''SELECT COUNT(DISTINCT game_id) games_count,
                               SUM(`order` IS NULL OR er IS NULL OR r IS NULL) invalid_rows
                        FROM kbo_season_pitch_records WHERE game_id IN ({placeholders})''',
                    resolved_ids,
                )
                resolved_check = cursor.fetchone()
            else:
                resolved_check = {'games_count': 0, 'invalid_rows': 0}
    finally:
        connection.close()
    if int(resolved_check['games_count']) != len(expected_resolved) or int(resolved_check['invalid_rows'] or 0):
        raise ValueError(f'resolved-game verification failed: {resolved_check}')
    if int(pitching['games_count']) != len(unresolved_ids):
        raise ValueError(f'global missing pitcher games {pitching["games_count"]} != {len(unresolved_ids)}')
    print('최종 검증:', {'missing_matchups': matchup, 'missing_pitcher_games': pitching,
                       'resolved': resolved_check}, flush=True)
    return matchup, pitching, resolved_check


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--start-year', type=int, default=2008)
    parser.add_argument('--end-year', type=int, default=2026)
    parser.add_argument('--cache-dir', type=Path, default=Path('/home/bitnami/wesiper/kbo-official-fallback'))
    parser.add_argument('--backup-dir', type=Path, default=Path('/home/bitnami/wesiper/backups'))
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()
    if not (1982 <= args.start_year <= args.end_year <= 2100):
        raise ValueError('invalid season range')
    args.cache_dir.mkdir(parents=True, exist_ok=True)
    lock = (args.cache_dir / 'run.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

    connection = connect()
    try:
        with connection.cursor() as cursor:
            targets = discover_targets(cursor, args.start_year, args.end_year)
    finally:
        connection.close()
    print(f'공식 KBO 보완 대상: {len(targets)}경기', flush=True)
    if not targets:
        return
    resolved, scores, unresolved = build_plan(targets, args.cache_dir)
    total_pitchers = sum(len(item['pitchers']) for item in resolved)
    total_updates = sum(len(item['updates']) for item in resolved)
    print(
        f'DRY-RUN 완료: resolved_games={len(resolved)} pitchers={total_pitchers} '
        f'matchups={total_updates} scoreboards={len(scores)} unresolved={len(unresolved)}',
        flush=True,
    )
    for game_id, reason in unresolved:
        print(f'미해결 {game_id}: {reason}', flush=True)
    if not args.write:
        return
    written = write_plan(resolved, scores, args.backup_dir)
    print(f'운영 반영: matchups={written[0]} pitchers={written[1]} scoreboards={written[2]}', flush=True)
    verify_after_write(
        {item['target']['game_id'] for item in resolved},
        {game_id for game_id, _ in unresolved},
    )


if __name__ == '__main__':
    main()
