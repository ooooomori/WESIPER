"""Enrich 1982-2000 batting totals with official SAC/SF and verify rates."""
import argparse,concurrent.futures,gzip,hashlib,json,re,time
from decimal import Decimal,ROUND_HALF_UP
from pathlib import Path
import requests
from collect_official_old_seasons import ROOT,atomic
from old_season_webform import form,selects,pager,record_table
from kbo_futures_crawl import row_values
from backfill_kbo_early_official import connect
SUP=ROOT/'rate-supplement'

def fetch(session,url,path,data=None):
 for attempt in range(4):
  try:
   r=session.post(url,data=data,timeout=30) if data else session.get(url,timeout=30)
   r.raise_for_status();r.encoding='utf-8';page=r.text
   if not selects(page):raise ValueError('official controls absent')
   path.parent.mkdir(parents=True,exist_ok=True)
   with gzip.open(path,'wb') as f:f.write(page.encode())
   time.sleep(.15);return page
  except (requests.RequestException,ValueError):
   if attempt==3:raise
   time.sleep(2**attempt)

def collect(year,series,part):
 dest=SUP/str(year)/f's{series}'/part;manifest=dest/'manifest.json'
 if manifest.exists():return json.loads(manifest.read_text())
 url=f'https://www.koreabaseball.com/Record/Player/HitterBasic/{part}.aspx'
 s=requests.Session();s.headers['User-Agent']='Mozilla/5.0';page=fetch(s,url,dest/'initial.html.gz');controls=selects(page)
 y=next(k for k in controls if 'ddlSeason' in k);sr=next(k for k in controls if 'ddlSeries' in k)
 # Basic1/2 bind the selected year on the sorting event, unlike BasicOld.
 target=y.rsplit('$',2)[0]+'$lbtnOrderBy';data=form(page,target,{y:str(year),sr:str(series)})
 for k in data:
  if k.endswith('$hfOrderByCol'):data[k]='GAME_CN'
  if k.endswith('$hfOrderBy'):data[k]='DESC'
  if k.endswith('$hfPage'):data[k]='1'
 page=fetch(s,url,dest/'page-001.html.gz',data);rows=[];seen=set();number=1
 while True:
  selected=selects(page)
  if selected[y]['value']!=str(year) or selected[sr]['value']!=str(series):raise ValueError('official selection mismatch')
  t=record_table(page);headers=row_values(t['rows'][0]);navigation=pager(page)
  active=[int(p['text']) for p in navigation if p['active'] and p['text'].isdigit()]
  if active and active!=[number]:raise ValueError('page did not advance')
  source=dest/f'page-{number:03}.html.gz';sha=hashlib.sha256(page.encode()).hexdigest()
  for row in t['rows'][1:]:
   vals=row_values(row)
   if len(vals)!=len(headers):
    if '없' in ' '.join(vals):continue
    raise ValueError('row width mismatch')
   ids={int(v) for cell in row['cells'] for v in re.findall(r'playerId=(\d+)',cell['raw'])}
   if len(ids)!=1:raise ValueError('official player link missing')
   pid=ids.pop()
   if pid in seen:raise ValueError('duplicate linked player')
   seen.add(pid);rows.append({'player_id':pid,'values':dict(zip(headers,vals)),'source_url':url,'source_file':str(source.relative_to(ROOT)),'source_sha256':sha})
  nxt=next((p for p in navigation if p['text'].isdigit() and int(p['text'])==number+1),None)
  if nxt is None:nxt=next((p for p in navigation if 'btnNext' in p.get('target','')),None)
  if not nxt:break
  number+=1
  if number>100:raise ValueError('pagination guard')
  page=fetch(s,url,dest/f'page-{number:03}.html.gz',form(page,nxt['target']))
 result={'year':year,'series_id':series,'part':part,'rows':rows,'complete':True};atomic(manifest,result)
 print('cached',year,series,part,len(rows),flush=True);return result

def ratio(n,d):return None if not d else (Decimal(n)/Decimal(d)).quantize(Decimal('.001'),rounding=ROUND_HALF_UP)

def run(write=False):
 originals={};tasks=[]
 for p in sorted(ROOT.glob('*/Hitter/s*/ALL/manifest.json')):
  g=json.loads(p.read_text())
  if not g['records'] or g['series_id']!=0:continue
  key=(g['year'],g['series_id']);originals[key]=g
  tasks.extend((key[0],key[1],part) for part in ('Basic1','Basic2'))
 failures=[];fetched={}
 with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
  futures={pool.submit(collect,*task):task for task in tasks}
  for future in concurrent.futures.as_completed(futures):
   task=futures[future]
   try:fetched[task]=future.result()
   except Exception as e:failures.append({'task':task,'error':str(e)})
 plans=[]
 for (year,series),g in originals.items():
  if (year,series,'Basic1') not in fetched or (year,series,'Basic2') not in fetched:continue
  try:
   a={r['player_id']:r for r in fetched[(year,series,'Basic1')]['rows']};b={r['player_id']:r for r in fetched[(year,series,'Basic2')]['rows']};old={r['player_id']:r for r in g['records']}
   if set(a)!=set(old) or set(b)!=set(old):raise ValueError('supplement player coverage differs from BasicOld')
   for pid,record in old.items():
    v=record['values'];av=a[pid]['values'];bv=b[pid]['values']
    for header in ('G','PA','AB','H','2B','3B','HR','RBI'):
     if int(av[header])!=int(v[header]):raise ValueError(f'Basic1 count mismatch {pid} {header}')
    for header in ('BB','HBP','SO','GDP','SB','CS'):
     if header in bv and int(bv[header])!=int(v[header]):raise ValueError(f'Basic2 count mismatch {pid} {header}')
    tb=int(v['H'])+int(v['2B'])+2*int(v['3B'])+3*int(v['HR'])
    if tb!=int(av['TB']):raise ValueError(f'TB mismatch {pid}')
    sf=int(av['SF']);den=int(v['AB'])+int(v['BB'])+int(v['HBP'])+(sf if year>=1986 else 0);num=int(v['H'])+int(v['BB'])+int(v['HBP'])
    obp=ratio(num,den);slg=ratio(tb,int(v['AB']));ops=ratio(Decimal(num)/den+Decimal(tb)/int(v['AB']),1) if den and int(v['AB']) else None
    for header,computed in (('OBP',obp),('SLG',slg),('OPS',ops)):
     if computed is not None and bv.get(header) not in (None,'','-') and abs(Decimal(bv[header])-computed)>Decimal('.001'):raise ValueError(f'official rate mismatch {pid} {header}')
    plans.append({'year':year,'series_id':series,'player_id':pid,'r':int(av['R']),'sh':int(av['SAC']),'sf':sf,'tb':tb,'ibb':int(bv['IBB']) if bv.get('IBB','').isdigit() else None,'obp':str(obp) if obp is not None else None,'slg':str(slg) if slg is not None else None,'ops':str(ops) if ops is not None else None,'sources':[a[pid],b[pid]]})
  except Exception as e:failures.append({'year':year,'series_id':series,'error':str(e)})
 atomic(SUP/'validation.json',{'validated_rows':len(plans),'failures':failures,'scope':'regular','unsupported_postseason':'Basic1 returns regular-season values and Basic2 rejects the old postseason selection; these mismatches are not imported','legacy_obp':'1982-1985 annual official OBP excludes SF; full-career OBP includes all SF'});atomic(SUP/'plan.json',plans)
 print('validated',len(plans),'failures',len(failures),flush=True)
 if failures or not write:return
 con=connect();table='kbo_player_season_batting_totals';receipt=SUP/'write-receipt.json';done=json.loads(receipt.read_text()) if receipt.exists() else []
 try:
  backup=SUP/'batting-before-enrichment.json.gz'
  if not backup.exists():
   with con.cursor() as q:q.execute(f'SELECT * FROM {table}');cols=[d[0] for d in q.description];snapshot=[dict(zip(cols,r)) for r in q.fetchall()]
   with gzip.open(backup,'wt',encoding='utf-8') as f:json.dump(snapshot,f,ensure_ascii=False,default=str)
  for p in plans:
   key=f"{p['year']}/{p['series_id']}/{p['player_id']}"
   if key in done:continue
   with con.cursor() as q:
    q.execute(f'SELECT row_scope,filter_team_code,raw_record FROM {table} WHERE league_level=1 AND year=%s AND series_id=%s AND player_id=%s',(p['year'],p['series_id'],p['player_id']))
    rows=q.fetchall()
    if len(rows)!=2:raise ValueError('unexpected total/team row count')
    for scope,team,raw in rows:
     original=json.loads(raw);original['_rate_supplement']=p['sources']
     q.execute(f'UPDATE {table} SET r=%s,sh=%s,sf=%s,tb=%s,ibb=%s,obp=%s,slg=%s,ops=%s,raw_record=%s WHERE league_level=1 AND year=%s AND series_id=%s AND player_id=%s AND row_scope=%s AND filter_team_code=%s',tuple(p[k] for k in ('r','sh','sf','tb','ibb','obp','slg','ops'))+(json.dumps(original,ensure_ascii=False),p['year'],p['series_id'],p['player_id'],scope,team))
   con.commit();done.append(key);atomic(receipt,done)
  atomic(SUP/'write-summary.json',{'season_records':len(done),'database_rows':len(done)*2,'failures':[]});print('ENRICHMENT COMMITTED',len(done)*2,flush=True)
 finally:con.close()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--write',action='store_true');a=p.parse_args();run(a.write)
