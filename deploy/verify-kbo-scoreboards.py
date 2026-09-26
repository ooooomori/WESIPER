"""Read-only verification, run with the crawler's DB environment."""
import json
import os
import pymysql

connection = pymysql.connect(host=os.environ['DB_HOST'], user=os.environ['DB_USER'],
    password=os.environ['DB_PASSWORD'], database=os.environ['DB_NAME'], charset='utf8mb4')
try:
    with connection.cursor() as cursor:
        cursor.execute('''SELECT game_code, away_score, home_score, away_inning_scores, home_inning_scores
            FROM kbo_schedule WHERE away_inning_scores IS NOT NULL ORDER BY game_date''')
        rows = cursor.fetchall()
        for code, away, home, away_json, home_json in rows:
            innings = [json.loads(away_json), json.loads(home_json)]
            assert len(innings[0]) == len(innings[1])
            assert sum(n for n in innings[0] if n is not None) == away
            assert sum(n for n in innings[1] if n is not None) == home
        print(json.dumps({'verified_scoreboards': len(rows), 'games': [row[0] for row in rows]}))
finally:
    connection.close()
