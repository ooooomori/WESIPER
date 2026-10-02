from backfill_kbo_early_official import ROOT,raw
from kbo_early_parser import daily,parse_tables,row_values
import json
known={}
for p in (ROOT/'2001/plans').glob('*.json'):
 d=json.loads(p.read_text())
 for r in d['batter_rows']:
  if r['player_name'] in ('우즈','김동주','송지만','이승엽','박재홍','양준혁','김태균'):known[(r['team'],r['player_name'])]=r['player_id']
for (team,name),pid in known.items():
 for sr in (3,5,7):
  page=daily(pid,2001,'batter',sr,ROOT)
  found=[row_values(r) for t in parse_tables(page) for r in t['rows'] if row_values(r) and len(row_values(r)[0])==5 and '.' in row_values(r)[0]]
  print(team,name,pid,sr,found[:15],flush=True)
for sr,g in ((3,'20011006HHOB0'),(7,'20011020OBSS0')):
 p=raw('GetScoreBoardScroll',{'leId':1,'srId':sr,'seasonId':2001,'gameId':g},ROOT/'probe'/f'post-{g}-s{sr}.json.gz')
 print(g,sr,p.get('code'),p.get('G_DT'),p.get('AWAY_NM'),p.get('HOME_NM'),flush=True)
