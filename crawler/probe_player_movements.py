import requests,json
from pathlib import Path
root=Path('/home/bitnami/wesiper/official-player-movements-2017-2026');root.mkdir(exist_ok=True)
s=requests.Session()
s.headers.update({'User-Agent':'Mozilla/5.0','Referer':'https://www.koreabaseball.com/Player/Trade.aspx','X-Requested-With':'XMLHttpRequest'})
s.get('https://www.koreabaseball.com/Player/Trade.aspx',timeout=60)
for name,data,url in [('years',{},'Controls.asmx/GetCareerYearList'),('2026',{'seasonId':2026,'monthId':0,'bdSc':0,'teamName':'','searchIf':'','pageNo':1,'listCount':20},'Player.asmx/GetTradeList')]:
 r=s.post('https://www.koreabaseball.com/ws/'+url,data=data,timeout=60);r.raise_for_status();r.encoding='utf-8-sig'
 (root/(name+'-probe.json')).write_text(r.text,encoding='utf-8')
 print(name,r.url,r.text[:6000])
