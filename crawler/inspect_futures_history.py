import gzip,json,re
from pathlib import Path
from kbo_futures_crawl import clean,parse_tables,row_values
root=Path('/home/bitnami/wesiper/official-futures-2010-2021')
for p in (root/'2010').glob('schedule-*.gz'):
 for r in json.loads(gzip.open(p,'rt').read())['rows']:
  if len(re.findall('<span',str(r)))>=5 and 'gameId=' not in str(r):print(p.name,str(r))
p=next((root/'2011').glob('s0-*-box.html.gz'))
print('SAMPLE',p.name)
for t in parse_tables(gzip.open(p,'rt').read()):
 rows=[row_values(r) for r in t['rows']]
 if rows and rows[0] and rows[0][0]=='선수명':print(rows[:3])
from backfill_kbo_early_official import connect
from futures_history_parser import Resolver
con=connect();resolver=Resolver(con)
print('KIM',resolver.by_name['김정훈'])
print('SEARCH',resolver.search('김정훈',2010))
with con.cursor() as c:
 c.execute('SHOW INDEX FROM kbo_season_records')
 print('INDEXES',[(r[2],r[4]) for r in c.fetchall()])
 for pid in resolver.by_name['김정훈']:
  c.execute('SELECT DISTINCT team FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_player_date) WHERE league_level=1 AND player_id=%s AND game_date>=%s AND game_date<%s',(pid,'2010-01-01','2011-01-01'))
  print('ROSTER',pid,c.fetchall())
con.close()
