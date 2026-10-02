import gzip,re
from backfill_futures_history import ROOT
from kbo_futures_crawl import parse_tables,row_values
for year,pid in [(2020,50415),(2020,63093)]:
 p=ROOT/str(year)/'player-daily'/f'Hitter-{pid}-{year}.html.gz'
 print('PAGE',p.name,'EXISTS',p.exists())
 if not p.exists():continue
 page=gzip.open(p,'rt',encoding='utf-8').read()
 print('HEADINGS',re.findall(r'<h6>(.*?)</h6>',page,re.S))
 for t in parse_tables(page):
  rows=[row_values(r) for r in t['rows']]
  if any(re.search(r'\d{2}\.\d{2}',str(r)) for r in rows):print(rows[:4])
folder=ROOT/'2020';gid='20200506KTHH0';import json
dec=json.loads((folder/(gid+'-source.json')).read_text());tables=parse_tables(gzip.open(folder/dec['path'],'rt',encoding='utf-8').read())
from kbo_futures_crawl import find_table,parse_lineup_group
for side in ('away','home'):
 print('LINEUP',side,[b for b in parse_lineup_group(tables,side) if b['name']=='김경민'])
 _,stats=find_table(tables,'tbl'+side.title()+'Hitter3');print('STATS',side,[row_values(r) for r in stats['rows']])
