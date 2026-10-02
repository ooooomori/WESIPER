"""Update stored schedules from official, finished KBO scoreboards."""
import argparse
import json
import os
import re
import time
from datetime import datetime
from pathlib import Path

import pymysql
import requests


def api(method, params, service='Schedule'):
    for attempt in range(3):
        try:
            response = requests.post(
                f'https://www.koreabaseball.com/ws/{service}.asmx/{method}', data=params,
                headers={'User-Agent': 'Mozilla/5.0', 'Referer': 'https://www.koreabaseball.com/',
                         'Origin': 'https://www.koreabaseball.com', 'X-Requested-With': 'XMLHttpRequest'},
                timeout=30)
            response.raise_for_status()
            return response.json()
        except (requests.RequestException, ValueError):
            if attempt == 2:
                raise
            time.sleep(attempt + 1)


def parse_scoreboard(data, game_id):
    if str(data.get('code')) != '100' or data.get('G_ID') != game_id:
        raise ValueError('Invalid scoreboard identity/response')
    if str(data.get('LE_ID')) != '1' or str(data.get('SR_ID')) != '0':
        raise ValueError('Not a regular-season KBO game')
    if data['G_DT'].replace('-', '') != game_id[:8]:
        raise ValueError('Scoreboard date mismatch')
    table = json.loads(data['table2']) if isinstance(data['table2'], str) else data['table2']
    headers = [str(cell['Text']) for cell in table['headers'][0]['row']]
    if headers != [str(i + 1) for i in range(len(headers))] or len(table['rows']) != 2:
        raise ValueError('Unexpected innings structure')
    innings = []
    for row in table['rows']:
        if len(row['row']) != len(headers):
            raise ValueError('Incomplete innings')
        values = []
        for cell in row['row']:
            value = str(cell['Text']).strip()
            if value == '-':
                values.append(None)
            elif re.fullmatch(r'\d+', value):
                values.append(int(value))
            else:
                raise ValueError(f'Unexpected inning score: {value}')
        innings.append(values)
    scores = [int(data['T_SCORE_CN']), int(data['B_SCORE_CN'])]
    if any(sum(n for n in values if n is not None) != score for values, score in zip(innings, scores)):
        raise ValueError('Inning totals do not match final scores')
    if not any(n is not None for n in innings[0]) or min(scores) < 0:
        raise ValueError('Empty or invalid final score')
    # Keep null for unplayed innings (including the bottom of the ninth).
    while innings[0] and innings[0][-1] is None and innings[1][-1] is None:
        innings[0].pop()
        innings[1].pop()
    return (game_id, data['G_DT'], data['AWAY_NM'], data['HOME_NM'], *scores,
            data['S_NM'], json.dumps(innings[0]), json.dumps(innings[1]))


def parse_naver_scoreboard(data, game_id):
    """Parse the already-fetched Naver record response; never perform HTTP here."""
    match = re.fullmatch(r'(\d{8}[A-Z]{4}\d)(\d{4})?', game_id)
    if not match:
        raise ValueError('Invalid Naver game ID')
    code = match[1]
    if data.get('code') != 200 or data.get('success') is not True:
        raise ValueError('Invalid Naver record response')
    record = data['result']['recordData']
    info = record['gameInfo']
    actual_day = str(info.get('gdate'))
    if not re.fullmatch(r'20\d{6}', actual_day):
        raise ValueError('Invalid actual game date')
    if match[2] and match[2] != actual_day[:4]:
        raise ValueError('Naver game year suffix mismatch')
    if code[:8] != actual_day:
        series_flag = str(info.get('gameFlag'))
        if code[:4] != series_flag * 4 or code[4:8] != actual_day[4:8]:
            raise ValueError('Naver pseudo-date game ID mismatch')
    games = [
        game for game in record['games']
        if (game.get('gmkey') or game.get('gameId')) == code
    ]
    if len(games) != 1:
        raise ValueError('Missing or ambiguous matching game')
    game = games[0]
    if game.get('suspendedInfo'):
        return None
    for source in (info, game):
        if str(source.get('gdate')) != actual_day or source.get('aCode') != code[8:10] or source.get('hCode') != code[10:12]:
            raise ValueError('Naver scoreboard identity mismatch')
        if (str(source.get('statusCode')) != '4'
                or ('cancelFlag' in source and source.get('cancelFlag') != 'N')):
            return None  # Do not publish live, suspended or cancelled results.
    if game.get('dheader') is not None and str(game.get('dheader')) != code[-1]:
        # Historical responses use dheader="2" on *both* games of a
        # doubleheader (meaning two games that day), while the exact gmkey
        # suffix still distinguishes game 1 and game 2.
        sibling_codes = {
            (item.get('gmkey') or item.get('gameId')) for item in record['games']
            if str(item.get('gdate')) == actual_day
            and item.get('aCode') == code[8:10]
            and item.get('hCode') == code[10:12]
        }
        expected_pair = {code[:-1] + '1', code[:-1] + '2'}
        if not (str(game.get('dheader')) == '2' and code[-1] in '12'
                and expected_pair <= sibling_codes):
            raise ValueError('Doubleheader identity mismatch')
    board = record['scoreBoard']
    if all(board.get('inn', {}).get(side) == [] and board.get('rheb', {}).get(side) == {}
           for side in ('away', 'home')):
        return None
    innings, totals = [], []
    for side, score_key in [('away', 'aScore'), ('home', 'hScore')]:
        values = board['inn'][side]
        score = board['rheb'][side]['r']
        if not isinstance(values, list) or not values or any(type(n) is not int or n < 0 for n in values):
            raise ValueError('Invalid Naver inning scores')
        if type(score) is not int or score < 0 or sum(values) != score or game['score'][score_key] != score:
            raise ValueError('Naver inning totals do not match final score')
        innings.append(list(values))
        totals.append(score)
    if len(innings[0]) - len(innings[1]) not in (0, 1):
        raise ValueError('Invalid home/away inning lengths')
    if len(innings[0]) != len(innings[1]):
        # Naver omits an unplayed final home half for walk-offs and called games.
        innings[1].append(None)
    if not all(isinstance(info.get(field), str) and info[field].strip() for field in ('aName', 'hName', 'stadium')):
        raise ValueError('Missing team or stadium')
    day = datetime.strptime(actual_day, '%Y%m%d').strftime('%Y-%m-%d')
    stored_code = code if code[:8] == actual_day else code + actual_day[:4]
    return (stored_code, day, info['aName'], info['hName'], *totals, info['stadium'],
            json.dumps(innings[0]), json.dumps(innings[1]))


def update_scoreboards_from_records(fetched_results, config):
    records = []
    seen = set()
    for match in fetched_results:
        row = parse_naver_scoreboard(match['raw_data'], match['game_id'])
        if row is not None and row[0] not in seen:
            records.append(row)
            seen.add(row[0])
    return save_scoreboards(records, config)


def migrate(config):
    connection = pymysql.connect(**config)
    try:
        with connection.cursor() as cursor:
            cursor.execute('SHOW COLUMNS FROM kbo_schedule')
            columns = {row[0]: row for row in cursor.fetchall()}
            additions = [name for name in ('away_inning_scores', 'home_inning_scores') if name not in columns]
            tv_needs_null = columns.get('tv') is not None and columns['tv'][2] != 'YES'
            if not additions and not tv_needs_null:
                return
            # Persist a private data backup before schema changes.
            cursor.execute('SELECT * FROM kbo_schedule')
            names = [column[0] for column in cursor.description]
            rows = [dict(zip(names, row)) for row in cursor.fetchall()]
            backup = Path(__file__).with_name('kbo_schedule-before-innings-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.json')
            fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w', encoding='utf-8') as output:
                json.dump(rows, output, ensure_ascii=False, default=str)
            changes = [f'ADD COLUMN {name} JSON NULL' for name in additions]
            if tv_needs_null:
                changes.append('MODIFY COLUMN tv VARCHAR(255) NULL DEFAULT NULL')
            cursor.execute('ALTER TABLE kbo_schedule ' + ', '.join(changes))
            print(f'Schema updated; backup: {backup}', flush=True)
    finally:
        connection.close()


def update_scoreboards(game_ids, config):
    daily = {}
    records = []
    for game_id in sorted(set(game_ids)):
        if not re.fullmatch(r'\d{8}[A-Z]{4}\d', game_id):
            raise ValueError(f'Invalid game ID: {game_id}')
        day = game_id[:8]
        if day not in daily:
            payload = api('GetKboGameList', {'leId': '1', 'srId': '0', 'date': day}, 'Main')
            if not isinstance(payload.get('game'), list):
                raise ValueError('Invalid daily game response')
            daily[day] = {g['G_ID']: g for g in payload['game']}
        game = daily[day].get(game_id)
        if game is None:
            raise ValueError(f'Game absent from official regular-season schedule: {game_id}')
        if str(game.get('GAME_STATE_SC')) != '3':
            print(f'Skipped unfinished/cancelled game: {game_id}', flush=True)
            continue
        payload = api('GetScoreBoardScroll', {'leId': '1', 'srId': '0', 'seasonId': day[:4], 'gameId': game_id})
        records.append(parse_scoreboard(payload, game_id))
        time.sleep(0.2)
    return save_scoreboards(records, config)


def save_scoreboards(records, config, require_existing=False):
    if not records:
        print('No finished scoreboards to update', flush=True)
        return 0
    connection = pymysql.connect(**config)
    try:
        with connection.cursor() as cursor:
            if require_existing:
                # The existing schedule defines regular-season membership; no KBO request needed.
                placeholders = ','.join(['%s'] * len(records))
                cursor.execute(f'SELECT game_code FROM kbo_schedule WHERE league_level=1 AND game_code IN ({placeholders}) FOR UPDATE',
                               [row[0] for row in records])
                existing = {row[0] for row in cursor.fetchall()}
                missing = {row[0] for row in records} - existing
                if missing:
                    raise ValueError(f'Games absent from stored regular-season schedule: {sorted(missing)}')
            cursor.executemany('''INSERT INTO kbo_schedule
                (league_level,game_code,game_date,away_team,home_team,away_score,home_score,stadium,
                 away_inning_scores,home_inning_scores,tv)
                VALUES (1,%s,%s,%s,%s,%s,%s,%s,%s,%s,NULL)
                ON DUPLICATE KEY UPDATE game_date=VALUES(game_date), away_team=VALUES(away_team),
                home_team=VALUES(home_team), away_score=VALUES(away_score), home_score=VALUES(home_score),
                stadium=VALUES(stadium), away_inning_scores=VALUES(away_inning_scores),
                home_inning_scores=VALUES(home_inning_scores)''', records)
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()
    print(f'Updated {len(records)} KBO scoreboards', flush=True)
    return len(records)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--init-schema', action='store_true')
    parser.add_argument('--game-id', action='append', default=[])
    parser.add_argument('--date', help='Update stored games on YYYY-MM-DD')
    args = parser.parse_args()
    config = dict(host=os.environ['DB_HOST'], user=os.environ['DB_USER'],
                  password=os.environ['DB_PASSWORD'], database=os.environ['DB_NAME'],
                  port=int(os.getenv('DB_PORT', '3306')), charset='utf8mb4')
    if args.init_schema:
        migrate(config)
    ids = args.game_id
    if args.date:
        datetime.strptime(args.date, '%Y-%m-%d')
        connection = pymysql.connect(**config)
        try:
            with connection.cursor() as cursor:
                cursor.execute('SELECT game_code FROM kbo_schedule WHERE league_level=1 AND game_date=%s', (args.date,))
                ids.extend(row[0] for row in cursor.fetchall())
        finally:
            connection.close()
    update_scoreboards(ids, config)
