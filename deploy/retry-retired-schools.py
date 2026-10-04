import concurrent.futures,gzip,html,json,re,time,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent;OUT=ROOT/'output/retired-schools'
results=json.loads((OUT/'collected.json').read_text(encoding='utf-8'))
def field(t,k):
 m=re.search(r'<span\b[^>]*id=["\'][^"\']*ucPlayerProfile_lbl'+k+r'["\'][^>]*>(.*?)</span>',t,re.S)
 return html.unescape(re.sub('<[^>]*>','',m[1])).strip() if m else None
def retry(r):
 time.sleep(.1)
 pid=r['player_id'];role='Pitcher' if '/Pitcher.' in r['source_url'] else 'Hitter';url=f'https://www.koreabaseball.com/Futures/Player/{role}Detail.aspx?playerId={pid}'
 path=OUT/f'{pid}-futures.html.gz'
 try:
  if path.exists():t=gzip.decompress(path.read_bytes()).decode('utf-8')
  else:
   with urllib.request.urlopen(url,timeout=20) as response:t=response.read().decode('utf-8-sig')
   path.write_bytes(gzip.compress(t.encode('utf-8')))
  name=field(t,'Name');career=field(t,'Career');birth=field(t,'Birthday')
  if name and career:return {**r,'source_url':url,'name':name,'career':career,'birth':birth,'status':'ok','source_cache':path.name}
 except Exception as e:return {**r,'fallback_error':str(e)}
 return r
targets=[r for r in results if r['status']!='ok']
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:retried=list(pool.map(retry,targets))
lookup={r['player_id']:r for r in retried};final=[lookup.get(r['player_id'],r) for r in results]
(OUT/'collected.json').write_text(json.dumps(final,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'retried':len(retried),'recovered':sum(r['status']=='ok' for r in retried),'errors':sum('fallback_error' in r for r in retried)}))
