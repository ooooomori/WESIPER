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


def migrate(config):
    connection = pymysql.connect(**config)
    try:
        with connection.cursor() as cursor:
            cursor.execute('SHOW COLUMNS FROM kbo_schedule')
            columns = {row[0] for row in cursor.fetchall()}
            additions = [name for name in ('away_inning_scores', 'home_inning_scores') if name not in columns]
            if not additions:
                return
            # Persist a private data backup before schema changes.
            cursor.execute('SELECT * FROM kbo_schedule')
            names = [column[0] for column in cursor.description]
            rows = [dict(zip(names, row)) for row in cursor.fetchall()]
            backup = Path(__file__).with_name('kbo_schedule-before-innings-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.json')
            fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w', encoding='utf-8') as output:
                json.dump(rows, output, ensure_ascii=False, default=str)
            cursor.execute('ALTER TABLE kbo_schedule ' + ', '.join(f'ADD COLUMN {name} JSON NULL' for name in additions))
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
    if not records:
        print('No finished scoreboards to update', flush=True)
        return 0
    connection = pymysql.connect(**config)
    try:
        with connection.cursor() as cursor:
            cursor.executemany('''INSERT INTO kbo_schedule
                (game_code,game_date,away_team,home_team,away_score,home_score,stadium,
                 away_inning_scores,home_inning_scores,tv)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'')
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
                cursor.execute('SELECT game_code FROM kbo_schedule WHERE game_date=%s', (args.date,))
                ids.extend(row[0] for row in cursor.fetchall())
        finally:
            connection.close()
    update_scoreboards(ids, config)
