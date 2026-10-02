"""Validate raw official listings and team splits without inventing missing fields."""
import gzip,json,re
from collections import Counter,defaultdict
from decimal import Decimal,ROUND_HALF_UP
from datetime import datetime,timezone
from collect_official_old_seasons import ROOT,SERIES,atomic
from old_season_totals_schema import MAPS
def outs(text):
 if text in ('','-','–'):return None
 m=re.fullmatch(r'(?:(\d+)\s+)?([12])/3',text)
 if m:return 3*int(m[1] or 0)+int(m[2])
 if re.fullmatch(r'\d+',text):return 3*int(text)
 m=re.fullmatch(r'(\d+)\.([012])',text)
 if m:return 3*int(m[1])+int(m[2])
 raise ValueError('unrecognized official innings '+text)
def typed(group,source):
 role=group['role'];raw=source['values'];known={'순위','선수명','팀명'}|set(MAPS[role]);unknown=set(raw)-known
 if unknown:raise ValueError('unmapped official columns '+str(unknown))
 row={k:group[k] for k in ('year','league_level','series_id','row_scope','filter_team_code')};row.update({k:source[k] for k in ('player_id','player_name','team_name','source_url','source_file','source_sha256')});row['source_rank']=raw.get('순위');row['raw_record']=json.dumps(raw,ensure_ascii=False);row['collected_at']=group['_collected_at']
 for header,(field,definition) in MAPS[role].items():
  value=raw.get(header)
  if value in (None,'','-','–'):row[field]=None
  elif field=='innings_text':row[field]=value
  elif definition.startswith('DECIMAL'):row[field]=str(Decimal(value))
  else:
   if not re.fullmatch(r'\d+',value):raise ValueError('invalid official integer '+header+': '+value)
   row[field]=int(value)
 if role=='Pitcher':row['innings_outs']=outs(row['innings_text'] or '')
 if not row['player_id'] or not row['player_name']:raise ValueError('unlinked/unnamed player')
 if role=='Hitter' and all(row[v] is not None for v in ('h','doubles','triples','hr','ab')):
  if row['doubles']+row['triples']+row['hr']>row['h'] or row['h']>row['ab']:raise ValueError('invalid hit component totals')
  if row['ab'] and row['avg'] is not None:
   expected=(Decimal(row['h'])/row['ab']).quantize(Decimal('.001'),rounding=ROUND_HALF_UP)
   if abs(expected-Decimal(row['avg']))>Decimal('.001'):raise ValueError('official AVG inconsistent with hits/AB')
 if role=='Pitcher' and row['r'] is not None and row['er'] is not None and row['er']>row['r']:raise ValueError('ER exceeds R')
 return row
def run():
 collection=json.loads((ROOT/'collection-summary.json').read_text())
 if collection['failures']:raise ValueError('collection failures remain')
 files=sorted(ROOT.glob('*/*/s*/*/manifest.json'));failures=[];counts=Counter();groups=[];bykey={};plans={}
 for path in files:
  group=json.loads(path.read_text());group['_collected_at']=datetime.fromtimestamp(path.stat().st_mtime,timezone.utc).strftime('%Y-%m-%d %H:%M:%S');rows=[]
  try:
   for source in group['records']:rows.append(typed(group,source))
   keys=[(r['player_id'],r['row_scope'],r['filter_team_code']) for r in rows]
   if len(set(keys))!=len(keys):raise ValueError('duplicate player key within group')
   for row in rows:
    key=(group['year'],group['role'],group['series_id'],row['player_id']);bykey.setdefault(key,{'total':[],'team':[]})[row['row_scope']].append(row)
   relative=str(path.relative_to(ROOT));plan=path.with_name('plan.json');atomic(plan,{'manifest':relative,'role':group['role'],'rows':rows});plans[relative]=str(plan.relative_to(ROOT));groups.append({'year':group['year'],'role':group['role'],'series_id':group['series_id'],'row_scope':group['row_scope'],'filter_team_code':group['filter_team_code'],'rows':len(rows),'coverage_status':group.get('coverage_status','available' if rows else 'empty_official_result')});counts[(group['role'],group['row_scope'])]+=len(rows)
  except Exception as e:failures.append({'manifest':str(path.relative_to(ROOT)),'error':str(e)})
 split_discrepancies=[]
 for key,sets in bykey.items():
  if len(sets['total'])!=1:failures.append({'key':key,'error':'not exactly one unfiltered season total'});continue
  total=sets['total'][0];team=sets['team']
  if not team:failures.append({'key':key,'error':'season total has no team-filter record'});continue
  for field,value in total.items():
   if field not in ('innings_outs',*[v[0] for v in MAPS[key[1]].values() if v[1].startswith('INT')]):continue
   if value is None or any(r[field] is None for r in team):continue
   if sum(r[field] for r in team)!=value:split_discrepancies.append({'key':key,'field':field,'total':value,'team_sum':sum(r[field] for r in team)})
 report={'groups':groups,'plans':plans,'failures':failures,'team_split_discrepancies':split_discrepancies,'counts':{role:{scope:counts[(role,scope)] for scope in ('total','team')} for role in ('Hitter','Pitcher')},'official_columns':{role:list(MAPS[role]) for role in MAPS}}
 atomic(ROOT/'validation-report.json',report);print(json.dumps({'counts':report['counts'],'failures':failures,'team_split_discrepancies':split_discrepancies[:20],'split_discrepancy_count':len(split_discrepancies)},ensure_ascii=False),flush=True)
if __name__=='__main__':run()
