"""Collect official KBO retired profiles, with a resumable raw source cache."""
import concurrent.futures,gzip,html,json,re,threading,time,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
OUT=ROOT/'output/retired-schools';OUT.mkdir(exist_ok=True)
TARGETS=json.loads((ROOT/'output/retired-school-targets.json').read_text(encoding='utf-8'))
lock=threading.Lock();next_request=0
def field(text,key):
 m=re.search(r'<span\b[^>]*id=["\'][^"\']*ucRetireInfo_lbl'+key+r'["\'][^>]*>(.*?)</span>',text,re.S)
 return html.unescape(re.sub('<[^>]*>','',m[1])).strip() if m else None
def fetch(p):
 global next_request
 pid=p['player_id'];kind='Pitcher' if p['pos']=='투수' else 'Hitter';url=f'https://www.koreabaseball.com/Record/Retire/{kind}.aspx?playerId={pid}'
 raw=OUT/f'{pid}.html.gz';result=OUT/f'{pid}.json'
 if result.exists():return json.loads(result.read_text(encoding='utf-8'))
 for attempt in range(3):
  try:
   if raw.exists():text=gzip.decompress(raw.read_bytes()).decode('utf-8-sig')
   else:
    with lock:
     delay=max(0,next_request-time.monotonic());next_request=max(time.monotonic(),next_request)+0.08
    if delay:time.sleep(delay)
    req=urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'})
    with urllib.request.urlopen(req,timeout=25) as r:text=r.read().decode('utf-8-sig')
    raw.write_bytes(gzip.compress(text.encode('utf-8')))
   name=field(text,'Name');birthday=field(text,'Birthday');career=field(text,'Career')
   value={'player_id':pid,'source_url':url,'name':name,'birth':birthday,'career':career,'status':'ok' if name and career else 'missing_profile_or_school'}
   result.write_text(json.dumps(value,ensure_ascii=False,indent=2),encoding='utf-8');return value
  except Exception as e:
   if attempt==2:return {'player_id':pid,'source_url':url,'status':'fetch_error','error':str(e)}
   time.sleep(2*(attempt+1))
results=[];start=time.monotonic()
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
 for f in concurrent.futures.as_completed([pool.submit(fetch,p) for p in TARGETS]):
  results.append(f.result())
  if len(results)%100==0:
   progress={'done':len(results),'total':len(TARGETS),'seconds':round(time.monotonic()-start),'ok':sum(r['status']=='ok' for r in results)}
   (OUT/'progress.json').write_text(json.dumps(progress),encoding='utf-8');print(json.dumps(progress),flush=True)
results.sort(key=lambda r:r['player_id'])
(OUT/'collected.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'complete':len(results),'errors':sum(r['status']=='fetch_error' for r in results)}),flush=True)
