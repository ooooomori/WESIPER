import gzip,json
from backfill_kbo_early_official import ROOT,connect
from kbo_early_parser import daily_matches,parse_tables,row_values
print('plans',len(list((ROOT/'2001/plans').glob('*.json'))))
for p in list((ROOT/'2001/player-daily').glob('*-s0.html.gz'))[:5]:
 with gzip.open(p,'rt') as f:page=f.read()
 print(p.name,[row_values(r) for t in parse_tables(page) for r in t['rows'] if row_values(r) and row_values(r)[0].startswith('04.')][:2])
p=ROOT/'2001/dry-progress.json'
if p.exists():print(p.read_text()[:12000])
con=connect()
with con.cursor() as c:
 c.execute("SELECT player_id,name,oldname,pos,team FROM kbo_player_data WHERE name IN ('김민철','이종민','장일현','김태룡','김민호','김상훈') OR oldname IN ('김민철','이종민')")
 print('catalog samples',c.fetchall())
con.close()
