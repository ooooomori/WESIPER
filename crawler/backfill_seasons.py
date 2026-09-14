"""Resumable historical import; checkpoint raw API data before DB writes."""
import argparse
import contextlib
import fcntl
import io
import json
import os
import time
from datetime import date
from pathlib import Path

import pymysql
import requests
from historical_parser import extract_baseball_data
from set_league_stat import aggregate_league_eff_stats

HEADERS = {'User-Agent': 'Mozilla/5.0', 'Referer': 'https://m.sports.naver.com/', 'Accept': 'application/json'}
UNPLAYED_GAMES = {'20140308HTSS0'}  # Confirmed by the user; not inferred from empty records.
TEAMS = ['SS', 'HT', 'NC', 'KT', 'LG', 'SK', 'OB', 'WO', 'LT', 'HH']

def save_json(path, data):
    temp = path.with_suffix('.tmp')
    temp.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
    temp.replace(path)

def request_json(session, url, params=None):
    for attempt in range(4):
        try:
            response = session.get(url, params=params, headers=HEADERS, timeout=30)
            response.raise_for_status()
            data = response.json()
            if data.get('code') != 200 or not data.get('success'):
                raise ValueError('API rejected response')
            return data
        except (requests.RequestException, ValueError):
            if attempt == 3:
                raise
            time.sleep(3 * (attempt + 1))

def schedule(session, year, root):
    path = root / f'schedule-{year}.json'
    if path.exists():
        return json.loads(path.read_text(encoding='utf-8'))
    games = {}
    for month in range(1, 13):
        for team in TEAMS:
            data = request_json(session, 'https://api-gw.sports.naver.com/schedule/calendar', {'upperCategoryId': 'kbaseball', 'categoryIds': 'kbo', 'date': f'{year}-{month:02d}-01', 'teamCode': team})
            for day in data.get('result', {}).get('dates', []) or []:
                for game in day.get('gameInfos') or []:
                    gid = game.get('gameId')
                    if not gid or game.get('statusCode') in ('CANCEL', 'BEFORE'):
                        continue
                    actual_date = date.fromisoformat(day['ymd'])
                    if actual_date.year != year:
                        continue
                    # Playoff prefixes (3333/4444/5555/7777) are not years.
                    prefix = int(gid[:4])
                    id_date = date(prefix if 1900 <= prefix <= 2100 else year, int(gid[4:6]), int(gid[6:8]))
                    if id_date != actual_date:
                        raise ValueError(f'Date mismatch: {gid} / {actual_date}')
                    if gid in games and games[gid] != str(actual_date):
                        raise ValueError(f'Conflicting schedule dates: {gid}')
                    games[gid] = str(actual_date)
            time.sleep(.5)
        print(f'[{year}] {month}월 스케줄 완료: {len(games)}경기', flush=True)
    if not games:
        raise ValueError(f'No schedule games for {year}')
    save_json(path, games)
    return games

def connect():
    return pymysql.connect(host=os.environ['DB_HOST'], user=os.environ['DB_USER'], password=os.environ['DB_PASSWORD'], database=os.environ['DB_NAME'], charset='utf8mb4')

def main():
    args = argparse.ArgumentParser()
    args.add_argument('--start-year', type=int, default=2014)
    args.add_argument('--end-year', type=int, default=2017)
    args.add_argument('--root', type=Path)
    args.add_argument('--probe', action='store_true')
    opt = args.parse_args()
    if not 2008 <= opt.start_year <= opt.end_year <= 2017:
        raise ValueError('Only historical 2008–2017 seasons are allowed')
    if opt.root is None:
        opt.root = Path(f'/home/bitnami/wesiper/backfill-{opt.start_year}-{opt.end_year}')
    opt.root.mkdir(parents=True, exist_ok=True)
    lock = (opt.root / 'run.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    (opt.root / 'exit-code.txt').unlink(missing_ok=True)
    session = requests.Session()
    if opt.probe:
        for gid in ('20080401OBHT0', '77771027OBSK0', '20171001OBHH02017'):
            data = request_json(session, f'https://api-gw.sports.naver.com/schedule/games/{gid}/record')
            rows = extract_baseball_data(data, gid)
            print(gid, len(rows), rows[:2], flush=True)
        return
    warnings_path = opt.root / 'manual-required.txt'
    skipped_path = opt.root / 'empty-records.json'
    skipped = json.loads(skipped_path.read_text(encoding='utf-8')) if skipped_path.exists() else {}
    for year in range(opt.start_year, opt.end_year + 1):
        games = schedule(session, year, opt.root)
        raw_dir = opt.root / str(year)
        raw_dir.mkdir(exist_ok=True)
        manifest_path = opt.root / f'imported-{year}.json'
        imported = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
        conn = connect()
        try:
            with conn.cursor() as cur:
                cur.execute('SELECT game_id,COUNT(*) FROM kbo_season_records WHERE game_date >= %s AND game_date < %s GROUP BY game_id', (f'{year}-01-01',f'{year+1}-01-01'))
                existing_games = dict(cur.fetchall())
        finally:
            conn.close()
        for gid, count in imported.items():
            if existing_games.get(gid) != count:
                raise ValueError(f'Checkpoint / DB mismatch: {gid}')
        for idx, (gid, game_date) in enumerate(sorted(games.items(), key=lambda item:(item[1],item[0])), 1):
            if gid in UNPLAYED_GAMES:
                print(f'[{year}] 미개최 경기 제외: {gid}', flush=True)
                continue
            if gid in imported:
                continue
            raw_path = raw_dir / f'{gid}.json'
            if raw_path.exists():
                data = json.loads(raw_path.read_text(encoding='utf-8'))
            else:
                data = request_json(session, f'https://api-gw.sports.naver.com/schedule/games/{gid}/record')
                save_json(raw_path, data)
                time.sleep(1.5)
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                rows = extract_baseball_data(data, gid)
            warnings = output.getvalue()
            if warnings:
                print(warnings, end='', flush=True)
                with warnings_path.open('a', encoding='utf-8') as log:
                    log.write(warnings)
            if not rows:
                skipped[gid] = game_date
                save_json(skipped_path, skipped)
                print(f'[{year}] 빈 타석 응답 건너뜀: {gid}', flush=True)
                continue
            for row in rows:
                if not row['player_id'] or len(row['player_name']) > 5 or len(row['pa_result']) > 3 or len(gid) > 17:
                    raise ValueError(f'Row cannot fit current DB schema: {gid} / {row}')
            # The PK is auto-increment, not game-unique. Skip existing exact games;
            # never delete or duplicate user-edited records on reruns.
            conn = connect()
            try:
                with conn.cursor() as cur:
                    existing = existing_games.get(gid, 0)
                    if existing:
                        if existing != len(rows):
                            raise ValueError(f'Existing game row-count conflict: {gid}: {existing} vs {len(rows)}')
                    else:
                        cur.executemany('INSERT INTO kbo_season_records (game_id,game_date,player_id,player_name,inning,pa_result,sb,cs) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)', [(gid,game_date,r['player_id'],r['player_name'],r['inning'],r['pa_result'],r['sb'],r['cs']) for r in rows])
                conn.commit()
            except Exception:
                conn.rollback()
                raise
            finally:
                conn.close()
            imported[gid] = len(rows)
            save_json(manifest_path, imported)
            print(f'[{year}] {idx}/{len(games)} {gid}: {len(rows)}행 완료', flush=True)
        print(f'[{year}] 시즌 적재 완료: {len(imported)}경기 / {sum(imported.values())}행', flush=True)
    # All season imports must succeed before a single league aggregation pass.
    for year in range(opt.start_year, opt.end_year + 1):
        aggregate_league_eff_stats(year)
    from kbo_candle_crawl import publish_ranking_revision
    publish_ranking_revision()
    save_json(opt.root / 'completed.json', {'start_year':opt.start_year,'end_year':opt.end_year,'finished_at':time.time()})
    print('전체 시즌 및 리그 집계 완료', flush=True)

if __name__ == '__main__':
    main()
