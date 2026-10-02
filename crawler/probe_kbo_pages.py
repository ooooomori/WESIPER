import requests,re,gzip,json
from pathlib import Path
from backfill_kbo_early_official import ROOT,raw
s=requests.Session()
for sr in ('3,4,5,7','0,9,6','1'):
 p=raw('GetScheduleList',{'leId':1,'srIdList':sr,'seasonId':'2001','gameMonth':'' if sr!='0,9,6' else '07','teamId':''},ROOT/'probe'/f'schedule-group-{sr}.json.gz')
 print('special',sr,len(p.get('rows',[])),str(p)[:500])
for url in ('https://www.koreabaseball.com/Game/LiveText.aspx?leId=1&srId=0&seasonId=2001&gameId=20010405HTOB0',):
 r=s.get(url,timeout=30);r.encoding='utf-8'
 print('PAGE',url,r.status_code,r.url,len(r.text))
 print('selects',re.findall(r'<select\b[^>]*>.*?</select>',r.text,re.S)[:8])
 print('scripts',re.findall(r'<script[^>]*src=[\'"]([^\'"]+)',r.text))
 p=ROOT/'probe'/('daily.html' if 'Daily' in url else 'schedule.html' if 'Schedule.aspx' in url else 'live.html');p.parent.mkdir(exist_ok=True);p.write_text(r.text)
