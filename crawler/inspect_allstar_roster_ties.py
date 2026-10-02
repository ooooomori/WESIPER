import gzip,json
from backfill_futures_history import ROOT,cached
from backfill_kbo_early_official import connect
from futures_history_parser import Resolver
import kbo_futures_crawl as base
con=connect();resolver=Resolver(con)
try:
 gid='20180713FNFS0';folder=ROOT/'2018';decision=json.loads((folder/(gid+'-source.json')).read_text())
 tables=base.parse_tables(gzip.open(folder/decision['path'],'rt',encoding='utf-8').read())
 for t in tables:
  for row in t['rows']:
   v=base.row_values(row)
   if v and v[0] in ('김태형','박주홍'):print('BOX',v,flush=True)
 for pid in (67122,62918,68703,50357):
  if pid in (67122,62918):
   page=cached(f'https://www.koreabaseball.com/Record/Player/PitcherDetail/Total.aspx?playerId={pid}',folder/'allstar-identity'/f'profile-{pid}.html.gz')
   import re
   text=re.sub(r'\s+',' ',re.sub('<[^>]+>',' ',page))
   for term in ('입단년도','지명순위'):
    i=text.find(term);print('OFFICIAL_PROFILE',pid,text[max(0,i-30):i+150],flush=True)
  with con.cursor() as c:
   c.execute('SELECT player_id,name,oldname,pos,team,birth FROM kbo_player_data WHERE player_id=%s',(pid,));print('PROFILE',c.fetchall(),flush=True)
  rows=resolver.daily(pid,2018,'pitcher');print('DAILY',pid,len(rows),[r for r in rows if '07.01'<=r[0]<='07.20'],flush=True)
finally:con.close()
