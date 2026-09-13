import os
import json
import requests
import pymysql

conn = pymysql.connect(host=os.environ['DB_HOST'], user=os.environ['DB_USER'], password=os.environ['DB_PASSWORD'], database=os.environ['DB_NAME'], charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor)
with conn.cursor() as cur:
    for table in ('kbo_season_records', 'kbo_league_records'):
        cur.execute('SHOW CREATE TABLE ' + table)
        print(cur.fetchone())
    cur.execute('SELECT YEAR(game_date) year, COUNT(*) rows_count, COUNT(DISTINCT game_id) games FROM kbo_season_records GROUP BY YEAR(game_date)')
    print(cur.fetchall())
    cur.execute('SELECT game_id, game_date FROM kbo_season_records WHERE LEFT(game_id,4) <> CAST(YEAR(game_date) AS CHAR) LIMIT 10')
    print(cur.fetchall())
conn.close()
for date in ('2008-01-01', '2008-03-01'):
    url = 'https://api-gw.sports.naver.com/schedule/calendar'
    r = requests.get(url, params={'upperCategoryId':'kbaseball','categoryIds':'kbo','date':date,'teamCode':'OB'}, headers={'User-Agent':'Mozilla/5.0','Referer':'https://m.sports.naver.com/'}, timeout=30)
    data = r.json()
    dates = data.get('result',{}).get('dates', [])
    games = [(d.get('ymd'),g.get('gameId'),g.get('statusCode')) for d in dates for g in (d.get('gameInfos') or [])]
    print(date, r.status_code, data.get('code'), len(games), games[:4], games[-4:])
