"""Import user-supplied pitcher and inning data for 77771029SSOB0."""
import json

import pymysql

from kbo_candle_crawl import DB_CONFIG


GAME_ID = '77771029SSOB0'
SCHEDULE_CODE = '77771029SSOB02013'
GAME_DATE = '2013-10-29'
AWAY_INNINGS = [3, 0, 1, 0, 1, 0, 0, 2, 0]
HOME_INNINGS = [0, 1, 3, 0, 1, 0, 0, 0, 0]
PITCHERS = [
    # team, player_id, name, inning, record, pitches, order, earned runs, runs, batters faced
    ('삼성', 74454, '윤성환', '2 1/3', None, 60, 1, 4, 4, 14),
    ('삼성', 72463, '안지만', '3 2/3', None, 45, 2, 1, 1, 12),
    ('삼성', 63432, '밴덴헐크', '2', '승', 28, 3, 0, 0, 6),
    ('삼성', 75421, '오승환', '1', '세', 23, 4, 0, 0, 4),
    ('두산', 73211, '노경은', '5', None, 105, 1, 5, 5, 25),
    ('두산', 78232, '김선우', '2/3', None, 13, 2, 0, 0, 4),
    ('두산', 62242, '윤명준', '1 1/3', '패', 29, 3, 1, 1, 6),
    ('두산', 73241, '정재훈', '1/3', None, 6, 4, 1, 1, 3),
    ('두산', 78247, '홍상삼', '2/3', None, 9, 5, 0, 0, 2),
    ('두산', 61527, '김명성', '0', None, 7, 6, 0, 0, 1),
    ('두산', 79293, '오현택', '1', None, 15, 7, 0, 0, 4),
]


def validate_players(cursor):
    expected = {row[1]: row[2] for row in PITCHERS}
    placeholders = ','.join(['%s'] * len(expected))
    cursor.execute(
        f'''SELECT p_no, p_name, p_oldname, p_pos FROM kbo_playerlist_20250613
            WHERE p_no IN ({placeholders})''',
        list(expected),
    )
    actual = {int(row['p_no']): row for row in cursor.fetchall()}
    for player_id, name in expected.items():
        player = actual.get(player_id)
        if (not player or player['p_pos'] != '투수'
                or name not in (player['p_name'], player['p_oldname'])):
            raise ValueError(f'Player ID mismatch: {player_id} {name} -> {player}')


def build_matchups(cursor):
    updates = []
    for pitching_team, batting_team in (('삼성', '두산'), ('두산', '삼성')):
        slots = []
        for row in PITCHERS:
            if row[0] == pitching_team:
                slots.extend([(row[1], row[2])] * row[9])
        cursor.execute(
            '''SELECT PK, batting_index FROM kbo_season_records
               WHERE game_id=%s AND team=%s AND batting_index IS NOT NULL
               ORDER BY batting_index''',
            (GAME_ID, batting_team),
        )
        batters = cursor.fetchall()
        if len(batters) != len(slots):
            raise ValueError(
                f'{batting_team}: batter rows {len(batters)} != pitcher BF {len(slots)}'
            )
        updates.extend((pitcher_id, pitcher_name, batter['PK'])
                       for batter, (pitcher_id, pitcher_name) in zip(batters, slots))
    return updates


connection = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
try:
    with connection.cursor() as cursor:
        validate_players(cursor)
        matchups = build_matchups(cursor)
        if len(matchups) != 81:
            raise ValueError(f'Expected 81 matchup updates, got {len(matchups)}')

        cursor.execute('DELETE FROM kbo_season_pitch_records WHERE game_id=%s', (GAME_ID,))
        cursor.executemany(
            '''INSERT INTO kbo_season_pitch_records
               (game_id, game_date, team, player_id, inning, record, pitched, `order`, er, r)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''',
            [(GAME_ID, GAME_DATE, row[0], row[1], row[3], row[4], row[5], row[6], row[7], row[8])
             for row in PITCHERS],
        )
        cursor.executemany(
            '''UPDATE kbo_season_records
               SET pitcher_id=%s, pitcher_name=%s WHERE PK=%s''',
            matchups,
        )
        cursor.execute(
            '''INSERT INTO kbo_schedule
               (game_code, game_date, away_team, home_team, away_score, home_score, tv,
                stadium, away_inning_scores, home_inning_scores)
               VALUES (%s,%s,'삼성','두산',7,5,NULL,'잠실',%s,%s)
               ON DUPLICATE KEY UPDATE
                game_date=VALUES(game_date), away_team=VALUES(away_team),
                home_team=VALUES(home_team), away_score=VALUES(away_score),
                home_score=VALUES(home_score), stadium=VALUES(stadium),
                away_inning_scores=VALUES(away_inning_scores),
                home_inning_scores=VALUES(home_inning_scores)''',
            (SCHEDULE_CODE, GAME_DATE, json.dumps(AWAY_INNINGS), json.dumps(HOME_INNINGS)),
        )
    connection.commit()

    with connection.cursor() as cursor:
        cursor.execute(
            '''SELECT COUNT(*) AS rows_count, COUNT(DISTINCT team) AS teams,
                      SUM(`order` IS NULL OR er IS NULL OR r IS NULL) AS missing
               FROM kbo_season_pitch_records WHERE game_id=%s''',
            (GAME_ID,),
        )
        pitchers = cursor.fetchone()
        cursor.execute(
            '''SELECT SUM(batting_index IS NOT NULL AND
                          (pitcher_id IS NULL OR pitcher_name IS NULL)) AS missing,
                      SUM(batting_index IS NOT NULL) AS pa_rows
               FROM kbo_season_records WHERE game_id=%s''',
            (GAME_ID,),
        )
        batters = cursor.fetchone()
        cursor.execute('SELECT * FROM kbo_schedule WHERE game_code=%s', (SCHEDULE_CODE,))
        schedule = cursor.fetchone()
    if pitchers != {'rows_count': 11, 'teams': 2, 'missing': 0}:
        raise ValueError(f'Pitcher verification failed: {pitchers}')
    if batters != {'missing': 0, 'pa_rows': 81}:
        raise ValueError(f'Batter matchup verification failed: {batters}')
    if (not schedule or schedule['away_score'] != 7 or schedule['home_score'] != 5
            or json.loads(schedule['away_inning_scores']) != AWAY_INNINGS
            or json.loads(schedule['home_inning_scores']) != HOME_INNINGS
            or schedule['tv'] is not None):
        raise ValueError(f'Schedule verification failed: {schedule}')
    print({'pitchers': pitchers, 'batters': batters,
           'schedule_code': schedule['game_code'], 'tv': schedule['tv']})
except Exception:
    connection.rollback()
    raise
finally:
    connection.close()
