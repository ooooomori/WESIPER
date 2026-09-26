"""Collect official monthly schedules; default to a local SQLite database.

python crawler/collect_kbo_schedule.py --year 2026
python crawler/collect_kbo_schedule.py --year 2026 --mysql
"""
import argparse
import html
import json
import os
import re
import sqlite3
from datetime import date
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = """CREATE TABLE IF NOT EXISTS kbo_schedule (
    game_code VARCHAR(32) PRIMARY KEY,
    game_date DATE NOT NULL,
    away_team VARCHAR(40) NOT NULL,
    home_team VARCHAR(40) NOT NULL,
    away_score INTEGER NULL,
    home_score INTEGER NULL,
    tv VARCHAR(255) NOT NULL,
    stadium VARCHAR(80) NOT NULL
)"""
COLUMNS = ('game_code', 'game_date', 'away_team', 'home_team',
           'away_score', 'home_score', 'tv', 'stadium')


def text(value):
    return html.unescape(re.sub('<[^>]+>', ' ', value or '')).strip()


def request_api(service, method, params):
    request = Request(f'https://www.koreabaseball.com/ws/{service}.asmx/{method}',
        data=urlencode(params).encode(), headers={
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'User-Agent': 'Mozilla/5.0', 'Referer': 'https://www.koreabaseball.com/',
            'Origin': 'https://www.koreabaseball.com', 'X-Requested-With': 'XMLHttpRequest'})
    with urlopen(request, timeout=30) as response:
        return json.load(response)


def parse_month(payload, year, month):
    if not isinstance(payload.get('rows'), list):
        raise ValueError('Invalid schedule response')
    games, cancelled, current_date = [], 0, None
    for record in payload['rows']:
        cells = record['row']
        day = next((c for c in cells if c.get('Class') == 'day'), None)
        if day:
            match = re.search(r'(\d{2})\.(\d{2})', text(day['Text']))
            if not match:
                raise ValueError('Invalid day cell')
            current_date = date(year, int(match[1]), int(match[2]))
            if current_date.month != month:
                raise ValueError('Unexpected month')
        play_index = next((i for i, c in enumerate(cells) if c.get('Class') == 'play'), None)
        if play_index is None:
            if any('경기가 없습니다' in text(c.get('Text')) for c in cells):
                continue
            raise ValueError('Unrecognized schedule row')
        if any('취소' in text(c.get('Text')) for c in cells):
            cancelled += 1
            continue
        if current_date is None:
            raise ValueError('Missing date')
        play = cells[play_index]['Text']
        parts = [text(v) for v in re.findall(r'<span\b[^>]*>(.*?)</span>', play, re.S)]
        if len(parts) not in (3, 5) or parts[len(parts) // 2].lower() != 'vs':
            raise ValueError(f'Unrecognized matchup: {play}')
        codes = set(re.findall(r'gameId=([A-Z0-9]+)', html.unescape(' '.join(c.get('Text') or '' for c in cells)), re.I))
        if len(codes) > 1:
            raise ValueError('Conflicting game IDs')
        # Offsets relative to the matchup cell do not change when the date is row-spanned.
        games.append(dict(zip(COLUMNS, (
            next(iter(codes), None), current_date.isoformat(), parts[0], parts[-1],
            int(parts[1]) if len(parts) == 5 else None,
            int(parts[3]) if len(parts) == 5 else None,
            text(cells[play_index + 3]['Text']), text(cells[play_index + 5]['Text']),
        ))))
    return games, cancelled


def resolve_missing_codes(games):
    daily = {}
    excluded = []
    for game in games:
        if game['game_code']:
            continue
        day = game['game_date'].replace('-', '')
        if day not in daily:
            daily[day] = request_api('Main', 'GetKboGameList',
                {'leId': '1', 'srId': '0,9,6', 'date': day})['game']
        matches = [g for g in daily[day] if g.get('AWAY_NM') == game['away_team']
                   and g.get('HOME_NM') == game['home_team']]
        if matches and all(str(g.get('GAME_STATE_SC')) == '4' for g in matches):
            excluded.append(game)
            continue
        matches = [g for g in matches if str(g.get('GAME_STATE_SC')) != '4']
        if len(matches) != 1 or not matches[0].get('G_ID'):
            raise ValueError(f'Cannot unambiguously resolve game code: {game}')
        game['game_code'] = matches[0]['G_ID']
    for game in excluded:
        games.remove(game)
    print(f'Additional cancelled games excluded by state code: {len(excluded)}', flush=True)


def save(games, year, connection, mysql=False):
    cursor = connection.cursor()
    cursor.execute(SCHEMA + (' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' if mysql else ''))
    connection.commit()
    placeholder = '%s' if mysql else '?'
    # Replace only the successfully collected scope, removing games cancelled since the last run.
    try:
        if not games:
            raise ValueError('Refusing to replace schedule with empty data')
        cursor.execute(f'DELETE FROM kbo_schedule WHERE game_date >= {placeholder} AND game_date < {placeholder} AND game_code NOT IN ({",".join([placeholder] * len(games))})',
                       (f'{year}-03-01', f'{year}-11-01', *[g['game_code'] for g in games]))
        # Update only schedule fields, preserving previously collected inning arrays.
        update = (' ON DUPLICATE KEY UPDATE ' + ','.join(f'{c}=VALUES({c})' for c in COLUMNS[1:])) if mysql else (
            ' ON CONFLICT(game_code) DO UPDATE SET ' + ','.join(f'{c}=excluded.{c}' for c in COLUMNS[1:]))
        cursor.executemany(f'INSERT INTO kbo_schedule ({",".join(COLUMNS)}) VALUES ({",".join([placeholder] * 8)})' + update,
                           [tuple(g[c] for c in COLUMNS) for g in games])
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        cursor.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--year', type=int, default=2026)
    parser.add_argument('--mysql', action='store_true')
    parser.add_argument('--sqlite', type=Path, default=ROOT / 'data/kbo_schedule.sqlite3')
    args = parser.parse_args()
    games = []
    for month in range(3, 11):
        payload = request_api('Schedule', 'GetScheduleList', {
            'leId': '1', 'srIdList': '0,9,6', 'seasonId': args.year,
            'gameMonth': f'{month:02}', 'teamId': '',
        })
        rows, cancelled = parse_month(payload, args.year, month)
        print(f'{args.year}-{month:02}: {len(rows)} games, {cancelled} cancelled', flush=True)
        games.extend(rows)
    resolve_missing_codes(games)
    if not games or len({g['game_code'] for g in games}) != len(games):
        raise ValueError('Empty collection or duplicate game codes; database unchanged')
    for game in games:
        if not re.fullmatch(r'\d{8}[A-Z]{4}\d', game['game_code']):
            raise ValueError(f'Unexpected game code: {game["game_code"]}')
        if game['game_code'][:8] != game['game_date'].replace('-', ''):
            raise ValueError('Game date does not match code')
    if args.mysql:
        import pymysql
        connection = pymysql.connect(host=os.environ['DB_HOST'], port=int(os.getenv('DB_PORT', '3306')),
            user=os.environ['DB_USER'], password=os.environ['DB_PASSWORD'],
            database=os.environ['DB_NAME'], charset='utf8mb4', autocommit=False)
    else:
        args.sqlite.parent.mkdir(parents=True, exist_ok=True)
        connection = sqlite3.connect(args.sqlite)
    try:
        save(games, args.year, connection, args.mysql)
    finally:
        connection.close()
    print(f'Saved {len(games)} games to {"MySQL:kbo_schedule" if args.mysql else args.sqlite}')


if __name__ == '__main__':
    main()
