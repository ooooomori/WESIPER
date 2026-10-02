"""Read-only completeness audit for stored KBO games from 2008 through 2026."""
import json
from pathlib import Path

import pymysql

from backfill_batter_positions_gwrbi import build_year_plan
from backfill_kbo_schedule_from_cache import CACHE_ROOT, get_existing, load_rows, verify
from kbo_candle_crawl import DB_CONFIG


START = '2008-01-01'
END = '2027-01-01'
MANUAL_SCHEDULES = {
    '33331011OBLT02012': {
        'game_date': '2012-10-11', 'away_team': '두산', 'home_team': '롯데',
        'away_score': 7, 'home_score': 2, 'stadium': '사직',
        'away_innings': [3, 0, 0, 0, 0, 0, 4, 0, 0],
        'home_innings': [0, 2, 0, 0, 0, 0, 0, 0, 0],
    },
    '77771029SSOB02013': {
        'game_date': '2013-10-29', 'away_team': '삼성', 'home_team': '두산',
        'away_score': 7, 'home_score': 5, 'stadium': '잠실',
        'away_innings': [3, 0, 1, 0, 1, 0, 0, 2, 0],
        'home_innings': [0, 1, 3, 0, 1, 0, 0, 0, 0],
    },
}


def integer(value):
    return int(value or 0)


connection = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
try:
    with connection.cursor() as cursor:
        cursor.execute(
            '''SELECT COUNT(*) rows_count, COUNT(DISTINCT game_id) games_count,
                      COUNT(DISTINCT YEAR(game_date)) years_count,
                      MIN(game_date) first_date, MAX(game_date) last_date,
                      SUM(player_id IS NULL OR player_name IS NULL OR player_name='') bad_player,
                      SUM(team IS NULL OR team='') bad_team,
                      SUM(pos IS NULL OR pos='') bad_pos,
                      SUM(`order` IS NULL OR `order` NOT BETWEEN 1 AND 9) bad_order,
                      SUM(is_gs IS NULL OR is_gs NOT IN (0,1)) bad_is_gs,
                      SUM(rbi IS NULL OR r IS NULL) bad_run_totals,
                      SUM(is_gwrbi IS NULL OR is_gwrbi NOT IN (0,1)) bad_gwrbi,
                      SUM(pa_result IS NOT NULL AND batting_index IS NULL) missing_batting_index,
                      SUM(pa_result IS NULL AND batting_index IS NOT NULL) non_pa_with_index,
                      SUM(pa_result IS NOT NULL AND (pitcher_id IS NULL OR pitcher_name IS NULL)) missing_matchup
               FROM kbo_season_records WHERE game_date >= %s AND game_date < %s''',
            (START, END),
        )
        records = cursor.fetchone()
        for field in (
            'bad_player', 'bad_team', 'bad_pos', 'bad_order', 'bad_is_gs',
            'bad_run_totals', 'bad_gwrbi', 'missing_batting_index',
            'non_pa_with_index', 'missing_matchup',
        ):
            if integer(records[field]):
                raise ValueError(f'kbo_season_records {field}={records[field]}')

        cursor.execute(
            '''SELECT COUNT(*) bad_groups FROM (
                   SELECT game_id, team, COUNT(*) row_count,
                          COUNT(DISTINCT batting_index) distinct_count,
                          MIN(batting_index) min_index, MAX(batting_index) max_index
                   FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s AND batting_index IS NOT NULL
                   GROUP BY game_id, team
                   HAVING min_index<>1 OR max_index<>row_count OR distinct_count<>row_count
               ) invalid''',
            (START, END),
        )
        batting_groups = cursor.fetchone()
        if integer(batting_groups['bad_groups']):
            raise ValueError(f'invalid batting-index groups: {batting_groups}')

        cursor.execute(
            '''SELECT COUNT(*) rows_count, COUNT(DISTINCT game_id) games_count,
                      SUM(team IS NULL OR team='') bad_team,
                      SUM(player_id IS NULL) bad_player,
                      SUM(inning IS NULL OR inning='') bad_inning,
                      SUM(pitched IS NULL OR `order` IS NULL OR er IS NULL OR r IS NULL) bad_stats
               FROM kbo_season_pitch_records WHERE game_date >= %s AND game_date < %s''',
            (START, END),
        )
        pitchers = cursor.fetchone()
        for field in ('bad_team', 'bad_player', 'bad_inning', 'bad_stats'):
            if integer(pitchers[field]):
                raise ValueError(f'kbo_season_pitch_records {field}={pitchers[field]}')
        if integer(pitchers['games_count']) != integer(records['games_count']):
            raise ValueError(f'pitcher game coverage mismatch: {pitchers} vs {records}')

        cursor.execute(
            '''SELECT COUNT(*) bad_groups FROM (
                   SELECT game_id, team, COUNT(*) row_count,
                          COUNT(DISTINCT `order`) distinct_count,
                          MIN(`order`) min_order, MAX(`order`) max_order
                   FROM kbo_season_pitch_records
                   WHERE game_date >= %s AND game_date < %s
                   GROUP BY game_id, team
                   HAVING min_order<>1 OR max_order<>row_count OR distinct_count<>row_count
               ) invalid''',
            (START, END),
        )
        pitching_groups = cursor.fetchone()
        if integer(pitching_groups['bad_groups']):
            raise ValueError(f'invalid pitcher-order groups: {pitching_groups}')

        cursor.execute(
            '''SELECT COUNT(*) bad_games FROM (
                   SELECT game_id, SUM(is_gwrbi=1) flags
                   FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s
                   GROUP BY game_id HAVING flags>1
               ) invalid''',
            (START, END),
        )
        winning_flags = cursor.fetchone()
        if integer(winning_flags['bad_games']):
            raise ValueError(f'multiple winning-hit flags: {winning_flags}')
finally:
    connection.close()

cache_rows_checked = cache_games_checked = cache_flags = 0
for year in range(2008, 2027):
    expected, game_count, flag_count = build_year_plan(year)
    connection = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT PK, pos, is_gwrbi FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            actual = {int(row['PK']): (row['pos'], int(row['is_gwrbi'])) for row in cursor.fetchall()}
    finally:
        connection.close()
    if len(actual) != len(expected):
        raise ValueError(f'{year}: cached/DB row count mismatch {len(expected)} != {len(actual)}')
    mismatches = [
        pk for pos, flag, pk in expected
        if actual.get(int(pk)) != (pos, int(flag))
    ]
    if mismatches:
        raise ValueError(f'{year}: position/winning-hit cache mismatches: {mismatches[:20]}')
    cache_rows_checked += len(expected)
    cache_games_checked += game_count
    cache_flags += flag_count
    print(f'[{year}] cache match: rows={len(expected)} games={game_count} flags={flag_count}', flush=True)

schedule_rows, schedule_skipped = load_rows(Path(CACHE_ROOT))
if set(schedule_skipped) != {'33331011OBLT0', '77771029SSOB0'}:
    raise ValueError(f'unexpected schedule cache exceptions: {schedule_skipped}')
planned = {row[0]: row for row in schedule_rows}
legacy_codes = {code[:13] for code in planned if len(code) == 17}
connection = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
try:
    with connection.cursor() as cursor:
        current_tv = get_existing(cursor)
        verify(cursor, planned, current_tv, legacy_codes)
        placeholders = ','.join(['%s'] * len(MANUAL_SCHEDULES))
        cursor.execute(
            f'''SELECT game_code, game_date, away_team, home_team, away_score, home_score,
                       stadium, away_inning_scores, home_inning_scores
                FROM kbo_schedule WHERE game_code IN ({placeholders})''',
            list(MANUAL_SCHEDULES),
        )
        actual_manual = {row['game_code']: row for row in cursor.fetchall()}
        for code, expected in MANUAL_SCHEDULES.items():
            row = actual_manual.get(code)
            if row is None:
                raise ValueError(f'{code}: missing manually sourced schedule')
            values = {
                'game_date': str(row['game_date']), 'away_team': row['away_team'],
                'home_team': row['home_team'], 'away_score': row['away_score'],
                'home_score': row['home_score'], 'stadium': row['stadium'],
                'away_innings': json.loads(row['away_inning_scores']),
                'home_innings': json.loads(row['home_inning_scores']),
            }
            if values != expected:
                raise ValueError(f'{code}: manual schedule mismatch: {values}')
finally:
    connection.close()

print('AUDIT_OK', {
    'records': records,
    'pitchers': pitchers,
    'batting_groups': batting_groups,
    'pitching_groups': pitching_groups,
    'winning_flags': winning_flags,
    'cache_rows_checked': cache_rows_checked,
    'cache_games_checked': cache_games_checked,
    'cache_flags': cache_flags,
    'schedule_rows_checked': len(schedule_rows) + len(MANUAL_SCHEDULES),
    'schedule_skipped': len(schedule_skipped),
}, flush=True)
