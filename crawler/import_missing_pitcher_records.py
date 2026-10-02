"""Import manually supplied pitcher box scores for record API omissions."""
import argparse
import re
from collections import defaultdict
from datetime import datetime
from pathlib import Path

import pymysql

from kbo_candle_crawl import DB_CONFIG


GAME_RE = re.compile(r'^\d{8}[A-Z]{4}\d\d{4}$')
TEAM_NAMES = {
    '롯데 자이언츠': '롯데',
    '두산 베어스': '두산',
    '한화 이글스': '한화',
    '키움 히어로즈': '키움',
    'KT 위즈': 'KT',
    '삼성 라이온즈': '삼성',
    'NC 다이노스': 'NC',
    'KIA 타이거즈': 'KIA',
    'LG 트윈스': 'LG',
    'SSG 랜더스': 'SSG',
}
RECORD_NAMES = {'승': '승', '패': '패', '홀드': '홀', '세': '세'}
TEAM_IMAGE_CODES = {
    '롯데': 'lot', '두산': 'doo', '한화': 'han', '키움': 'kiw', 'KT': 'kt',
    '삼성': 'sam', 'NC': 'nc', 'KIA': 'kia', 'LG': 'lg', 'SSG': 'ssg',
}
PLAYER_OVERRIDES = {
    ('20220522KTSS02022', '삼성', '이승현'): 60146,
    ('20220901SSHT02022', '삼성', '이승현'): 51454,
}


def parse_rows(path):
    rows = []
    game_id = None
    team = None
    appearance_order = 0
    for raw_line in path.read_text(encoding='utf-8').splitlines():
        line = raw_line.strip()
        if not line:
            continue
        if GAME_RE.fullmatch(line):
            game_id = line
            team = None
            appearance_order = 0
            continue
        if line.endswith('투수 기록'):
            matches = [short for full, short in TEAM_NAMES.items() if full in line]
            if len(matches) != 1:
                raise ValueError(f'Cannot identify team header: {line}')
            team = matches[0]
            appearance_order = 0
            continue
        if line.startswith('선수명') or line.startswith('TOTAL'):
            continue
        if not game_id or not team:
            raise ValueError(f'Pitcher row without game/team: {line}')
        fields = [value.strip() for value in raw_line.split('\t')]
        if len(fields) != 17:
            raise ValueError(f'Expected 17 columns, got {len(fields)}: {fields}')
        appearance_order += 1
        rows.append({
            'game_id': game_id,
            'game_date': f'{game_id[:4]}-{game_id[4:6]}-{game_id[6:8]}',
            'team': team,
            'player_name': fields[0],
            'order': appearance_order,
            'record': RECORD_NAMES.get(fields[2]) if fields[2] else None,
            'inning': fields[6],
            'batters_faced': int(fields[7]),
            'pitched': int(fields[8]),
            'r': int(fields[14]),
            'er': int(fields[15]),
        })
    games = {row['game_id'] for row in rows}
    if len(games) != 10:
        raise ValueError(f'Expected 10 games, parsed {len(games)}: {sorted(games)}')
    return rows


def connect():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def resolve_player_ids(cursor, rows):
    names = sorted({row['player_name'] for row in rows})
    placeholders = ','.join(['%s'] * len(names))
    cursor.execute(
        f'''SELECT name, oldname, fullname, player_id, pos, img
            FROM kbo_player_data
            WHERE name IN ({placeholders}) OR oldname IN ({placeholders}) OR fullname IN ({placeholders})''',
        names + names + names,
    )
    candidates = defaultdict(list)
    for player in cursor.fetchall():
        if player['pos'] != '투수' or not player['player_id']:
            continue
        for name in names:
            if name in (player['name'], player['oldname'], player['fullname']):
                candidates[name].append(player)

    unresolved = {}
    for row in rows:
        unique = {int(player['player_id']): player for player in candidates[row['player_name']]}
        override = PLAYER_OVERRIDES.get((row['game_id'], row['team'], row['player_name']))
        if override is not None:
            if override not in unique:
                raise ValueError(f'Override {override} is not a player-list candidate for {row}')
            row['player_id'] = override
            continue
        if len(unique) == 1:
            row['player_id'] = next(iter(unique))
            continue

        team_token = TEAM_IMAGE_CODES[row['team']]
        team_matches = {
            pno: player for pno, player in unique.items()
            if f'_{team_token}_' in str(player.get('img') or '')
        }
        pool = team_matches or unique
        years = defaultdict(list)
        for pno, player in pool.items():
            image = str(player.get('img') or '')
            match = re.match(r'^(\d{4})_', image)
            years[int(match.group(1)) if match else 0].append(pno)
        newest = years[max(years)] if years else []
        if len(newest) == 1:
            row['player_id'] = newest[0]
            continue
        unresolved[f'{row["game_id"]}/{row["team"]}/{row["player_name"]}'] = list(unique.values())
    if unresolved:
        for name, options in unresolved.items():
            print(f'선수 ID 후보 {name}: {options}', flush=True)
        raise ValueError(f'Unresolved or ambiguous pitcher names: {sorted(unresolved)}')


def build_matchup_updates(cursor, rows):
    by_game_team = defaultdict(list)
    teams_by_game = defaultdict(set)
    for row in rows:
        by_game_team[(row['game_id'], row['team'])].append(row)
        teams_by_game[row['game_id']].add(row['team'])

    updates = []
    for game_id, teams in sorted(teams_by_game.items()):
        if len(teams) != 2:
            raise ValueError(f'{game_id}: expected two teams, got {sorted(teams)}')
        for pitching_team in sorted(teams):
            opponent = next(team for team in teams if team != pitching_team)
            slots = []
            for pitcher in by_game_team[(game_id, pitching_team)]:
                slots.extend([pitcher] * pitcher['batters_faced'])
            cursor.execute(
                '''SELECT PK, batting_index FROM kbo_season_records
                   WHERE game_id=%s AND team=%s AND batting_index IS NOT NULL
                   ORDER BY batting_index''',
                (game_id, opponent),
            )
            batter_rows = cursor.fetchall()
            if len(batter_rows) != len(slots):
                raise ValueError(
                    f'{game_id} {opponent}: batter rows {len(batter_rows)} '
                    f'!= pitcher BF {len(slots)}'
                )
            updates.extend((pitcher['player_id'], pitcher['player_name'], batter['PK'])
                           for batter, pitcher in zip(batter_rows, slots))
    return updates


def backup_targets(cursor, game_ids):
    suffix = datetime.now().strftime('%Y%m%d%H%M%S')
    placeholders = ','.join(['%s'] * len(game_ids))
    pitch_backup = f'kbo_season_pitch_records_manual_bak_{suffix}'
    batter_backup = f'kbo_season_records_matchup_bak_{suffix}'
    cursor.execute(
        f'CREATE TABLE `{pitch_backup}` AS SELECT * FROM kbo_season_pitch_records '
        f'WHERE game_id IN ({placeholders})', game_ids,
    )
    cursor.execute(
        f'''CREATE TABLE `{batter_backup}` AS
            SELECT PK, game_id, pitcher_id, pitcher_name
            FROM kbo_season_records WHERE game_id IN ({placeholders})''',
        game_ids,
    )
    return pitch_backup, batter_backup


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()
    rows = parse_rows(args.input)
    game_ids = sorted({row['game_id'] for row in rows})

    connection = connect()
    try:
        with connection.cursor() as cursor:
            resolve_player_ids(cursor, rows)
            matchup_updates = build_matchup_updates(cursor, rows)
            print(
                f'검증 완료: games={len(game_ids)} pitchers={len(rows)} '
                f'matchups={len(matchup_updates)}', flush=True,
            )
            for game_id in game_ids:
                game_rows = [row for row in rows if row['game_id'] == game_id]
                print(game_id, [(row['team'], row['order'], row['player_name'], row['player_id'])
                                for row in game_rows], flush=True)
            if not args.write:
                print('DRY-RUN: DB를 변경하지 않았습니다.', flush=True)
                return

            backups = backup_targets(cursor, game_ids)
            print(f'백업 생성: {backups[0]}, {backups[1]}', flush=True)
            placeholders = ','.join(['%s'] * len(game_ids))
            cursor.execute(
                f'DELETE FROM kbo_season_pitch_records WHERE game_id IN ({placeholders})',
                game_ids,
            )
            cursor.executemany(
                '''INSERT INTO kbo_season_pitch_records
                   (game_id, game_date, team, player_id, inning, record, pitched,
                    `order`, er, r)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''',
                [(row['game_id'], row['game_date'], row['team'], row['player_id'],
                  row['inning'], row['record'], row['pitched'], row['order'],
                  row['er'], row['r']) for row in rows],
            )
            cursor.executemany(
                '''UPDATE kbo_season_records
                   SET pitcher_id=%s, pitcher_name=%s WHERE PK=%s''',
                matchup_updates,
            )
        connection.commit()

        with connection.cursor() as cursor:
            placeholders = ','.join(['%s'] * len(game_ids))
            cursor.execute(
                f'''SELECT COUNT(*) AS row_count, COUNT(DISTINCT game_id) AS games,
                           SUM(`order` IS NULL OR er IS NULL OR r IS NULL) AS missing
                    FROM kbo_season_pitch_records
                    WHERE game_id IN ({placeholders})''', game_ids,
            )
            pitch_check = cursor.fetchone()
            cursor.execute(
                f'''SELECT SUM(batting_index IS NOT NULL AND pitcher_id IS NULL) AS missing
                    FROM kbo_season_records WHERE game_id IN ({placeholders})''', game_ids,
            )
            matchup_check = cursor.fetchone()
        if (int(pitch_check['row_count']) != len(rows)
                or int(pitch_check['games']) != len(game_ids)
                or int(pitch_check['missing'] or 0) != 0
                or int(matchup_check['missing'] or 0) != 0):
            raise ValueError(
                f'Post-write verification failed: {pitch_check}, {matchup_check}'
            )
        print(f'운영 반영 완료: pitchers={pitch_check}, matchups={matchup_check}', flush=True)
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


if __name__ == '__main__':
    main()
