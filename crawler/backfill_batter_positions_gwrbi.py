"""Backfill cached batter positions and exact game-winning plate appearances."""
import argparse
import json
import re
from collections import defaultdict, deque
from pathlib import Path

import pymysql

from kbo_candle_crawl import DB_CONFIG, extract_baseball_data, publish_ranking_revision


CACHE_ROOT = Path('/home/bitnami/wesiper')
GAME_FILE = re.compile(r'\d{8}[A-Z]{4}\d(?:\d{4})?\.json')


def connect():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def ensure_schema(cursor):
    cursor.execute('SHOW COLUMNS FROM kbo_season_records')
    columns = {row['Field'] for row in cursor.fetchall()}
    changes = []
    if 'pos' not in columns:
        changes.append("ADD COLUMN pos VARCHAR(20) NULL AFTER team")
    if 'is_gwrbi' not in columns:
        changes.append("ADD COLUMN is_gwrbi TINYINT(1) NOT NULL DEFAULT 0 AFTER r")
    if changes:
        cursor.execute('ALTER TABLE kbo_season_records ' + ', '.join(changes))
        print('스키마 반영: ' + ', '.join(changes), flush=True)


def cached_payload(year, game_id):
    path = CACHE_ROOT / f'backfill-game-details-{year}' / f'{game_id}.json'
    if not path.exists() or not GAME_FILE.fullmatch(path.name):
        raise ValueError(f'{game_id}: exact cached record response is missing')
    return json.loads(path.read_text(encoding='utf-8'))


def build_year_plan(year):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT PK, game_id, team, player_id, inning, batting_index
                   FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s
                   ORDER BY game_id, PK''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            stored = cursor.fetchall()
    finally:
        connection.close()
    if not stored:
        raise ValueError(f'{year}: no stored batter rows')

    by_game = defaultdict(list)
    for row in stored:
        by_game[row['game_id']].append(row)
    updates = []
    expected_flags = 0

    for game_id, stored_rows in sorted(by_game.items()):
        payload = cached_payload(year, game_id)
        extracted = extract_baseball_data(
            payload, game_id, include_pitcher_matchups=False,
        )
        pa_by_key = {}
        non_pa_by_key = defaultdict(deque)
        player_pos = {}
        for row in extracted:
            team = row['team']
            player_id = int(row['player_id'])
            player_pos[(team, player_id)] = row['pos']
            if row['batting_index'] is not None:
                key = (team, int(row['batting_index']))
                if key in pa_by_key:
                    raise ValueError(f'{game_id}: duplicate extracted batting index {key}')
                pa_by_key[key] = row
            else:
                non_pa_by_key[(team, player_id, row['inning'])].append(row)

        used_pa = set()
        for stored_row in stored_rows:
            team = stored_row['team']
            player_id = int(stored_row['player_id'])
            batting_index = stored_row['batting_index']
            if batting_index is not None:
                key = (team, int(batting_index))
                row = pa_by_key.get(key)
                if row is None or int(row['player_id']) != player_id:
                    raise ValueError(
                        f'{game_id}: stored PA {key}/{player_id} does not match cache'
                    )
                used_pa.add(key)
                updates.append((row['pos'], row['is_gwrbi'], stored_row['PK']))
                expected_flags += row['is_gwrbi']
                continue

            key = (team, player_id, stored_row['inning'])
            if non_pa_by_key[key]:
                row = non_pa_by_key[key].popleft()
                updates.append((row['pos'], 0, stored_row['PK']))
            else:
                # Historical name-only running records can leave an extra
                # non-PA row.  Its player identity still determines position.
                pos = player_pos.get((team, player_id))
                if not pos:
                    raise ValueError(f'{game_id}: no position for stored non-PA row {stored_row}')
                updates.append((pos, 0, stored_row['PK']))

        unused_pa = set(pa_by_key) - used_pa
        unused_non_pa = sum(len(rows) for rows in non_pa_by_key.values())
        if unused_pa or unused_non_pa:
            raise ValueError(
                f'{game_id}: unmatched cache rows: PA={sorted(unused_pa)[:5]} '
                f'non-PA={unused_non_pa}'
            )

    if len(updates) != len(stored):
        raise ValueError(f'{year}: planned updates {len(updates)} != rows {len(stored)}')
    return updates, len(by_game), expected_flags


def write_year(year, updates, expected_flags):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            for offset in range(0, len(updates), 10000):
                cursor.executemany(
                    'UPDATE kbo_season_records SET pos=%s, is_gwrbi=%s WHERE PK=%s',
                    updates[offset:offset + 10000],
                )
        connection.commit()
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT COUNT(*) rows_count,
                          SUM(pos IS NULL OR pos='') missing_pos,
                          SUM(is_gwrbi IS NULL) missing_flag,
                          SUM(is_gwrbi=1) winning_pa,
                          SUM(is_gwrbi NOT IN (0,1)) invalid_flag
                   FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            result = cursor.fetchone()
        if (int(result['rows_count']) != len(updates)
                or int(result['missing_pos'] or 0) != 0
                or int(result['missing_flag'] or 0) != 0
                or int(result['winning_pa'] or 0) != expected_flags
                or int(result['invalid_flag'] or 0) != 0):
            raise ValueError(f'{year}: post-write verification failed: {result}')
        print(f'[{year}] 운영 반영 및 검증 완료: {result}', flush=True)
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--start-year', type=int, default=2008)
    parser.add_argument('--end-year', type=int, default=2026)
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()
    if not 2008 <= args.start_year <= args.end_year <= 2100:
        raise ValueError('Invalid year range')

    if args.write:
        connection = connect()
        try:
            with connection.cursor() as cursor:
                ensure_schema(cursor)
            connection.commit()
        finally:
            connection.close()

    total_rows = total_games = total_flags = 0
    for year in range(args.start_year, args.end_year + 1):
        updates, games, flags = build_year_plan(year)
        total_rows += len(updates)
        total_games += games
        total_flags += flags
        print(
            f'[{year}] 계획 검증: rows={len(updates)} games={games} winning_pa={flags}',
            flush=True,
        )
        if args.write:
            write_year(year, updates, flags)

    print(
        f'전체 검증 완료: rows={total_rows} games={total_games} winning_pa={total_flags}',
        flush=True,
    )
    if args.write:
        publish_ranking_revision()
        print('전체 운영 반영 완료', flush=True)
    else:
        print('DRY-RUN: DB를 변경하지 않았습니다.', flush=True)


if __name__ == '__main__':
    main()
