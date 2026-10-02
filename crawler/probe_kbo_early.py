import json, os
from pathlib import Path
import pymysql, requests
from backfill_official_kbo_pitchers import fetch_payload

root=Path('/home/bitnami/wesiper/official-2001-2007')
print('Connecting to DB',flush=True)
con=pymysql.connect(host=os.environ['DB_HOST'],port=int(os.getenv('DB_PORT','3306')),user=os.environ['DB_USER'],password=os.environ['DB_PASSWORD'],database=os.environ['DB_NAME'],charset='utf8mb4',connect_timeout=10,read_timeout=30)
with con.cursor() as c:
    for t in ('kbo_season_records','kbo_season_pitch_records','kbo_schedule','kbo_player_data','kbo_playerlist_20250613'):
        c.execute('SHOW COLUMNS FROM '+t)
        print(t, c.fetchall())
    c.execute("SELECT game_id,game_date,pos FROM kbo_season_records WHERE league_level=1 AND game_id LIKE '3333%' LIMIT 5")
    print('codes',c.fetchall())
con.close()
s=requests.Session()
p=fetch_payload(s,'GetScheduleList',{'leId':'1','srIdList':'0,1,3,5,7,6,9','seasonId':'2001','gameMonth':'04','teamId':''},root/'2001/schedule-04.json')
print('schedule',json.dumps(p,ensure_ascii=False)[:10000])
g='20010405HTOB0'
for method in ('GetScoreBoardScroll','GetBoxScoreScroll'):
    p=fetch_payload(s,method,{'leId':'1','srId':'0','seasonId':'2001','gameId':g},root/'2001'/f'{g}-{method}.json')
    print(method,list(p))
    if method=='GetBoxScoreScroll':
        for k in ('arrHitter','arrPitcher'):
            for item in p.get(k,[]):
                print(k,list(item))
                for key,v in item.items():
                    if isinstance(v,str) and v.startswith('{'):
                        t=json.loads(v)
                        print(key,[[x['Text'] for x in r['row']] for r in t.get('headers',[])],[[x['Text'] for x in r['row']] for r in t.get('rows',[])][:3])
        print('notes',[[x['Text'] for x in r['row']] for r in json.loads(p['tableEtc'])['rows']])
url='https://www.koreabaseball.com/Game/LiveText.aspx'
r=s.get(url,params={'leagueId':1,'seriesId':0,'seasonId':2001,'gameId':g},timeout=30)
r.encoding='utf-8'
(root/'2001'/f'{g}-live.html').write_text(r.text,encoding='utf-8')
import re
print('scripts',re.findall(r'<script[^>]*src="([^"]+)',r.text))
print('live fragments',[v[:200] for v in r.text.splitlines() if any(x in v for x in ('ajax','LiveText','Get','gameId','문자','주루사'))][-60:])
