import gzip,json
from pathlib import Path
import requests
from old_season_webform import selects,form,record_table
from kbo_futures_crawl import row_values,parse_tables
ROOT=Path('/home/bitnami/wesiper/official-season-totals-1982-2000/rate-supplement');ROOT.mkdir(exist_ok=True)
for endpoint in ('HitterBasic/Basic1','HitterDetail/Basic'):
 url='https://www.koreabaseball.com/Record/Player/'+endpoint+'.aspx'+('?playerId=20001' if 'Detail' in endpoint else '')
 s=requests.Session();r=s.get(url,timeout=30);r.encoding='utf-8';page=r.text;controls=selects(page)
 print('CONTROLS',endpoint,[(k,v['value'],[o['value'] for o in v['options']][-6:]) for k,v in controls.items()],flush=True)
 yearkey=next((k for k in controls if 'ddlSeason' in k),None)
 if yearkey:
  target=yearkey.rsplit('$',2)[0]+'$lbtnOrderBy';data=form(page,target,{yearkey:'1983'})
  for k in data:
   if k.endswith('$hfOrderByCol'):data[k]='GAME_CN'
   if k.endswith('$hfOrderBy'):data[k]='DESC'
  r=s.post(url,data=data,timeout=30);r.encoding='utf-8';page=r.text
  print('SELECTION',r.status_code,r.url,[(k,v['value']) for k,v in selects(page).items() if 'Season' in k],flush=True)
 with gzip.open(ROOT/(endpoint.replace('/','-')+'.html.gz'),'wb') as f:f.write(page.encode())
 for t in parse_tables(page):
  rows=[row_values(row) for row in t['rows']]
  if any('AB' in row for row in rows):print('RECORDS',endpoint,rows[:4],flush=True)
