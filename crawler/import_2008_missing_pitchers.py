"""Import the four user-supplied 2008 postseason pitcher box scores."""
import argparse
import gzip
import json
import os
from collections import defaultdict
from datetime import datetime
from pathlib import Path

import pymysql

from kbo_candle_crawl import DB_CONFIG, extract_baseball_data


EXPECTED_GAMES = {
    '55551021OBSS0', '55551023SSOB0', '77771026OBSK0', '77771027OBSK0',
}
API_CACHE_ROOT = Path('/home/bitnami/wesiper/backfill-game-details-2008')


def connect():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def resolve_ids(cursor, games):
    refs = [
        (game, team, pitcher)
        for game in games
        for team, pitchers in game['pitchers'].items()
        for pitcher in pitchers
    ]
    rows = [pitcher for _, _, pitcher in refs]
    names = sorted({row['name'] for row in rows if row.get('player_id') is None})
    placeholders = ','.join(['%s'] * len(names))
    candidates = defaultdict(dict)
    cursor.execute(
        f'''SELECT p_name name, p_oldname oldname, p_no player_id, p_img, p_birth
            FROM kbo_playerlist_20250613
            WHERE p_pos='투수' AND (p_name IN ({placeholders}) OR p_oldname IN ({placeholders}))''',
        names + names,
    )
    for player in cursor.fetchall():
        for name in names:
            if name in (player['name'], player['oldname']):
                candidates[name][int(player['player_id'])] = player
    for game, team, row in refs:
        if row.get('player_id') is not None:
            continue
        options = candidates[row['name']]
        if len(options) == 1:
            row['player_id'] = next(iter(options))
            continue
        latest_birth_year = int(game['game_date'][:4]) - 18
        active_age = {
            player_id: player for player_id, player in options.items()
            if (str(player.get('p_birth') or '')[:4].isdigit()
                and int(str(player['p_birth'])[:4]) <= latest_birth_year)
        }
        if len(active_age) == 1:
            row['player_id'] = next(iter(active_age))
            continue
        raise ValueError(
            f'Unresolved pitcher ID {game["game_id"]}/{team}/{row["name"]}: '
            f'{list(options.values())}'
        )


def schedule_code(game):
    return game['game_id'] + game['game_date'][:4]


def winning_hit_pk(cursor, game):
    """Use the cached record API target; use manual input only when API has none."""
    game_id = game['game_id']
    cache_path = API_CACHE_ROOT / f'{game_id}.json'
    if cache_path.exists():
        payload = json.loads(cache_path.read_text(encoding='utf-8'))
        extracted = extract_baseball_data(payload, game_id, include_pitcher_matchups=False)
        api_flags = [row for row in extracted if int(row['is_gwrbi'] or 0) == 1]
        if len(api_flags) > 1:
            raise ValueError(f'{game_id}: API returned multiple winning-hit rows')
        if api_flags:
            row = api_flags[0]
            cursor.execute(
                '''SELECT PK, player_name, inning, pa_result
                   FROM kbo_season_records
                   WHERE game_id=%s AND team=%s AND player_id=%s AND inning=%s
                     AND batting_index=%s''',
                (game_id, row['team'], row['player_id'], row['inning'], row['batting_index']),
            )
            matches = cursor.fetchall()
            if len(matches) != 1:
                raise ValueError(f'{game_id}: API winning-hit row does not map uniquely: {matches}')
            print(f'{game_id} 결승타: API 우선 {matches[0]}', flush=True)
            return matches[0]['PK']

    winning = game['winning_hit']
    cursor.execute(
        '''SELECT PK, player_name, inning, pa_result
           FROM kbo_season_records
           WHERE game_id=%s AND team=%s AND player_name=%s AND inning=%s
             AND batting_index IS NOT NULL''',
        (game_id, winning['team'], winning['player_name'], winning['inning']),
    )
    matches = cursor.fetchall()
    if len(matches) != 1:
        raise ValueError(f'{game_id}: ambiguous manual winning-hit row: {matches}')
    print(f'{game_id} 결승타: 수동 보완 {matches[0]}', flush=True)
    return matches[0]['PK']


def build_plan(cursor, games):
    pitcher_values = []
    matchup_updates = []
    gwrbi_updates = []
    schedule_values = []
    for game in games:
        game_id = game['game_id']
        teams = [game['away_team'], game['home_team']]
        cursor.execute(
            '''SELECT DISTINCT team FROM kbo_season_records WHERE game_id=%s ORDER BY team''',
            (game_id,),
        )
        stored_teams = {row['team'] for row in cursor.fetchall()}
        if stored_teams != set(teams):
            raise ValueError(f'{game_id}: team mismatch DB={stored_teams} input={set(teams)}')
        cursor.execute(
            '''SELECT COUNT(*) rows_count,
                      SUM(pos IS NULL OR pos='') missing_pos,
                      SUM(pos REGEXP '^[A-Z0-9]+$') english_pos
               FROM kbo_season_records WHERE game_id=%s''',
            (game_id,),
        )
        position_check = cursor.fetchone()
        if int(position_check['missing_pos'] or 0) or int(position_check['english_pos'] or 0):
            raise ValueError(f'{game_id}: existing KBO position format is incomplete: {position_check}')

        for pitching_team in teams:
            opponent = next(team for team in teams if team != pitching_team)
            slots = []
            for order, pitcher in enumerate(game['pitchers'][pitching_team], 1):
                slots.extend([pitcher] * int(pitcher['bf']))
                pitcher_values.append((
                    game_id, game['game_date'], pitching_team, pitcher['player_id'],
                    pitcher['inning'], pitcher['record'], pitcher['pitched'], order,
                    pitcher['er'], pitcher['r'],
                ))
            cursor.execute(
                '''SELECT PK FROM kbo_season_records
                   WHERE game_id=%s AND team=%s AND batting_index IS NOT NULL
                   ORDER BY batting_index''',
                (game_id, opponent),
            )
            batters = cursor.fetchall()
            if len(batters) != len(slots):
                raise ValueError(
                    f'{game_id} {opponent}: PAs={len(batters)} != opponent BF={len(slots)}'
                )
            matchup_updates.extend(
                (pitcher['player_id'], pitcher['name'], batter['PK'])
                for batter, pitcher in zip(batters, slots)
            )

        gwrbi_updates.append((winning_hit_pk(cursor, game), game_id))

        cursor.execute(
            '''SELECT game_code, game_date, away_team, home_team
               FROM kbo_schedule WHERE game_code=%s''',
            (schedule_code(game),),
        )
        stored_schedule = cursor.fetchone()
        if stored_schedule is None:
            raise ValueError(f'{game_id}: existing schedule row is missing')
        if (str(stored_schedule['game_date']) != game['game_date']
                or stored_schedule['away_team'] != game['away_team']
                or stored_schedule['home_team'] != game['home_team']):
            raise ValueError(f'{game_id}: schedule identity mismatch: {stored_schedule}')
        schedule_values.append((
            json.dumps(game['away_innings']), json.dumps(game['home_innings']),
            schedule_code(game),
        ))
    return pitcher_values, matchup_updates, gwrbi_updates, schedule_values


def write_backup(cursor, games, backup_dir):
    ids = [game['game_id'] for game in games]
    codes = [schedule_code(game) for game in games]
    placeholders = ','.join(['%s'] * len(ids))
    cursor.execute(
        f'''SELECT PK, game_id, pitcher_id, pitcher_name, is_gwrbi
            FROM kbo_season_records WHERE game_id IN ({placeholders}) ORDER BY PK''', ids,
    )
    data = {'records': cursor.fetchall()}
    cursor.execute(
        f'SELECT * FROM kbo_season_pitch_records WHERE game_id IN ({placeholders})', ids,
    )
    data['pitchers'] = cursor.fetchall()
    placeholders = ','.join(['%s'] * len(codes))
    cursor.execute(f'SELECT * FROM kbo_schedule WHERE game_code IN ({placeholders})', codes)
    data['schedules'] = cursor.fetchall()
    backup_dir.mkdir(parents=True, exist_ok=True)
    path = backup_dir / ('kbo-2008-manual-before-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '.json.gz')
    with gzip.open(path, 'wt', encoding='utf-8') as output:
        json.dump(data, output, ensure_ascii=False, default=str)
    os.chmod(path, 0o600)
    return path


def verify(cursor, games, expected_pitchers, expected_matchups):
    ids = [game['game_id'] for game in games]
    placeholders = ','.join(['%s'] * len(ids))
    cursor.execute(
        f'''SELECT COUNT(*) rows_count, COUNT(DISTINCT game_id) games_count,
                   SUM(`order` IS NULL OR er IS NULL OR r IS NULL) invalid
            FROM kbo_season_pitch_records WHERE game_id IN ({placeholders})''', ids,
    )
    pitchers = cursor.fetchone()
    cursor.execute(
        f'''SELECT COUNT(*) rows_count,
                   SUM(pitcher_id IS NULL OR pitcher_name IS NULL) missing
            FROM kbo_season_records
            WHERE game_id IN ({placeholders}) AND batting_index IS NOT NULL''', ids,
    )
    matchups = cursor.fetchone()
    cursor.execute(
        f'''SELECT game_id, SUM(is_gwrbi=1) flags FROM kbo_season_records
            WHERE game_id IN ({placeholders}) GROUP BY game_id ORDER BY game_id''', ids,
    )
    flags = cursor.fetchall()
    if (int(pitchers['rows_count']) != expected_pitchers
            or int(pitchers['games_count']) != len(games)
            or int(pitchers['invalid'] or 0) != 0
            or int(matchups['rows_count']) != expected_matchups
            or int(matchups['missing'] or 0) != 0
            or any(int(row['flags']) != 1 for row in flags)):
        raise ValueError(f'Post-write verification failed: {pitchers}, {matchups}, {flags}')
    return pitchers, matchups, flags


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', type=Path, default=Path(__file__).with_name('kbo_2008_missing_pitchers.json'))
    parser.add_argument('--backup-dir', type=Path, default=Path('/home/bitnami/wesiper/backups'))
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()
    payload = json.loads(args.input.read_text(encoding='utf-8'))
    games = payload['games']
    if {game['game_id'] for game in games} != EXPECTED_GAMES:
        raise ValueError('Input does not contain the exact four expected games')

    connection = connect()
    try:
        with connection.cursor() as cursor:
            resolve_ids(cursor, games)
            plan = build_plan(cursor, games)
            print(
                f'DRY-RUN: games={len(games)} pitchers={len(plan[0])} '
                f'matchups={len(plan[1])} gwrbi_updates={len(plan[2])} schedules={len(plan[3])}',
                flush=True,
            )
            for game in games:
                print(game['game_id'], {
                    team: [(row['name'], row['player_id'], row['bf']) for row in rows]
                    for team, rows in game['pitchers'].items()
                }, flush=True)
            if not args.write:
                return
            backup = write_backup(cursor, games, args.backup_dir)
            print(f'파일 백업 생성: {backup}', flush=True)
            connection.begin()
            game_ids = [game['game_id'] for game in games]
            placeholders = ','.join(['%s'] * len(game_ids))
            cursor.execute(
                f'DELETE FROM kbo_season_pitch_records WHERE game_id IN ({placeholders})',
                game_ids,
            )
            if plan[1]:
                cursor.executemany(
                    'UPDATE kbo_season_records SET pitcher_id=%s, pitcher_name=%s WHERE PK=%s',
                    plan[1],
                )
            if plan[2]:
                cursor.execute(
                    f'UPDATE kbo_season_records SET is_gwrbi=0 WHERE game_id IN ({placeholders})',
                    game_ids,
                )
                cursor.executemany(
                    '''UPDATE kbo_season_records SET is_gwrbi=1
                       WHERE PK=%s AND game_id=%s''', plan[2],
                )
            cursor.executemany(
                '''INSERT INTO kbo_season_pitch_records
                   (game_id, game_date, team, player_id, inning, record, pitched, `order`, er, r)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''', plan[0],
            )
            cursor.executemany(
                '''UPDATE kbo_schedule
                   SET away_inning_scores=%s, home_inning_scores=%s
                   WHERE game_code=%s''', plan[3],
            )
            result = verify(cursor, games, len(plan[0]), len(plan[1]))
        connection.commit()
        print(f'운영 반영 검증 완료: {result}', flush=True)
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


if __name__ == '__main__':
    main()
