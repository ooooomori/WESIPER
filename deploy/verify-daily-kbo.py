"""Read-only verification of the daily first-team and Futures write scopes."""
import json
import os
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import pymysql

now = datetime.now(ZoneInfo('Asia/Seoul'))
day = (now - timedelta(days=1)).strftime('%Y-%m-%d')
cutoff = (now - timedelta(days=7)).strftime('%Y-%m-%d')
db = pymysql.connect(host=os.environ['DB_HOST'], port=int(os.getenv('DB_PORT', '3306')),
                     user=os.environ['DB_USER'], password=os.environ['DB_PASSWORD'],
                     database=os.environ['DB_NAME'], charset='utf8mb4')
try:
    result = {}
    for level, since in ((1, day), (2, cutoff)):
        date_filter = 'game_date=%s' if level == 1 else 'game_date>=%s'
        report = {}
        with db.cursor() as cursor:
            cursor.execute(f'''SELECT COUNT(*),COUNT(DISTINCT game_id),
                SUM(player_id IS NULL OR player_name IS NULL OR pos IS NULL OR `order` IS NULL OR is_gs IS NULL OR rbi IS NULL OR r IS NULL OR is_gwrbi IS NULL),
                SUM(pa_result IS NOT NULL AND (batting_index IS NULL OR pitcher_id IS NULL OR pitcher_name IS NULL))
                FROM kbo_season_records WHERE league_level=%s AND {date_filter}''', (level, since))
            batters = tuple(int(x or 0) for x in cursor.fetchone())
            cursor.execute(f'''SELECT COUNT(*),COUNT(DISTINCT game_id),
                SUM(player_id IS NULL OR pitched IS NULL OR `order` IS NULL OR er IS NULL OR r IS NULL)
                FROM kbo_season_pitch_records WHERE league_level=%s AND {date_filter}''', (level, since))
            pitchers = tuple(int(x or 0) for x in cursor.fetchone())
            cursor.execute(f'''SELECT COUNT(*) FROM kbo_season_records
                WHERE league_level=%s AND {date_filter} AND batting_index IS NOT NULL
                GROUP BY game_id,team,batting_index HAVING COUNT(*)>1''', (level, since))
            duplicates = len(cursor.fetchall())
            cursor.execute(f'''SELECT COUNT(*),SUM(away_inning_scores IS NULL OR home_inning_scores IS NULL)
                FROM kbo_schedule WHERE league_level=%s AND {date_filter} AND away_score IS NOT NULL AND home_score IS NOT NULL''', (level, since))
            schedules = tuple(int(x or 0) for x in cursor.fetchone())
            assert not any(batters[2:] + pitchers[2:] + (duplicates,)), f'Invalid records level {level}'
            report.update(batter_rows=batters[0], batter_games=batters[1], pitcher_rows=pitchers[0],
                          pitcher_games=pitchers[1], duplicate_batting_indices=duplicates,
                          schedule_games=schedules[0], schedules_without_innings=schedules[1])
        result[str(level)] = report
    with db.cursor() as cursor:
        for table in ('kbo_season_records', 'kbo_season_pitch_records'):
            cursor.execute(f'''SELECT COUNT(*) FROM `{table}` r LEFT JOIN kbo_player_data p
                ON p.player_id=r.player_id WHERE r.game_date >= %s AND r.player_id IS NOT NULL AND p.player_id IS NULL''', (cutoff,))
            assert cursor.fetchone()[0] == 0, f'Orphan players in {table}'
    print(json.dumps({'verified': True, 'kst_date': now.strftime('%Y-%m-%d'), 'scopes': result}, ensure_ascii=False))
finally:
    db.close()
