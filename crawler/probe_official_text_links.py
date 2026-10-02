import requests,re,gzip
from backfill_kbo_early_official import ROOT,HEADERS
r=requests.get('https://www.koreabaseball.com/Schedule/ScoreBoard.aspx',headers=HEADERS,timeout=30);r.encoding='utf-8'
p=ROOT/'probe/ScoreBoard.html.gz'
with gzip.open(p,'wt',encoding='utf-8') as f:f.write(r.text)
print([v.strip()[:700] for v in r.text.splitlines() if any(x in v for x in ('LiveText','liveText','문자중계','Game/','popup','Popup','ws/','ajaxHtml','AjaxHtml'))],flush=True)
print('scripts',re.findall(r'<script[^>]*src=[\'"]([^\'"]+)',r.text)[-8:],flush=True)
print('date code',[v.strip()[:450] for v in r.text.splitlines() if any(x in v for x in ('txtCalendar','hf','gameDate','OnClient','PostBack','Calendar','DateChanged'))],flush=True)
for query in ('?gameDate=20030802','?date=20030802'):
 q=requests.get('https://www.koreabaseball.com/Schedule/ScoreBoard.aspx'+query,headers=HEADERS,timeout=30);q.encoding='utf-8'
 print(query,[v.strip()[:700] for v in q.text.splitlines() if 'btn_cast' in v or 'LiveText' in v],flush=True)
import html
form={}
for m in re.finditer(r'<input\b([^>]*)>',r.text,re.I|re.S):
 a={k.lower():html.unescape(v) for k,_,v in re.findall(r'([:\w-]+)\s*=\s*([\'"])(.*?)\2',m[1],re.S)}
 if a.get('type','').lower()=='hidden' and a.get('name'):form[a['name']]=a.get('value','')
key=next(k for k in form if k.endswith('hfSearchDate'))
form[key]='20030802';form['__EVENTTARGET']=key.replace('hfSearchDate','btnCalendarSelect');form['__EVENTARGUMENT']=''
q=requests.post('https://www.koreabaseball.com/Schedule/ScoreBoard.aspx',data=form,headers=HEADERS,timeout=30);q.encoding='utf-8'
with gzip.open(ROOT/'2003/20030802-ScoreBoard.html.gz','wt',encoding='utf-8') as f:f.write(q.text)
print('POST date',[v.strip()[:1000] for v in q.text.splitlines() if 'btn_cast' in v or 'LiveText' in v or 'hfSearchDate' in v],flush=True)
