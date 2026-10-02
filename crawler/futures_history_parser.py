"""Strict historical wrapper. Daily crawler behavior remains unchanged."""
import gzip,json,re
from collections import defaultdict
import kbo_futures_crawl as base
from kbo_early_parser import Resolver as CatalogResolver,attrs
from backfill_futures_history import ROOT,cached,atomic
from futures_history_corrections import corrected_page,game_settings,winning_override
from player_identity_corrections import kim_taeuk_id

original_winning=base.winning_event
def historical_winning(notes,events,game_id):return winning_override(notes,events,game_id,original_winning)
base.winning_event=historical_winning

original_run_out=base.resolve_run_out_players
def historical_run_out(lookup,lineups,game_id):
 overrides=[v for v in game_settings(game_id).get('running',[]) if v['label']=='주루사']
 if overrides:
  resolved={};remaining=dict(lookup)
  for item in overrides:
   key=(item['name'],item['inning'])
   if key not in remaining:raise ValueError('user run-out correction not found in official notes')
   found=[b for group in lineups.values() for b in group if int(b['player_id'])==item['player_id'] and b['name']==item['name']]
   if len(found)!=1:raise ValueError('user run-out player does not uniquely match official lineup')
   resolved[(item['player_id'],item['inning'])]=remaining.pop(key)
  resolved.update(original_run_out(remaining,lineups,game_id));return resolved
 try:return original_run_out(lookup,lineups,game_id)
 except ValueError:
  resolved={}
  for key,count in lookup.items():
   try:resolved.update(original_run_out({key:count},lineups,game_id))
   except ValueError:
    name,inning=key
    candidates=[b for group in lineups.values() for b in group if b['name']==name and '주' in b['pos'] and not (inning<=len(b['innings']) and b['innings'][inning-1])]
    if len(candidates)!=1:raise
    resolved[(int(candidates[0]['player_id']),inning)]=count
  return resolved
base.resolve_run_out_players=historical_run_out
original_pitchers=base.parse_pitchers
def historical_pitchers(tables,teams):
 result=original_pitchers(tables,teams)
 if any(not r['name'] for group in result.values() for r in group):raise ValueError('official pitcher player name missing')
 for group in result.values():
  for row in group:row['record']={'홀드':'홀','세이브':'세','무':None}.get(row.get('record'),row.get('record'))
 return result
base.parse_pitchers=historical_pitchers

def identity_team(value,year):
 value=base.normalize_team(value)
 if value=='기아':return 'KIA'
 if value in ('넥센','히어로즈','화성'):return '키움'
 if value=='고양' and 2015<=year<=2018:return 'NC'
 if value=='고양' and year>=2019:return '키움'
 return value

class Resolver(CatalogResolver):
 def __init__(self,connection):
  super().__init__(connection,ROOT);self.context={};self.daily_memory={};self.connection=connection;self.calls={};self.history={}
  with connection.cursor() as c:
   c.execute('SELECT player_id,name FROM kbo_player_data');self.current_names={int(pid):name for pid,name in c.fetchall()}
  aliases=ROOT/'existing-game-name-aliases.json'
  if aliases.exists():
   for p in json.loads(aliases.read_text()):self.by_name[p['name']].setdefault(int(p['player_id']),p)
 def daily(self,pid,year,role):
  key=(pid,year,role)
  if key in self.daily_memory:return self.daily_memory[key]
  endpoint='Hitter' if role=='batter' else 'Pitcher'
  url=f'https://www.koreabaseball.com/Futures/Player/{endpoint}Daily.aspx?playerId={pid}'
  folder=ROOT/str(year)/'player-daily';final=folder/f'{endpoint}-{pid}-{year}.html.gz'
  if final.exists():page=gzip.open(final,'rt',encoding='utf-8').read()
  else:
   page=cached(url,folder/f'{endpoint}-{pid}-initial.html.gz')
   heading=re.search(r'<h6>\s*(\d{4})\s+일자별 성적',page)
   if not heading or int(heading[1])!=year:
    select=next((m for m in re.finditer(r'<select\b([^>]*)>(.*?)</select>',page,re.I|re.S) if 'ddlYear' in str(attrs(m[1]))),None)
    if select is None or not re.search(rf'<option\b[^>]*value=[\'\"]{year}[\'\"]',select[2],re.I):self.daily_memory[key]=[];return []
    form={}
    for m in re.finditer(r'<input\b([^>]*)>',page,re.I|re.S):
     a=attrs(m[1])
     if a.get('type','').lower()=='hidden' and a.get('name'):form[a['name']]=a.get('value','')
    name=attrs(select[1])['name'];form.update({'__EVENTTARGET':name,'__EVENTARGUMENT':'',name:str(year)})
    page=cached(url,final,data=form)
   else:
    final.parent.mkdir(parents=True,exist_ok=True)
    with gzip.open(final,'wt',encoding='utf-8') as f:f.write(page)
  if not re.search(rf'<h6>\s*{year}\s+일자별 성적',page):raise ValueError(f'official daily year mismatch {pid}/{year}')
  lines=[]
  for t in base.parse_tables(page):
   rows=[base.row_values(r) for r in t['rows']]
   if rows and rows[0] and re.fullmatch(r'\d{1,2}월',rows[0][0]):lines.extend(r for r in rows[1:] if len(r)>=2 and re.fullmatch(r'\d{2}\.\d{2}',r[0]))
  self.daily_memory[key]=lines;return lines
 def resolve(self,game_id,name,team,season,role,*args,**kwargs):
  metadata=self.context.get((team,role,name),[])
  callkey=(team,role,name);index=self.calls.get(callkey,0);self.calls[callkey]=index+1
  meta=metadata[index] if index<len(metadata) else {}
  if role=='batter' and args and '투' in args[0]:
   role='pitcher';pitch_metadata=self.context.get((team,role,name),[])
   if index<len(pitch_metadata):meta=pitch_metadata[index]
  links={int(pid) for pid in re.findall(r'playerId=(\d+)',meta.get('raw',''),re.I)}
  if len(links)==1:return next(iter(links))
  if links:raise ValueError('conflicting official player links')
  confirmed=kim_taeuk_id(name,team,season)
  if confirmed is not None:
   if confirmed not in self.current_names:raise ValueError('Confirmed Kim Tae-uk parent missing')
   return confirmed
  confirmed_id=game_settings(game_id).get('player_ids',{}).get(name)
  if confirmed_id:
   if confirmed_id not in self.current_names:raise ValueError('confirmed historical identity missing official parent')
   return confirmed_id
  candidates=dict(self.by_name.get(name,{}))
  if len(candidates)!=1:
   searchkey=('official-search',name)
   if searchkey not in self.memory:self.memory[searchkey]=self.search(name,season)
   candidates.update(self.memory[searchkey]);self.by_name[name].update(candidates)
  if len(candidates)==1:return next(iter(candidates))
  resolutions=__import__('pathlib').Path(__file__).with_name('futures_allstar_identity_resolutions.json')
  if resolutions.exists():
   for item in json.loads(resolutions.read_text(encoding='utf-8')):
    if (game_id,team,name,role)==tuple(item[k] for k in ('game_id','team','name','role')):
     if item['player_id'] not in candidates:raise ValueError('official Allstar resolution outside candidate IDs')
     return item['player_id']
  roster_path=__import__('pathlib').Path(__file__).with_name('futures_allstar_rosters.json')
  if team in ('북부','남부') and roster_path.exists():
   supplied=json.loads(roster_path.read_text(encoding='utf-8')).get(game_id,{}).get(team,{}).get(name)
   if supplied:
    wanted={identity_team(t,season) for t in supplied};confirmed=[]
    table='kbo_season_records' if role=='batter' else 'kbo_season_pitch_records'
    idx='idx_league_player_date' if role=='batter' else 'idx_pitch_league_player_date'
    for pid in candidates:
     with self.connection.cursor() as c:
      name_field='player_name' if role=='batter' else 'NULL'
      c.execute(f'SELECT DISTINCT team,{name_field} FROM {table} FORCE INDEX ({idx}) WHERE league_level=2 AND player_id=%s AND game_date>=%s AND game_date<%s',(pid,f'{season}-01-01',f'{season+1}-01-01'))
      rows=c.fetchall()
     if any(identity_team(t,season) in wanted and (role=='pitcher' or n==name) for t,n in rows):confirmed.append(pid)
    if len(confirmed)==1:return confirmed[0]
    if confirmed:candidates={pid:candidates[pid] for pid in confirmed}
  evidence=[];matches=[]
  for pid in candidates:
   try:
    rows=[r for r in self.daily(pid,season,role) if r[0]==game_id[4:6]+'.'+game_id[6:8] and identity_team(r[1],season)==identity_team(kwargs.get('opponent'),season)]
    if role=='batter' and meta.get('stats') is not None:rows=[r for r in rows if len(r)>9 and tuple(int(r[i]) for i in (3,5,9,4))==tuple(meta['stats'])]
    if role=='pitcher' and meta.get('pitch_stats') is not None:rows=[r for r in rows if len(r)>13 and (int(r[5]),r[6],int(r[12]),int(r[13]))==tuple(meta['pitch_stats'])]
    if rows:matches.append(pid)
    evidence.append({'player_id':pid,'rows':rows})
   except Exception as e:evidence.append({'player_id':pid,'error':str(e)})
  if len(matches)>1:
   detailed=[]
   for e in evidence:
    if role=='batter' and meta.get('avg') not in (None,'','-') and any(r[-1]==meta['avg'] for r in e.get('rows',[])):detailed.append(e['player_id'])
    if role=='pitcher' and meta.get('pitch_details') is not None and any(len(r)>11 and (int(r[7]),int(r[8]),int(r[9])+int(r[10]),int(r[11]))==tuple(meta['pitch_details']) for r in e.get('rows',[])):detailed.append(e['player_id'])
   if len(detailed)==1:matches=detailed
  if len(matches)==1 and not any('error' in e for e in evidence):return matches[0]
  # Existing records can identify a historical roster when official daily pages
  # no longer expose that season. Never prefer a roster match over conflicting
  # daily evidence, and never score/tie-break multiple roster matches.
  if not matches:
   roster=[]
   for pid in candidates:
    key=(pid,season,role)
    if key not in self.history:
     table='kbo_season_records' if role=='batter' else 'kbo_season_pitch_records'
     idx='idx_league_player_date' if role=='batter' else 'idx_pitch_league_player_date'
     with self.connection.cursor() as c:
      c.execute(f'SELECT DISTINCT team FROM {table} FORCE INDEX ({idx}) WHERE league_level=1 AND player_id=%s AND game_date>=%s AND game_date<%s',(pid,f'{season}-01-01',f'{season+1}-01-01'))
      self.history[key]={identity_team(r[0],season) for r in c.fetchall()}
    if identity_team(team,season) in self.history[key]:roster.append(pid)
   if len(roster)==1 and not any('error' in e for e in evidence):return roster[0]
  raise LookupError(json.dumps({'game_id':game_id,'year':season,'team':team,'name':name,'role':role,'candidates':list(candidates),'evidence':evidence},ensure_ascii=False))

def parse(g,page,series,resolver):
 g=dict(g)
 for field in ('away_team','home_team'):
  if g[field]=='기아':g[field]='KIA'
 page,user_corrections=corrected_page(g,page)
 tables=base.parse_tables(page);resolver.context={};resolver.calls={};pitch_counts={}
 for side,team in [('away',g['away_team']),('home',g['home_team'])]:
  _,table=base.find_table(tables,'tbl'+side.title()+'Hitter1')
  _,stats=base.find_table(tables,'tbl'+side.title()+'Hitter3')
  statrows=[base.row_values(r) for r in stats['rows'] if len(base.row_values(r))>=5 and base.row_values(r)[0].isdigit()]
  lineupindex=0
  for row in table['rows']:
   vals=base.row_values(row)
   if len(vals)>=3 and vals[0].isdigit():
    resolver.context.setdefault((team,'batter',vals[2]),[]).append({'raw':str(row),'stats':[int(v) for v in statrows[lineupindex][:4]],'avg':statrows[lineupindex][4]})
    lineupindex+=1
 pitcher_tables=[]
 for table in tables:
  vals=[base.row_values(r) for r in table['rows']]
  if vals and vals[0] and vals[0][0]=='선수명' and '투구수' in vals[0]:pitcher_tables.append(table)
 complete_pitchers=False
 if len(pitcher_tables)==2:
  try:parsed=base.parse_pitchers(tables,(g['away_team'],g['home_team']));complete_pitchers=all(parsed.values())
  except ValueError as e:
   if str(e)!='official pitcher player name missing':raise
   parsed={}
  for team,table in zip((g['away_team'],g['home_team']),pitcher_tables):
   for row in table['rows'][1:]:
    v=base.row_values(row)
    if len(v)>=17 and v[0]!='TOTAL':
     resolver.context.setdefault((team,'pitcher',v[0]),[]).append({'raw':str(row),'pitch_stats':[int(v[7]),v[6],int(v[14]),int(v[15])],'pitch_details':[int(v[10]),int(v[11]),int(v[12]),int(v[13])]})
     pitch_counts[(team,v[0])]=int(v[8])
  for side,team,opponent in [('away',g['away_team'],g['home_team']),('home',g['home_team'],g['away_team'])]:
   events=base.ordered_events(base.parse_lineup_group(tables,side),g['game_id'],team,allow_missing=False)
   bf=sum(r['pitched'] for r in parsed.get(opponent,[]))
   if bf and len(events)!=bf:raise ValueError(f'{team}: visible PA {len(events)} != official BF {bf}')
 original_page=page;aliases=[];known_names={key[2] for key in resolver.context}
 notes=base.parse_note_rows(tables)
 note_names={name for label,text in notes.items() if label!='심판' for name in re.findall(r'([가-힣A-Za-z.·]+)(?:\d+호)*\d*\s*\(',text)}
 for name in note_names-known_names:
  ids=set(resolver.by_name.get(name,{}))
  if not ids:
   searchkey=('official-search',name)
   if searchkey not in resolver.memory:resolver.memory[searchkey]=resolver.search(name,int(g['game_date'][:4]))
   found=resolver.memory[searchkey];resolver.by_name[name].update(found);ids=set(found)
  matches=[(current,pid) for current in known_names for pid in ids & set(resolver.by_name.get(current,{}))]
  if len(matches)==1:
   current,pid=matches[0]
   page=re.sub(r'(?<![가-힣A-Za-z])'+re.escape(name)+r'(?=\d|\s*\()',current,page)
   aliases.append({'player_id':pid,'historical_name':name,'box_name':current,'evidence':'official old-name search plus stable player ID'})
 game=base.Game(**{k:g[k] for k in base.Game.__dataclass_fields__})
 result=base.parse_box(page,game,series,resolver);result['game']=g
 if result['source_issue'] and complete_pitchers:raise ValueError(result['source_issue'])
 for p in result['pitcher_rows']:p['pitched']=pitch_counts[(p['team'],p['player_name'])]
 rows=result['batter_rows']
 for alias in aliases:
  if alias['historical_name']==resolver.current_names.get(alias['player_id']):continue
  for r in rows+result['pitcher_rows']:
   if int(r['player_id'])==alias['player_id']:r['player_name']=alias['historical_name']
  for r in rows:
   if r['pitcher_id'] and int(r['pitcher_id'])==alias['player_id']:r['pitcher_name']=alias['historical_name']
 result['source_corrections']=aliases+user_corrections
 if game_settings(g['game_id']):result['user_corrections']=game_settings(g['game_id'])
 normalize_running(result,original_page)
 for r in rows:
  if not r['is_gs']:r['pos']='교'
 groups=defaultdict(list)
 for r in rows:
  if r['run_out']:groups[(r['team'],r['player_id'],r['inning'])].append(r)
 for key,original in groups.items():
  candidates=[r for r in rows if (r['team'],r['player_id'],r['inning'])==key and r['pa_result'] and base._reached_base(r['pa_result'])]
  if candidates:
   count=sum(r['run_out'] for r in original)
   for r in original:r['run_out']=0
   min(candidates,key=lambda r:r['batting_index'])['run_out']=count
 for team in (g['away_team'],g['home_team']):
  indexes=[r['batting_index'] for r in rows if r['team']==team and r['pa_result']]
  if sorted(indexes)!=list(range(1,len(indexes)+1)):raise ValueError('non-continuous batting index')
 if any(not r['player_id'] for r in rows+result['pitcher_rows']):raise ValueError('missing player ID')
 return result

def normalize_running(result,page):
 """Preserve running events for non-PA runners and avoid namesake duplication."""
 rows=result['batter_rows'];g=result['game'];notes=base.parse_note_rows(base.parse_tables(page))
 for field,label in [('sb','도루'),('cs','도루자')]:
  for r in rows:r[field]=0
  for (name,inning),count in base.parse_running(notes,label).items():
   named=[r for r in rows if r['player_name']==name]
   identities={(r['team'],r['player_id']) for r in named}
   forced=[v for v in game_settings(g['game_id']).get('running',[]) if v['label']==label and v['name']==name and v['inning']==inning]
   if forced:
    if len(forced)!=1:raise ValueError('duplicate running override')
    identities={identity for identity in identities if int(identity[1])==forced[0]['player_id']}
   if len(identities)>1:
    reached={(r['team'],r['player_id']) for r in named if r['inning']==inning and r['pa_result'] and base._reached_base(r['pa_result'])}
    if len(reached)==1:identities=reached
   if len(identities)>1 and field=='sb':
    evidenced=set();year=int(g['game_date'][:4]);day=g['game_date'][5:7]+'.'+g['game_date'][8:10]
    for team,pid in identities:
     path=ROOT/str(year)/'player-daily'/f'Hitter-{pid}-{year}.html.gz'
     if not path.exists():continue
     daily=base.parse_tables(gzip.open(path,'rt',encoding='utf-8').read())
     values=[base.row_values(row) for table in daily for row in table['rows']]
     matches=[v for v in values if len(v)>10 and v[0]==day]
     if len(matches)==1 and int(matches[0][10])>=count:evidenced.add((team,pid))
    if len(evidenced)==1:identities=evidenced
   if len(identities)!=1:raise LookupError(f'{g["game_id"]}: {label} {name}/{inning} candidates={sorted(identities)}')
   identity=next(iter(identities));player=[r for r in named if (r['team'],r['player_id'])==identity]
   candidates=[r for r in player if r['inning']==inning]
   if candidates:
    eligible=[r for r in candidates if r['pa_result'] and base._reached_base(r['pa_result'])] or candidates
    chosen=min(eligible,key=lambda r:r['batting_index'] or 100000)
   else:
    chosen=dict(player[0]);chosen.update(inning=inning,pa_result=None,batting_index=None,sb=0,cs=0,run_out=0,rbi=0,r=0,is_gwrbi=0,pitcher_id=None,pitcher_name=None)
    # Reuse an existing non-PA row when it carries no inning event.
    blank=next((r for r in player if r['pa_result'] is None and r['inning'] is None),None)
    if blank:blank['inning']=inning;chosen=blank
    else:rows.append(chosen)
   chosen[field]+=count

def dry(start,end,game_id=None):
 from backfill_kbo_early_official import connect
 con=connect();resolver=Resolver(con)
 try:
  for year in range(start,end+1):
   folder=ROOT/str(year);games=json.loads((folder/'games.json').read_text());failures=[];totals=defaultdict(int)
   for number,g in enumerate(games,1):
    if game_id and g['game_id']!=game_id:continue
    try:
     plan_path=folder/'plans'/f"{g['game_id']}.json"
     if plan_path.exists() and not game_id:
      result=json.loads(plan_path.read_text())
      totals['games']+=1;totals['batter_rows']+=len(result['batter_rows']);totals['pitcher_rows']+=len(result['pitcher_rows']);totals['run_out']+=sum(r['run_out'] for r in result['batter_rows'])
      continue
     decision=json.loads((folder/f"{g['game_id']}-source.json").read_text())
     if not decision['valid_box']:raise ValueError('official box lacks batter/scoreboard data')
     page=gzip.open(folder/decision['path'],'rt',encoding='utf-8').read()
     result=parse(g,page,decision['series'],resolver)
     atomic(folder/'plans'/f"{g['game_id']}.json",result)
     totals['games']+=1;totals['batter_rows']+=len(result['batter_rows']);totals['pitcher_rows']+=len(result['pitcher_rows']);totals['run_out']+=sum(r['run_out'] for r in result['batter_rows'])
    except Exception as e:
     failures.append({'game_id':g['game_id'],'error_type':type(e).__name__,'error':str(e)})
     atomic(folder/'dry-progress-failures.json',failures)
    if number%10==0:
     atomic(folder/'dry-progress.json',{'checked':number,'total':len(games),**totals,'failures':len(failures)})
     print(year,'dry',number,'/',len(games),dict(totals),'failures',len(failures),flush=True)
   name='single-'+game_id if game_id else 'dry'
   atomic(folder/(name+'-report.json'),{'year':year,**totals,'failures':failures})
   print(year,'dry complete',dict(totals),'failures',len(failures),flush=True)
 finally:con.close()

if __name__=='__main__':
 import argparse
 p=argparse.ArgumentParser();p.add_argument('--start-year',type=int,default=2010);p.add_argument('--end-year',type=int,default=2021);p.add_argument('--game-id');a=p.parse_args()
 if not 2010<=a.start_year<=a.end_year<=2021:raise ValueError('scope')
 dry(a.start_year,a.end_year,a.game_id)
