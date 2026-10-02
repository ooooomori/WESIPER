"""Target only games whose previously unique names acquired another candidate."""
import gzip,json
from backfill_futures_history import ROOT,atomic
from futures_history_parser import Resolver,parse
from recheck_futures_history_failures import api_page
from backfill_kbo_early_official import connect,BATTER_FIELDS,PITCH_FIELDS
con=connect();resolver=Resolver(con);entries=json.loads((ROOT/'additional-identity-candidates.json').read_text())['previously_unique_affected_games'];report={'checked':0,'changed_games':[],'failures':[]}
import sys
if '--changed-only' in sys.argv:entries=json.loads((ROOT/'changed-identity-audit.json').read_text())['changed_games']
try:
 for entry in entries:
  folder=ROOT/str(entry['year']);gid=entry['game_id'];old=json.loads((folder/'plans'/(gid+'.json')).read_text());decision=json.loads((folder/(gid+'-source.json')).read_text());page=gzip.open(folder/decision['path'],'rt',encoding='utf-8').read()
  try:
   try:new=parse(old['game'],page,decision['series'],resolver)
   except Exception:new=parse(old['game'],api_page(old['game'],page),decision['series'],resolver)
   def values(item):return ([[r[k] for k in BATTER_FIELDS] for r in item['batter_rows']],[[r[k] for k in PITCH_FIELDS] for r in item['pitcher_rows']])
   if values(old)!=values(new):
    atomic(folder/'identity-corrections'/(gid+'.json'),{'old_plan':old,'new_plan':new});report['changed_games'].append(entry)
  except Exception as e:report['failures'].append({**entry,'error_type':type(e).__name__,'error':str(e)})
  report['checked']+=1
  if report['checked']%25==0:atomic(ROOT/'changed-identity-audit.json',report);print('identity audit',report['checked'],'/',len(entries),'changed',len(report['changed_games']),'failures',len(report['failures']),flush=True)
 atomic(ROOT/'changed-identity-audit.json',report);print('identity audit complete',report['checked'],'changed',len(report['changed_games']),'failures',len(report['failures']),flush=True)
finally:con.close()
