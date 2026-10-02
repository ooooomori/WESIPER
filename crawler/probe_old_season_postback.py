import requests,gzip,re,json
from pathlib import Path
from old_season_webform import selects,form,pager,record_table,attrs
import kbo_futures_crawl as base
root=Path('/home/bitnami/wesiper/official-season-totals-1982-2000')
url='https://www.koreabaseball.com/Record/Player/HitterBasic/BasicOld.aspx';page=gzip.open(root/'Hitter-initial.html.gz','rt',encoding='utf-8').read()
for raw in re.findall(r'<input\b([^>]*)>',page,re.I|re.S):
 a=attrs(raw)
 if a.get('type')!='hidden':print('INPUT',a,flush=True)
for script in re.findall(r'<script\b[^>]*>(.*?)</script>',page,re.I|re.S):
 if 'ddlSeason' in script or 'lbtnOrderBy' in script:print('SCRIPT',script[:6000],flush=True)
year=next(k for k in selects(page) if 'ddlSeason' in k)
session=requests.Session();initial=session.get(url,timeout=30);initial.raise_for_status();initial.encoding='utf-8';page=initial.text
data=form(page,year,{year:'1982'});print('FORM_KEYS',list(data),flush=True)
r=session.post(url,data=data,timeout=30);r.raise_for_status();r.encoding='utf-8';page=r.text
print('RESPONSE',r.url,re.findall(r'<title>(.*?)</title>',page,re.S),page[:200],flush=True)
with gzip.open(root/'probe-1982.html.gz','wt',encoding='utf-8') as f:f.write(r.text)
print('SELECTED',{k:v['value'] for k,v in selects(page).items()},flush=True)
table=record_table(page);print('TABLE',[base.row_values(row) for row in table['rows']][:4],flush=True);print('PAGER',pager(page),flush=True)
for line in page.splitlines():
 if any(v in line for v in ('lbtnOrderBy','규정','전체','btnSearch')):print('LINE',line.strip()[:500],flush=True)
gcell=next(c for c in table['rows'][0]['cells'] if c['text']=='G');print('G_HEADER',gcell['raw'],flush=True)
sortcol=re.search(r"sort\('([^']+)'\)",gcell['raw'])[1];data=form(page,year.rsplit('$',2)[0]+'$lbtnOrderBy')
for k in data:
 if k.endswith('$hfOrderByCol'):data[k]=sortcol
 if k.endswith('$hfOrderBy'):data[k]='DESC'
 if k.endswith('$hfPage'):data[k]='1'
r=session.post(url,data=data,timeout=30);r.raise_for_status();r.encoding='utf-8';page=r.text
with gzip.open(root/'probe-1982-sortG.html.gz','wt',encoding='utf-8') as f:f.write(page)
print('SORT_G_PAGER',pager(page),flush=True);print('SORT_G_ROWS',[base.row_values(row) for row in record_table(page)['rows']][-3:],flush=True)
