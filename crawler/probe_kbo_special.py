import requests,json,re
from pathlib import Path
from backfill_kbo_early_official import ROOT,HEADERS,atomic
root=ROOT/'probe'
for day in ('2001-04-05','2001-10-06','2001-10-20','2001-03-15','2001-07-17'):
 p=root/f'main-{day}.json'
 if p.exists():d=json.loads(p.read_text())
 else:
  r=requests.post('https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList',data={'leId':1,'srId':'0,1,3,5,7,6,9','date':day},headers=HEADERS,timeout=30);r.raise_for_status();d=r.json();atomic(p,d)
 print(day,str(d)[:3000])
for url in ('https://www.koreabaseball.com/Game/LiveText.aspx?gameDate=20010405&gameId=20010405HTOB0','https://www.koreabaseball.com/Schedule/GameCenter/Main.aspx?gameDate=20010405&gameId=20010405HTOB0&section=REVIEW'):
 r=requests.get(url,headers=HEADERS,timeout=30);r.encoding='utf-8'
 print('url',r.url,len(r.text))
 print([line.strip()[:500] for line in r.text.splitlines() if any(s in line for s in ('LiveText','GetText','iframe','GetGame','gameId:','seasonId:'))])
 (root/('gamecenter.html' if 'GameCenter' in url else 'live-date.html')).write_text(r.text)
