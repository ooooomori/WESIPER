import requests,gzip,re,json
from backfill_kbo_early_official import ROOT,HEADERS
path=ROOT/'2003/20030802SKHH0-Futures-Schedule-BoxScore.aspx.html.gz'
with gzip.open(path,'rt') as f:page=f.read()
print([line.strip()[:600] for line in page.splitlines() if any(x in line for x in ('Live','live','문자','popup','Popup','GetGame','GetFutures'))],flush=True)
params={'leagueId':1,'seriesId':0,'seasonId':2003,'gameId':'20030802SKHH0','gameDate':'20030802'}
for route in ('Futures/Schedule/LiveText.aspx','Game/LiveText.aspx'):
 r=requests.post('https://www.koreabaseball.com/'+route,data=params,headers=HEADERS,timeout=30);r.encoding='utf-8'
 print(route,r.url,len(r.text),flush=True)
 p=ROOT/'2003'/('20030802SKHH0-'+route.replace('/','-')+'-post.html.gz')
 with gzip.open(p,'wt',encoding='utf-8') as f:f.write(r.text)
 print([line.strip()[:600] for line in r.text.splitlines() if any(x in line for x in ('LiveText','문자','주루사','조윤채','GetText'))][:15],flush=True)
