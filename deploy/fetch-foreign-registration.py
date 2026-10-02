"""Check public KBO registration profiles for reviewed foreign-name candidates."""
import json,urllib.request,re
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from lxml import html
ROOT=Path(__file__).resolve().parent.parent;CACHE=ROOT/'.player-career'
def one(p):
 pid=p['player_id'];path=CACHE/f'foreign-profile-{pid}.html'
 url=f'https://www.koreabaseball.com/Record/Player/PitcherDetail/Total.aspx?playerId={pid}'
 if not path.exists():
  with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=45) as r:path.write_bytes(r.read())
 doc=html.fromstring(path.read_bytes());fields={}
 for li in doc.xpath('//li'):
  text=' '.join(li.text_content().split())
  if ':' not in text or len(text)>300:continue
  k,v=text.split(':',1)
  if k in ['선수명','경력','지명순위','입단년도']:fields[k]=v.strip()
 team=doc.xpath('//*[contains(@class,"team")]//text()')
 return {'player_id':pid,'name':p['name'],'profile':fields,'source':url}
def main():
 catalog=json.loads((CACHE/'catalog.json').read_text(encoding='utf-8-sig'))['players']
 candidates=json.loads((CACHE/'foreign-candidates.json').read_text(encoding='utf-8'))
 extras=set(__import__('runpy').run_path(str(ROOT/'deploy/prepare-player-career-final.py'))['EXTRA_FOREIGN'])|{55903,73424,74534,79650}
 ids={p['player_id'] for p in candidates}|extras
 players=[p for p in catalog if p['player_id'] in ids];out=[]
 with ThreadPoolExecutor(max_workers=4) as pool:
  for i,r in enumerate(pool.map(one,players)):
   out.append(r)
   if i%100==0:print('profiles',i+1,flush=True)
 (CACHE/'foreign-registrations.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
 print('profiles complete',len(out),'with draft',sum(bool(r['profile'].get('지명순위')) for r in out),flush=True)
if __name__=='__main__':main()
