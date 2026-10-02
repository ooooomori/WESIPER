import requests
from old_season_webform import selects,form,record_table
from kbo_futures_crawl import row_values
url='https://www.koreabaseball.com/Record/Player/HitterBasic/Basic1.aspx'
for mode in ('inner','outer'):
 s=requests.Session();r=s.get(url);r.encoding='utf-8';page=r.text;c=selects(page);y=next(k for k in c if 'Season' in k);sr=next(k for k in c if 'Series' in k)
 d=form(page,sr if mode=='inner' else sr.rsplit('$',1)[0],{y:'1992',sr:'5'});r=s.post(url,data=d);r.encoding='utf-8';page=r.text
 d=form(page,y.rsplit('$',2)[0]+'$lbtnOrderBy',{y:'1992',sr:'5'})
 for k in d:
  if k.endswith('$hfOrderByCol'):d[k]='GAME_CN'
  if k.endswith('$hfOrderBy'):d[k]='DESC'
 r=s.post(url,data=d);r.encoding='utf-8';page=r.text
 print(mode,[(k,v['value']) for k,v in selects(page).items() if 'Series' in k or 'Season' in k]);print([row_values(row) for row in record_table(page)['rows'][:3]])
