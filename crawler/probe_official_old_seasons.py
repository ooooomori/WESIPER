import requests,re,gzip
from pathlib import Path
root=Path('/home/bitnami/wesiper/official-season-totals-1982-2000');root.mkdir(exist_ok=True)
for role in ('Hitter','Pitcher'):
 url=f'https://www.koreabaseball.com/Record/Player/{role}Basic/BasicOld.aspx';r=requests.get(url,timeout=30);r.raise_for_status();r.encoding='utf-8';page=r.text
 with gzip.open(root/(role+'-initial.html.gz'),'wt',encoding='utf-8') as f:f.write(page)
 print(role,flush=True)
 for a,body in re.findall(r'<select\b([^>]*)>(.*?)</select>',page,re.S|re.I):print('SELECT',a,re.sub('<[^>]+>',' ',body)[:1200],flush=True);print('OPTIONS',re.findall(r'<option\b[^>]*value="([^"]*)"[^>]*>(.*?)</option>',body,re.S),flush=True)
 for m in re.findall(r'<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>',page,re.S|re.I):
  if any(v in m[0] for v in ('Basic','Detail','__doPostBack')):print('LINK',m[0],re.sub('<[^>]+>',' ',m[1]),flush=True)
 for line in page.splitlines():
  if any(v in line for v in ('ucPager','btnNext','btnLast','lbl','ddl','ScriptManager')):print('CONTROL',line.strip()[:800],flush=True)
