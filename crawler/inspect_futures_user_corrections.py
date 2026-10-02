import gzip,json
from backfill_futures_history import ROOT
import kbo_futures_crawl as b
data=json.loads((ROOT/'unresolved-report.json').read_text())
for fail in data['failures']:
 gid=fail['game_id'];folder=ROOT/gid[:4];g=next(g for g in json.loads((folder/'games.json').read_text()) if g['game_id']==gid);d=json.loads((folder/(gid+'-source.json')).read_text());page=gzip.open(folder/d['path'],'rt',encoding='utf-8').read();tables=b.parse_tables(page)
 print('\nGAME',gid,fail['category'],flush=True)
 print('NOTES',b.parse_note_rows(tables),flush=True)
 for side,team in [('away',g['away_team']),('home',g['home_team'])]:
  lineup=b.parse_lineup_group(tables,side);ordered=b.ordered_events(lineup,gid,team,allow_missing=True)
  holes=[e for e in ordered if e.get('missing')]
  print('TEAM',team,'HOLES',holes,flush=True)
  for r in lineup:
   print(r,flush=True)
