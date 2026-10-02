import gzip,json
from backfill_futures_history import ROOT
from kbo_futures_crawl import parse_tables,row_values,parse_lineup_group,find_table
for gid,name,pid in [('20150813SKHT0','조용호',64868),('20150820HTKT0','김태훈',65040),('20150805HTSM0','김상호',62559)]:
 year=gid[:4];folder=ROOT/year;path=folder/'player-daily'/f'Hitter-{pid}-{year}.html.gz'
 print('GAME',[g for g in json.loads((folder/'games.json').read_text()) if g['game_id']==gid])
 page=gzip.open(path,'rt',encoding='utf-8').read();day=gid[4:6]+'.'+gid[6:8]
 print('DAILY',gid,pid,[row_values(r) for t in parse_tables(page) for r in t['rows'] if row_values(r)[0]==day])
 decision=json.loads((folder/(gid+'-source.json')).read_text());tables=parse_tables(gzip.open(folder/decision['path'],'rt',encoding='utf-8').read())
 for side in ('away','home'):
  lineup=parse_lineup_group(tables,side);_,stats=find_table(tables,'tbl'+side.title()+'Hitter3');statrows=[row_values(r) for r in stats['rows'] if len(row_values(r))>=5 and row_values(r)[0].isdigit()]
  for i,b in enumerate(lineup):
   if b['name']==name:print('BOX',b,statrows[i])
