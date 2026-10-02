"""Backfill first-team baserunning outs from cached Naver record responses."""
import argparse
import json
import re
import time
from collections import defaultdict, deque
from pathlib import Path

import pymysql
import requests

from kbo_candle_crawl import DB_CONFIG, extract_baseball_data, publish_ranking_revision


CACHE_ROOT = Path('/home/bitnami/wesiper')
GAME_FILE = re.compile(r'\d{8}[A-Z]{4}\d(?:\d{4})?\.json')
HEADERS = {'User-Agent': 'Mozilla/5.0', 'Accept': 'application/json',
           'Referer': 'https://m.sports.naver.com/'}


def connect():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def cached_payload(year, game_id):
    path = CACHE_ROOT / f'backfill-game-details-{year}' / f'{game_id}.json'
    if not GAME_FILE.fullmatch(path.name):
        raise ValueError(f'{game_id}: invalid cache file name')
    if not path.exists():
        response = requests.get(
            f'https://api-gw.sports.naver.com/schedule/games/{game_id}/record',
            headers=HEADERS, timeout=30,
        )
        response.raise_for_status()
        data = response.json()
        if data.get('code') != 200 or not data.get('success'):
            raise ValueError(f'{game_id}: record API rejected the game')
        path.parent.mkdir(parents=True, exist_ok=True)
        temporary = path.with_suffix('.tmp')
        temporary.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
        temporary.replace(path)
        time.sleep(.25)
    return json.loads(path.read_text(encoding='utf-8'))


def stored_year(year):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT PK, game_id, game_date, team, player_id, inning, pa_result, batting_index
                   FROM kbo_season_records
                   WHERE league_level=1 AND game_date >= %s AND game_date < %s
                   ORDER BY game_id, PK''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            return cursor.fetchall()
    finally:
        connection.close()


def build_year_plan(year):
    stored = stored_year(year)
    if not stored:
        raise ValueError(f'{year}: no stored first-team batter rows')
    by_game = defaultdict(list)
    for row in stored:
        by_game[row['game_id']].append(row)

    updates = []
    inserts = []
    expected_total = 0
    flagged_games = 0
    for game_id, stored_rows in sorted(by_game.items()):
        extracted = extract_baseball_data(
            cached_payload(year, game_id), game_id, include_pitcher_matchups=False,
        )
        flagged = [row for row in extracted if int(row.get('run_out') or 0)]
        if not flagged:
            continue
        flagged_games += 1

        pa_rows = {}
        non_pa_exact = defaultdict(deque)
        non_pa_null = defaultdict(deque)
        for row in stored_rows:
            player_id = int(row['player_id'])
            if row['batting_index'] is not None:
                pa_rows[(row['team'], int(row['batting_index']), player_id)] = row
            elif row['inning'] is None:
                non_pa_null[(row['team'], player_id)].append(row)
            else:
                non_pa_exact[(row['team'], player_id, int(row['inning']))].append(row)

        used = set()
        for row in flagged:
            player_id = int(row['player_id'])
            inning = int(row['inning'])
            if row['batting_index'] is not None:
                key = (row['team'], int(row['batting_index']), player_id)
                target = pa_rows.get(key)
            else:
                key = (row['team'], player_id, inning)
                target = non_pa_exact[key].popleft() if non_pa_exact[key] else None
                if target is None and non_pa_null[(row['team'], player_id)]:
                    target = non_pa_null[(row['team'], player_id)].popleft()
            if target is None:
                inserts.append((
                    1, game_id, stored_rows[0]['game_date'], row['player_id'], row['player_name'],
                    inning, None, int(row.get('sb') or 0), int(row.get('cs') or 0),
                    int(row['run_out']), None, None, row['team'], row['pos'],
                    int(row.get('rbi') or 0), int(row.get('r') or 0), 0,
                    int(row['order']), int(row['is_gs']), None,
                ))
                expected_total += int(row['run_out'])
                continue
            if target['PK'] in used:
                raise ValueError(f'{game_id}: duplicate run-out target PK {target["PK"]}')
            used.add(target['PK'])
            value = int(row['run_out'])
            updates.append((value, inning, target['PK']))
            expected_total += value

    return updates, inserts, len(by_game), flagged_games, expected_total


def write_year(year, updates, inserts, expected_total):
    connection = connect()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                '''UPDATE kbo_season_records SET run_out=0
                   WHERE league_level=1 AND game_date >= %s AND game_date < %s''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            if updates:
                cursor.executemany(
                    '''UPDATE kbo_season_records
                       SET run_out=%s, inning=IF(inning IS NULL,%s,inning)
                       WHERE PK=%s AND league_level=1''',
                    updates,
                )
            if inserts:
                cursor.executemany(
                    '''INSERT INTO kbo_season_records
                       (league_level,game_id,game_date,player_id,player_name,inning,pa_result,
                        sb,cs,run_out,pitcher_id,pitcher_name,team,pos,rbi,r,is_gwrbi,
                        `order`,is_gs,batting_index)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''',
                    inserts,
                )
        connection.commit()
        with connection.cursor() as cursor:
            cursor.execute(
                '''SELECT COALESCE(SUM(run_out),0) AS total,
                          SUM(run_out IS NULL OR run_out < 0) AS invalid
                   FROM kbo_season_records
                   WHERE league_level=1 AND game_date >= %s AND game_date < %s''',
                (f'{year}-01-01', f'{year + 1}-01-01'),
            )
            result = cursor.fetchone()
        if int(result['total']) != expected_total or int(result['invalid'] or 0):
            raise ValueError(f'{year}: post-write verification failed: {result}')
        print(
            f'[{year}] 반영 완료: run_out={expected_total}, '
            f'updated={len(updates)}, inserted={len(inserts)}', flush=True,
        )
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

    totals = [0, 0, 0, 0]
    for year in range(args.start_year, args.end_year + 1):
        updates, inserts, games, flagged_games, run_outs = build_year_plan(year)
        totals = [a + b for a, b in zip(totals, (len(updates) + len(inserts), games, flagged_games, run_outs))]
        print(
            f'[{year}] 계획: games={games}, flagged_games={flagged_games}, '
            f'updated={len(updates)}, inserted={len(inserts)}, run_out={run_outs}',
            flush=True,
        )
        if args.write:
            write_year(year, updates, inserts, run_outs)

    print(
        f'전체: rows={totals[0]}, games={totals[1]}, '
        f'flagged_games={totals[2]}, run_out={totals[3]}',
        flush=True,
    )
    if args.write:
        publish_ranking_revision()
    else:
        print('DRY-RUN: DB를 변경하지 않았습니다.', flush=True)


if __name__ == '__main__':
    main()
