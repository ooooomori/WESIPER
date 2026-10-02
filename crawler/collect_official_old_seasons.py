"""Complete official historical season listings, including low-usage players and team filters."""
import argparse,concurrent.futures,gzip,hashlib,json,re,time
from pathlib import Path
import requests
import kbo_futures_crawl as base
from old_season_webform import selects,form,pager,record_table
ROOT=Path('/home/bitnami/wesiper/official-season-totals-1982-2000')
SERIES={0:'정규시즌',1:'시범경기',3:'준플레이오프',4:'와일드카드',5:'플레이오프',7:'한국시리즈'}
def atomic(path,data):
 path.parent.mkdir(parents=True,exist_ok=True);tmp=path.with_suffix(path.suffix+'.tmp');tmp.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8');tmp.replace(path)
def request(session,url,path,data=None):
 for attempt in range(4):
  try:
   r=session.post(url,data=data,timeout=30) if data is not None else session.get(url,timeout=30)
   r.raise_for_status();r.encoding='utf-8';page=r.text
   if not selects(page):raise ValueError('official response missing record controls')
   path.parent.mkdir(parents=True,exist_ok=True)
   with gzip.open(path,'wt',encoding='utf-8') as f:f.write(page)
   time.sleep(.15);return page
  except (requests.RequestException,ValueError):
   if attempt==3:raise
   time.sleep(2**attempt)
def values_checked(page,year,series,team):
 controls=selects(page)
 for marker,wanted in [('ddlSeason',str(year)),('ddlSeries',str(series)),('ddlTeam',team)]:
  actual=next(v['value'] for k,v in controls.items() if marker in k)
  if actual!=wanted:raise ValueError(f'official selection mismatch {marker}: {actual}/{wanted}')
def collect_group(year,role,series,team=''):
 dest=ROOT/str(year)/role/f's{series}'/(team or 'ALL');manifest=dest/'manifest.json'
 if manifest.exists():return json.loads(manifest.read_text())
 attempt=1
 while (dest/f'attempt-{attempt}').exists():attempt+=1
 raw=dest/f'attempt-{attempt}';session=requests.Session();session.headers.update({'User-Agent':'Mozilla/5.0','Referer':'https://www.koreabaseball.com/'})
 url=f'https://www.koreabaseball.com/Record/Player/{role}Basic/BasicOld.aspx'
 try:
  page=request(session,url,raw/'initial.html.gz');controls=selects(page);year_key=next(k for k in controls if 'ddlSeason' in k);series_key=next(k for k in controls if 'ddlSeries' in k);team_key=next(k for k in controls if 'ddlTeam' in k)
  page=request(session,url,raw/'selected.html.gz',form(page,year_key,{year_key:str(year),series_key:str(series),team_key:''}))
  selected=selects(page)
  if str(year) not in {o['value'] for o in selected[year_key]['options']} and selected[series_key]['value']==str(series):
   result={'year':year,'league_level':1,'series_id':series,'series_name':SERIES[series],'role':role,'row_scope':'team' if team else 'total','filter_team_code':team,'teams':[],'headers':[],'pages':[],'records':[],'complete':True,'coverage_status':'year_not_offered_for_series','selection_source_file':str((raw/'selected.html.gz').relative_to(ROOT)),'offered_years':[o['value'] for o in selected[year_key]['options']]}
   atomic(manifest,result);print(year,role,SERIES[series],'year not offered by official selector',flush=True);return result
  if team:page=request(session,url,raw/'team-selected.html.gz',form(page,team_key,{team_key:team}))
  values_checked(page,year,series,team);controls=selects(page);teams=[{'code':o['value'],'name':o['text']} for o in controls[team_key]['options'] if o['value']]
  table=record_table(page);gcell=next(c for c in table['rows'][0]['cells'] if c['text']=='G');sortcol=re.search(r"sort\('([^']+)'\)",gcell['raw'])[1];target=year_key.rsplit('$',2)[0]+'$lbtnOrderBy';data=form(page,target)
  for k in data:
   if k.endswith('$hfOrderByCol'):data[k]=sortcol
   if k.endswith('$hfOrderBy'):data[k]='DESC'
   if k.endswith('$hfPage'):data[k]='1'
  page=request(session,url,raw/'page-001.html.gz',data);records=[];seen=set();pages=[];expected=1
  while True:
   values_checked(page,year,series,team);table=record_table(page);headers=base.row_values(table['rows'][0]);navigation=pager(page);active=[int(p['text']) for p in navigation if p['active'] and p['text'].isdigit()]
   if active and active!=[expected]:raise ValueError('official pagination did not advance as requested')
   path=raw/f'page-{expected:03}.html.gz';count=0
   for row in table['rows'][1:]:
    vals=base.row_values(row)
    if len(vals)!=len(headers):
     if '없' in ' '.join(vals) or '조회' in ' '.join(vals):continue
     raise ValueError('official row/header width mismatch')
    links={int(v) for cell in row['cells'] for v in re.findall(r'playerId=(\d+)',cell['raw'],re.I)}
    if len(links)!=1:raise ValueError('official player row lacks unique linked player ID')
    pid=next(iter(links));key=(pid,vals[2])
    if key in seen:raise ValueError('duplicate player/team across official pages')
    seen.add(key);count+=1;records.append({'player_id':pid,'player_name':vals[1],'team_name':vals[2],'values':dict(zip(headers,vals)),'source_url':url,'source_file':str(path.relative_to(ROOT)),'source_sha256':hashlib.sha256(page.encode()).hexdigest()})
   pages.append({'page':expected,'rows':count,'file':str(path.relative_to(ROOT))})
   nextpage=next((p for p in navigation if p['text'].isdigit() and int(p['text'])==expected+1),None)
   if nextpage is None:
    if any(p['text'].isdigit() and int(p['text'])>expected for p in navigation):raise ValueError('pagination gap')
    nextpage=next((p for p in navigation if p['target'].endswith('$btnNext')),None)
   if not nextpage:break
   if count==0:raise ValueError('empty page unexpectedly has a next page')
   expected+=1
   if expected>100:raise ValueError('pagination guard')
   page=request(session,url,raw/f'page-{expected:03}.html.gz',form(page,nextpage['target']))
  result={'year':year,'league_level':1,'series_id':series,'series_name':SERIES[series],'role':role,'row_scope':'team' if team else 'total','filter_team_code':team,'teams':teams,'headers':headers,'pages':pages,'records':records,'complete':True}
  atomic(manifest,result);print(year,role,SERIES[series],team or 'ALL',len(records),'rows',len(pages),'pages',flush=True);return result
 finally:session.close()
def run(start,end,team_splits):
 jobs=[(year,role,series,'') for year in range(start,end+1) for role in ('Hitter','Pitcher') for series in SERIES];failures=[];results=[]
 def batch(jobs):
  with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
   futures={pool.submit(collect_group,*job):job for job in jobs}
   for future in concurrent.futures.as_completed(futures):
    try:results.append(future.result())
    except Exception as e:
     failures.append({'job':futures[future],'error':str(e)});print('COLLECTION FAIL',futures[future],str(e),flush=True)
    atomic(ROOT/'collection-progress.json',{'completed_groups':len(results),'failures':failures})
 batch(jobs)
 if team_splits:
  teamjobs=[]
  for result in list(results):
   if not result['records']:continue
   for team in result['teams']:teamjobs.append((result['year'],result['role'],result['series_id'],team['code']))
  batch(teamjobs)
 summary={'completed_groups':len(results),'failures':failures,'records':sum(len(v['records']) for v in results),'total_scope_records':sum(len(v['records']) for v in results if v['row_scope']=='total'),'team_scope_records':sum(len(v['records']) for v in results if v['row_scope']=='team'),'empty_groups':[{'year':v['year'],'role':v['role'],'series_id':v['series_id'],'team':v['filter_team_code']} for v in results if not v['records']]};atomic(ROOT/'collection-summary.json',summary);print('COLLECTION SUMMARY',{k:v for k,v in summary.items() if k!='empty_groups'},flush=True)
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--start-year',type=int,default=1982);p.add_argument('--end-year',type=int,default=2000);p.add_argument('--team-splits',action='store_true');a=p.parse_args()
 if not 1982<=a.start_year<=a.end_year<=2000:raise ValueError('only 1982--2000 authorized')
 run(a.start_year,a.end_year,a.team_splits)
