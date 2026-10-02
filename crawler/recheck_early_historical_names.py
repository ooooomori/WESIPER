"""Reparse only plans whose official notes prove an unresolved historical alias."""
import json,re
from backfill_kbo_early_official import ROOT,connect,load_raw,dry,atomic
from kbo_early_parser import Resolver,rows
c=connect();resolver=Resolver(c,ROOT);targets=[];failed=[]
try:
 for year in range(2001,2008):
  blocked={v['game']['game_id'] for v in json.loads((ROOT/str(year)/'dry-failures.json').read_text())}
  for g in json.loads((ROOT/str(year)/'games.json').read_text()):
   if g['game_id'] in blocked:continue
   item=json.loads((ROOT/str(year)/'plans'/f"{g['game_id']}.json").read_text())
   names={}
   for b in item['batter_rows']:
    names.setdefault(int(b['player_id']),set()).add(b['player_name'])
    if b['pitcher_id'] is not None:names.setdefault(int(b['pitcher_id']),set()).add(b['pitcher_name'])
   positive=set(names)|{int(p['player_id']) for p in item['pitcher_rows']}
   evidence=[]
   for note in rows(load_raw(g,'GetBoxScoreScroll')['tableEtc']):
    if note[0]=='심판':continue
    for name in re.findall(r'([가-힣A-Za-z.·]+)(?:\d+호)*\d*\s*\(',note[1]):
     candidates=set(resolver.by_name.get(name,{}))&positive
     if len(candidates)==1:
      pid=next(iter(candidates))
      if any(existing!=name for existing in names.get(pid,set())):evidence.append({'name':name,'player_id':pid,'note':note[0]})
   if evidence:targets.append({'game_id':g['game_id'],'year':year,'evidence':evidence})
 print('historical aliases requiring single-game revalidation',len(targets),flush=True)
 for target in targets:
  passed,failures=dry(target['year'],resolver,target['game_id'])
  if not passed:failed.append(target)
 atomic(ROOT/'historical-name-recheck.json',{'targets':targets,'failed':failed})
 print('historical-name revalidation complete',len(targets)-len(failed),'failed',len(failed),flush=True)
finally:c.close()
if failed:raise SystemExit(1)
