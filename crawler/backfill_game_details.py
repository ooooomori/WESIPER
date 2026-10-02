"""Backfill batter matchup metadata and pitcher game records from saved game IDs."""
import argparse
import fcntl
import json
import os
import time
from collections import defaultdict, deque
from datetime import datetime
from pathlib import Path

import pymysql
import requests

from kbo_candle_crawl import DB_CONFIG, extract_baseball_data, extract_pitcher_data


HEADERS = {
    'User-Agent': 'Mozilla/5.0',
    'Accept': 'application/json',
    'Referer': 'https://m.sports.naver.com/',
}


def connect():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def save_json(path, data):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
    temporary.replace(path)


def fetch_record(session, game_id, cache_dir):
    path = cache_dir / f'{game_id}.json'
    if path.exists():
        return json.loads(path.read_text(encoding='utf-8'))

    url = f'https://api-gw.sports.naver.com/schedule/games/{game_id}/record'
    for attempt in range(4):
        try:
            response = session.get(url, headers=HEADERS, timeout=30)
            response.raise_for_status()
            data = response.json()
            if data.get('code') != 200 or not data.get('success'):
                raise ValueError(f'API rejected {game_id}')
            save_json(path, data)
            time.sleep(.25)
            return data
        except (requests.RequestException, ValueError):
            if attempt == 3:
                raise
            time.sleep(2 * (attempt + 1))


def normalize_result(value):
    if value is None:
        return None
    value = str(value).strip()
    return value or None


def row_key(row):
    player_id = row.get('player_id')
    if not player_id:
        raise ValueError(f'Missing player ID: {row}')
    inning = row.get('inning')
    return int(player_id), int(inning) if inning is not None else None, normalize_result(row.get('pa_result'))


def existing_game_ids(year):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT game_id, MIN(game_date) AS game_date
                   FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s
                   GROUP BY game_id
                   ORDER BY game_date, game_id''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            rows = cursor.fetchall()
    finally:
        connection.close()
    if not rows:
        raise ValueError(f'{year}: no games in kbo_season_records')
    return [(row['game_id'], str(row['game_date'])) for row in rows]


def current_batter_rows(cursor, game_id):
    cursor.execute(
        '''SELECT PK, player_id, player_name, inning, pa_result, sb, cs
           FROM kbo_season_records WHERE game_id=%s ORDER BY PK''',
        (game_id,),
    )
    return cursor.fetchall()


def reconcile_game(cursor, game_id, game_date, data, include_pitchers=True):
    api_date = str(data.get('result', {}).get('recordData', {}).get('gameInfo', {}).get('gdate') or '')
    if api_date and api_date != game_date.replace('-', ''):
        raise ValueError(f'{game_id}: DB date {game_date} != API date {api_date}')

    extracted = extract_baseball_data(
        data, game_id, include_pitcher_matchups=include_pitchers,
    )
    existing = current_batter_rows(cursor, game_id)
    updates = []
    inserts = []

    # The result API can revise an old scoring decision (for example 우안 -> 1실)
    # after the original crawler stored the PA.  Match real PAs by each player's
    # chronological occurrence and let the current record API correct pa_result.
    existing_pas = defaultdict(list)
    extracted_pas = defaultdict(list)
    for row in existing:
        if normalize_result(row.get('pa_result')) is not None:
            existing_pas[int(row['player_id'])].append(row)
    for row in extracted:
        if row['batting_index'] is not None:
            extracted_pas[int(row['player_id'])].append(row)
    for rows in extracted_pas.values():
        rows.sort(key=lambda item: item['batting_index'])

    for player_id in sorted(set(existing_pas) | set(extracted_pas)):
        stored_rows = existing_pas[player_id]
        api_rows = extracted_pas[player_id]
        if len(stored_rows) != len(api_rows):
            raise ValueError(
                f'{game_id}: player {player_id} PA count differs: '
                f'DB={len(stored_rows)} API={len(api_rows)}'
            )
        for stored, row in zip(stored_rows, api_rows):
            updates.append((
                row['pa_result'], row['pitcher_id'], row['pitcher_name'], row['team'],
                row['pos'], row['rbi'], row['r'], row['is_gwrbi'], row['order'],
                row['is_gs'], row['batting_index'], stored['PK'],
            ))

    # Blank legacy rows represent substitutions/running-only appearances.  Match
    # those by player and inning, normalize pa_result to NULL, and insert only an
    # API non-PA row that did not exist in the legacy table.
    non_pa_buckets = defaultdict(deque)
    for row in existing:
        if normalize_result(row.get('pa_result')) is None:
            non_pa_buckets[row_key(row)].append(row)
    for row in (item for item in extracted if item['batting_index'] is None):
        key = row_key(row)
        if non_pa_buckets[key]:
            stored = non_pa_buckets[key].popleft()
            updates.append((
                None, None, None, row['team'], row['pos'], row['rbi'], row['r'],
                row['is_gwrbi'], row['order'], row['is_gs'], None, stored['PK'],
            ))
        else:
            inserts.append((
                game_id, game_date, row['player_id'], row['player_name'], row['inning'],
                row['pa_result'], row['sb'], row['cs'], row['pitcher_id'], row['pitcher_name'],
                row['team'], row['pos'], row['rbi'], row['r'], row['is_gwrbi'],
                row['order'], row['is_gs'], row['batting_index'],
            ))

    leftovers = [row for bucket in non_pa_buckets.values() for row in bucket]
    if leftovers:
        # etcRecords identifies runners by name only.  When two players in one
        # lineup share a name, the legacy crawler may have retained an extra
        # running-only row that cannot be assigned unambiguously from the API.
        # Preserve that row and its sb/cs values, while filling its new metadata
        # from the matching player ID.
        player_metadata = {}
        for row in extracted:
            player_metadata.setdefault(int(row['player_id']), row)
        unresolved = []
        for stored in leftovers:
            row = player_metadata.get(int(stored['player_id']))
            if row is None:
                unresolved.append(stored)
                continue
            updates.append((
                None, None, None, row['team'], row['pos'], 0, 0, 0, row['order'],
                row['is_gs'], None, stored['PK'],
            ))
        if unresolved:
            raise ValueError(
                f'{game_id}: {len(unresolved)} unmatched existing rows without '
                f'API player metadata, first rows={unresolved[:5]}'
            )

    pitchers = extract_pitcher_data(data, game_id) if include_pitchers else []
    pitcher_values = [(
        game_id, game_date, row['team'], row['player_id'], row['inning'],
        row['record'], row['pitched'], row['order'], row['er'], row['r'],
    ) for row in pitchers]
    return updates, inserts, pitcher_values


def verify_schema(cursor):
    cursor.execute('SHOW COLUMNS FROM kbo_season_records')
    batter_columns = {row['Field'] for row in cursor.fetchall()}
    required = {
        'pitcher_id', 'pitcher_name', 'team', 'pos', 'rbi', 'r', 'is_gwrbi',
        'order', 'is_gs', 'batting_index',
    }
    missing = required - batter_columns
    if missing:
        raise ValueError(f'kbo_season_records missing columns: {sorted(missing)}')
    cursor.execute('SHOW TABLES LIKE %s', ('kbo_season_pitch_records',))
    if cursor.fetchone() is None:
        raise ValueError('kbo_season_pitch_records does not exist')
    cursor.execute('SHOW COLUMNS FROM kbo_season_pitch_records')
    pitch_columns = {row['Field'] for row in cursor.fetchall()}
    pitch_missing = {'order', 'er', 'r'} - pitch_columns
    if pitch_missing:
        raise ValueError(f'kbo_season_pitch_records missing columns: {sorted(pitch_missing)}')


def build_plan(year, cache_dir):
    games = existing_game_ids(year)
    session = requests.Session()
    connection = connect()
    all_updates, all_inserts, all_pitchers = [], [], []
    missing_pitcher_games = []
    try:
        with connection.cursor() as cursor:
            verify_schema(cursor)
            for index, (game_id, game_date) in enumerate(games, 1):
                data = fetch_record(session, game_id, cache_dir)
                pitcher_box = data.get('result', {}).get('recordData', {}).get('pitchersBoxscore', {})
                include_pitchers = bool(pitcher_box.get('away') and pitcher_box.get('home'))
                if not include_pitchers:
                    missing_pitcher_games.append(game_id)
                    print(f'[{year}] 투수 기록 누락: {game_id}', flush=True)
                try:
                    updates, inserts, pitchers = reconcile_game(
                        cursor, game_id, game_date, data, include_pitchers=include_pitchers,
                    )
                except ValueError as exc:
                    # A historical response can contain pitcher rows while
                    # omitting one reliever.  Its pitcher PA total then cannot
                    # be aligned safely with the batting results.  Treat the
                    # entire game's pitcher data as missing, while still
                    # completing all batter-only metadata.
                    if not include_pitchers or '상대 투수 pa 합계' not in str(exc):
                        raise
                    missing_pitcher_games.append(game_id)
                    print(f'[{year}] 투수 기록 불완전: {game_id} ({exc})', flush=True)
                    updates, inserts, pitchers = reconcile_game(
                        cursor, game_id, game_date, data, include_pitchers=False,
                    )
                all_updates.extend(updates)
                all_inserts.extend(inserts)
                all_pitchers.extend(pitchers)
                if index % 25 == 0 or index == len(games):
                    print(
                        f'[{year}] 검증 {index}/{len(games)}: '
                        f'updates={len(all_updates)} inserts={len(all_inserts)} '
                        f'pitchers={len(all_pitchers)}',
                        flush=True,
                    )
    finally:
        connection.close()
        session.close()
    return games, missing_pitcher_games, all_updates, all_inserts, all_pitchers


def backup_tables(cursor, year):
    suffix = datetime.now().strftime('%Y%m%d%H%M%S')
    batter_backup = f'kbo_season_records_bak_{year}_{suffix}'
    pitcher_backup = f'kbo_season_pitch_records_bak_{year}_{suffix}'
    cursor.execute(
        f'''CREATE TABLE `{batter_backup}` AS
            SELECT * FROM kbo_season_records
            WHERE game_date >= %s AND game_date < %s''',
        (f'{year}-01-01', f'{year + 1}-01-01'),
    )
    cursor.execute(
        f'''CREATE TABLE `{pitcher_backup}` AS
            SELECT * FROM kbo_season_pitch_records
            WHERE game_date >= %s AND game_date < %s''',
        (f'{year}-01-01', f'{year + 1}-01-01'),
    )
    return batter_backup, pitcher_backup


def write_plan(year, updates, inserts, pitchers):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            verify_schema(cursor)
            backups = backup_tables(cursor, year)
            print(f'백업 생성: {backups[0]}, {backups[1]}', flush=True)
            connection.begin()
            if updates:
                cursor.executemany(
                    '''UPDATE kbo_season_records SET
                           pa_result=%s, pitcher_id=%s, pitcher_name=%s, team=%s,
                           pos=%s, rbi=%s, r=%s, is_gwrbi=%s,
                           `order`=%s, is_gs=%s, batting_index=%s
                       WHERE PK=%s''',
                    updates,
                )
            if inserts:
                cursor.executemany(
                    '''INSERT INTO kbo_season_records
                       (game_id, game_date, player_id, player_name, inning, pa_result, sb, cs,
                        pitcher_id, pitcher_name, team, pos, rbi, r, is_gwrbi,
                        `order`, is_gs, batting_index)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''',
                    inserts,
                )
            if pitchers:
                cursor.executemany(
                    '''INSERT INTO kbo_season_pitch_records
                       (game_id, game_date, team, player_id, inning, record, pitched,
                        `order`, er, r)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                       ON DUPLICATE KEY UPDATE
                           game_date=VALUES(game_date), team=VALUES(team),
                           inning=VALUES(inning), record=VALUES(record),
                           pitched=VALUES(pitched), `order`=VALUES(`order`),
                           er=VALUES(er), r=VALUES(r)''',
                    pitchers,
                )
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


def verify_written(year, expected_games, expected_pitchers, missing_pitcher_games):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT COUNT(*) AS rows_count,
                          SUM(team IS NULL) AS missing_team,
                          SUM(pos IS NULL OR pos='') AS missing_pos,
                          SUM(is_gwrbi IS NULL) AS missing_is_gwrbi,
                          SUM(`order` IS NULL) AS missing_order,
                          SUM(is_gs IS NULL) AS missing_is_gs,
                          SUM(pa_result IS NOT NULL AND batting_index IS NULL) AS missing_batting_index
                   FROM kbo_season_records
                   WHERE game_date >= %s AND game_date < %s''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            batter_check = cursor.fetchone()
            pitcher_scope = ''
            pitcher_params = [f'{year}-01-01', f'{year + 1}-01-01']
            if missing_pitcher_games:
                placeholders = ','.join(['%s'] * len(missing_pitcher_games))
                pitcher_scope = f' AND game_id NOT IN ({placeholders})'
                pitcher_params.extend(missing_pitcher_games)
            cursor.execute(
                f'''SELECT SUM(pa_result IS NOT NULL AND pitcher_id IS NULL) AS missing_pitcher
                    FROM kbo_season_records
                    WHERE game_date >= %s AND game_date < %s{pitcher_scope}''',
                pitcher_params,
            )
            batter_check['missing_pitcher'] = cursor.fetchone()['missing_pitcher']
            cursor.execute(
                '''SELECT COUNT(*) AS rows_count, COUNT(DISTINCT game_id) AS games_count,
                          SUM(`order` IS NULL) AS missing_order,
                          SUM(er IS NULL) AS missing_er,
                          SUM(r IS NULL) AS missing_r
                   FROM kbo_season_pitch_records
                   WHERE game_date >= %s AND game_date < %s''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            pitcher_check = cursor.fetchone()
    finally:
        connection.close()

    for name in ('missing_team', 'missing_pos', 'missing_is_gwrbi', 'missing_order',
                 'missing_is_gs', 'missing_batting_index', 'missing_pitcher'):
        if int(batter_check[name] or 0) != 0:
            raise ValueError(f'Post-write verification failed: {name}={batter_check[name]}')
    if int(pitcher_check['games_count']) != expected_games:
        raise ValueError(f'Pitcher game count {pitcher_check["games_count"]} != {expected_games}')
    if int(pitcher_check['rows_count']) != expected_pitchers:
        raise ValueError(f'Pitcher rows {pitcher_check["rows_count"]} != {expected_pitchers}')
    for name in ('missing_order', 'missing_er', 'missing_r'):
        if int(pitcher_check[name] or 0) != 0:
            raise ValueError(f'Post-write pitcher verification failed: {name}={pitcher_check[name]}')
    print(f'검증 완료: batters={batter_check}, pitchers={pitcher_check}', flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--year', type=int, default=2026)
    parser.add_argument('--write', action='store_true')
    parser.add_argument('--cache-dir', type=Path)
    args = parser.parse_args()
    if args.year < 2008 or args.year > 2100:
        raise ValueError('Invalid KBO season year')
    if args.cache_dir is None:
        args.cache_dir = Path(f'/home/bitnami/wesiper/backfill-game-details-{args.year}')
    args.cache_dir.mkdir(parents=True, exist_ok=True)

    lock = (args.cache_dir / 'run.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    games, missing_pitcher_games, updates, inserts, pitchers = build_plan(
        args.year, args.cache_dir,
    )
    print(
        f'DRY-RUN 완료: games={len(games)} updates={len(updates)} '
        f'inserts={len(inserts)} pitchers={len(pitchers)} '
        f'missing_pitcher_games={len(missing_pitcher_games)}',
        flush=True,
    )
    if missing_pitcher_games:
        print(f'투수 기록 누락 경기: {",".join(missing_pitcher_games)}', flush=True)
    if not args.write:
        return
    write_plan(args.year, updates, inserts, pitchers)
    verify_written(
        args.year, len(games) - len(missing_pitcher_games), len(pitchers),
        missing_pitcher_games,
    )
    print(f'{args.year} 운영 DB 백필 완료', flush=True)


if __name__ == '__main__':
    main()
