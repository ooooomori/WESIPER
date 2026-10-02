"""Resolve number-change identities and verify current numbers against KBO profiles."""
import gzip,json,re
from collections import defaultdict,Counter
from concurrent.futures import ThreadPoolExecutor
import requests
from collect_player_movements import ROOT,atomic,clean
from backfill_kbo_early_official import connect
from player_identity_corrections import kim_taeuk_id

ALIASES={'넥센':'키움','SK':'SSG'}
def team(value):return ALIASES.get(value,value)
def profile(pid):
 path=ROOT/'profiles'/f'{pid}.html.gz';path.parent.mkdir(exist_ok=True)
 if path.exists():
  with gzip.open(path,'rt',encoding='utf-8') as f:page=f.read()
 else:
  r=requests.get(f'https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId={pid}',headers={'User-Agent':'Mozilla/5.0'},timeout=45);r.raise_for_status();r.encoding='utf-8-sig';page=r.text
  with gzip.open(path,'wt',encoding='utf-8') as f:f.write(page)
 fields={}
 for li in re.findall(r'<li\b[^>]*>(.*?)</li>',page,re.I|re.S):
  text=clean(li)
  if ':' in text and len(text)<180:
   key,value=text.split(':',1);fields[key.strip()]=value.strip()
 assert fields.get('선수명'),f'profile identity missing {pid}'
 team_codes={'LG':'LG','OB':'두산','SS':'삼성','HT':'KIA','LT':'롯데','HH':'한화','NC':'NC','KT':'KT','WO':'키움','SK':'SSG'}
 emblem=re.search(r'<h4[^>]*id=[\'"]h4Team[\'"][^>]*',page,re.I)
 club=re.search(r'regular/(\d{4})/emblem_(\w+)',emblem[0]) if emblem else None
 return {'player_id':pid,'name':fields['선수명'],'back_no':fields.get('등번호','').removeprefix('No.').strip() or None,'position':fields.get('포지션'),'draft':fields.get('지명순위'),'last_profile_year':int(club[1]) if club else None,'team':team_codes.get(club[2]) if club else None,'source_file':str(path.relative_to(ROOT))}
def run():
 plan=json.loads((ROOT/'plan.json').read_text());parents=json.loads((ROOT/'players-before.json').read_text());names=defaultdict(set);parent={p[0]:p for p in parents}
 for pid,name,oldname,*_ in parents:
  names[name].add(pid)
  if oldname:
   for alias in re.split(r'[,/;·\s()]+',oldname):
    if alias:names[alias].add(pid)
 unresolved=[];con=connect();latest={};unparsed=[]
 for row in plan['rows']:
  name=re.sub(r'\([^)]*\)$','',row['player_text']).strip();row['player_name']=name
  candidates=names.get(name,set());pid=next(iter(candidates)) if len(candidates)==1 else None
  confirmed=kim_taeuk_id(name,row['team'],row['year'])
  if confirmed is not None:
   if confirmed not in parent:raise ValueError('Confirmed player parent missing')
   pid=confirmed
  if row['event_type']=='등번호 변경':
   match=re.match(r'(?:No\.?\s*)?(\d{1,3})\s*(?:→|->|⇒|➡)\s*(?:No\.?\s*)?(\d{1,3})(.*)$',row['note'] or '')
   if match:row['old_back_no'],row['new_back_no']=match.group(1,2)
   else:
    only=re.fullmatch(r'(\d{1,3})\([^)]*적용\)',row['note'] or '')
    if only:row['new_back_no']=only[1]
    else:unparsed.append({k:row[k] for k in ('event_date','team','player_text','note')})
   if pid is None and candidates:
    hits=[]
    with con.cursor() as c:
     for candidate in candidates:
      for table in ('kbo_season_records','kbo_season_pitch_records'):
       c.execute(f'SELECT DISTINCT team FROM {table} WHERE league_level IN (1,2) AND player_id=%s AND game_date BETWEEN %s AND %s',(candidate,f"{row['year']}-01-01",f"{row['year']}-12-31"))
       if team(row['team']) in [team(r[0]) for r in c.fetchall()]:hits.append(candidate);break
    if len(set(hits))==1:pid=hits[0]
   if pid is None and candidates:
    role=re.search(r'\(([^)]+)\)',row['player_text']);role=role[1] if role else None
    matches=[];number_matches=[]
    for candidate in candidates:
     if candidate<10000:continue
     try:
      official=profile(candidate)
      draft=re.match(r'(\d{2}|\d{4})\s',official['draft'] or '')
      debut=int(draft[1]) if draft else None
      if debut is not None and debut<100:debut+=2000 if debut<50 else 1900
      if debut is not None and debut>row['year']:continue
      if role and role not in (official['position'] or ''):continue
      if official['team']==team(row['team']) or row['team'] in (official['draft'] or ''):
       matches.append(candidate)
       if official['back_no']==row['new_back_no']:number_matches.append(candidate)
     except Exception:continue
    if len(matches)==1:pid=matches[0]
    elif len(number_matches)==1 and row['year']==2026:pid=number_matches[0]
   if pid is None:unresolved.append({k:row[k] for k in ('source_key','event_date','team','player_text','note')}|{'candidate_player_ids':sorted(candidates)})
   else:
    prior=latest.get(pid)
    if prior is None or row['event_date']>prior['event_date']:latest[pid]=row
    elif row['event_date']==prior['event_date'] and row['new_back_no']!=prior['new_back_no']:raise ValueError(f'Conflicting latest numbers {pid}')
  row['player_id']=pid
 con.close();verified={};errors=[]
 def fetch(pid):
  try:return pid,profile(pid),None
  except Exception as e:return pid,None,str(e)
 with ThreadPoolExecutor(max_workers=4) as pool:
  for pid,p,error in pool.map(fetch,latest):
   if error:errors.append({'player_id':pid,'error':error})
   else:verified[pid]=p
 updates=[];already=[];superseded=[]
 for pid,row in latest.items():
  if pid not in verified:continue
  p=parent[pid];official=verified[pid]['back_no'];dbno=p[5];target=row['new_back_no']
  if not target:continue
  # Number strings deliberately preserve 00, 01, and 07.
  if official!=target:
   superseded.append({'player_id':pid,'name':p[1],'event_date':row['event_date'],'event_team':row['team'],'event_number':target,'database_number':dbno,'official_current_number':official})
   # Retired player profiles may retain a later playing number. Profiles
   # describing coaches omit the player position; do not import those numbers.
   playing_profile=any((verified[pid]['position'] or '').startswith(role) for role in ('투수','포수','내야수','외야수'))
   if not official or not (p[6] or playing_profile):continue
   target=official
  item={'player_id':pid,'name':p[1],'before':dbno,'after':target,'event_date':row['event_date'],'source_key':row['source_key'],'profile':verified[pid]}
  (already if dbno==target else updates).append(item)
 atomic(ROOT/'resolved-plan.json',plan)
 result={'updates':updates,'already_current':already,'superseded_events':superseded,'unresolved_number_identities':unresolved,'unparsed_numbers':unparsed,'profile_failures':errors,'resolved_number_players':len(latest),'movement_rows':len(plan['rows']),'mapped_movement_rows':sum(r['player_id'] is not None for r in plan['rows'])}
 atomic(ROOT/'number-plan.json',result)
 print(json.dumps({k:len(v) if isinstance(v,list) else v for k,v in result.items()},ensure_ascii=False),flush=True)
 print('unparsed',json.dumps(unparsed,ensure_ascii=False));print('unresolved',json.dumps(unresolved,ensure_ascii=False));print('updates',json.dumps(updates,ensure_ascii=False))
if __name__=='__main__':run()
