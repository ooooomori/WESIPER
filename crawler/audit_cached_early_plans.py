import gzip,json
from pathlib import Path
from backfill_kbo_early_official import ROOT,atomic,validate_plan
failures=[];summaries={}
for year in range(2001,2008):
 total={'games':0,'batter_rows':0,'pitcher_rows':0,'run_out':0,'pitcher_missing_games':[]}
 for path in (ROOT/str(year)/'plans').glob('*.json'):
  p=json.loads(path.read_text());g=p['game'];validate_plan(p)
  total['games']+=1;total['batter_rows']+=len(p['batter_rows']);total['pitcher_rows']+=len(p['pitcher_rows']);total['run_out']+=sum(r['run_out'] for r in p['batter_rows'])
  if p['pitcher_source_issue']:total['pitcher_missing_games'].append(g['game_id']);continue
  with gzip.open(ROOT/str(year)/f"s{g['series']}-{g['request_id']}-GetBoxScoreScroll.json.gz",'rt') as z:box=json.load(z)
  for side,team in ((0,g['away_team']),(1,g['home_team'])):
   faced=sum(int(r['row'][7]['Text']) for r in json.loads(box['arrPitcher'][1-side]['table'])['rows'])
   pa=sum(1 for r in p['batter_rows'] if r['team']==team and r['pa_result'] is not None)
   if faced!=pa:failures.append({'game_id':g['game_id'],'team':team,'pa':pa,'opponent_bf':faced})
 print(year,total,flush=True);summaries[year]=total
atomic(ROOT/'cached-plan-audit.json',{'summaries':summaries,'bf_mismatches':failures})
print('BF MISMATCHES',failures,flush=True)
