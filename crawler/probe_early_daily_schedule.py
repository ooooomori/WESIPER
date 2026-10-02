import requests,json
from backfill_kbo_early_official import ROOT,HEADERS,atomic
for sr,day in ((0,'20010405'),(3,'20011009'),(7,'20011020'),(0,'20261001')):
 p=ROOT/'probe'/f'main-s{sr}-{day}.json'
 if p.exists():d=json.loads(p.read_text())
 else:
  r=requests.post('https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList',data={'leId':1,'srId':sr,'date':day},headers=HEADERS,timeout=30);r.raise_for_status();d=r.json();atomic(p,d)
 print(sr,day,str(d)[:9000],flush=True)
