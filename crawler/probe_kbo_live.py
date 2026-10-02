import requests,re
from backfill_kbo_early_official import ROOT,connect
params={'leId':1,'srId':0,'leagueId':1,'seriesId':0,'seasonId':2001,'gameId':'20010405HTOB0','gameDate':'20010405','year':2001}
s=requests.Session()
for path,method in (('Game/LiveText.aspx','get'),('Game/LiveText.aspx','post'),('Schedule/GameCenter/ReviewNew.aspx','post')):
 url='https://www.koreabaseball.com/'+path
 r=s.get(url,params=params,timeout=30) if method=='get' else s.post(url,data=params,timeout=30)
 r.encoding='utf-8'
 print(path,method,r.url,len(r.text),flush=True)
 print([v.strip()[:600] for v in r.text.splitlines() if any(x in v for x in ('LiveText','GetText','iframe','gameId','GetBox','GetScore'))][-25:],flush=True)
 (ROOT/'probe'/f'{path.split("/")[-1]}-{method}.html').write_text(r.text)
con=connect()
with con.cursor() as c:
 c.execute('SHOW PROCESSLIST')
 print('DB processes',[(r[4],r[5],r[6]) for r in c.fetchall()],flush=True)
con.close()
