"""Verify every selected winner ID against the public KBO player search."""
import json
import re
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from lxml import html

ROOT=Path(__file__).resolve().parent.parent
CACHE=ROOT/'.player-history'

def search(name):
 path=CACHE/('search-'+name+'.html')
 if not path.exists():
  url='https://www.koreabaseball.com/Player/Search.aspx?searchWord='+urllib.parse.quote(name)
  req=urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'})
  with urllib.request.urlopen(req,timeout=40) as response:path.write_bytes(response.read())
 results={}
 for row in html.fromstring(path.read_bytes()).xpath('//table//tr'):
  cells=[x.text_content().strip() for x in row.xpath('./td')]
  for link in row.xpath('.//a/@href'):
   m=re.search(r'playerId=(\d+)',link)
   if m:results[int(m[1])]=cells
 return results

def main():
 catalog={x['player_id']:x for x in json.loads((CACHE/'catalog.json').read_text(encoding='utf-8'))['players']}
 records=json.loads((ROOT/'data/player-history/titleholders.json').read_text(encoding='utf-8'))
 names={}
 for r in records:names.setdefault(r['player_id'],set()).add(r['name'])
 def one(pid):
  player=catalog[pid]
  found=search(player['name']).get(pid)
  if found is None:
   for name in names[pid]:
    found=search(name).get(pid)
    if found:break
  if found is None:raise ValueError(('Official player ID missing',pid,player))
  if player['birth'] and re.fullmatch(r'\d{4}-\d{2}-\d{2}',player['birth']) and player['birth']!=found[4]:raise ValueError(('Official birth mismatch',pid,player,found))
  return pid,{'cells':found,'source':'https://www.koreabaseball.com/Player/Search.aspx?searchWord='+urllib.parse.quote(player['name'])}
 with ThreadPoolExecutor(max_workers=3) as pool:verified=dict(pool.map(one,sorted(names)))
 (CACHE/'verified-title-identities.json').write_text(json.dumps(verified,ensure_ascii=False,indent=2),encoding='utf-8')
 print('Verified',len(verified),'winner IDs and birth dates')

if __name__=='__main__':
 import sys
 sys.stdout.reconfigure(encoding='utf-8')
 main()
