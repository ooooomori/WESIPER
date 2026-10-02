import json
from backfill_kbo_early_official import ROOT,atomic,validate_plan
report={'status':'blocked_before_commit','production_committed_games':0,'scope':'league_level=1; 2001-2007','years':{},'source_limitations':['Official schedule returns no historical exhibition/postseason/all-star rows for requested series. Discovery completeness is unverified.','2001 and 2002 official box notes contain no run-out category. Zero is an observed note total, not verified actual run-outs.','Seven games have a missing official PA cell despite an extra opposing batter faced; HTML confirms the same gap.','Five ambiguous run-outs assigned to the first reaching PA by explicit user instruction.'],'unresolved':[]}
for year in range(2001,2008):
 folder=ROOT/str(year);games=json.loads((folder/'games.json').read_text());fail=json.loads((folder/'dry-failures.json').read_text());bad={f['game']['game_id'] for f in fail}
 total={'cached_games':len(games),'validated_games':0,'batter_rows':0,'pitcher_rows':0,'run_out_note_total':0,'pitcher_missing_games':[],'failures':len(fail)}
 for g in games:
  if g['game_id'] in bad:continue
  item=json.loads((folder/'plans'/f"{g['game_id']}.json").read_text());validate_plan(item)
  total['validated_games']+=1;total['batter_rows']+=len(item['batter_rows']);total['pitcher_rows']+=len(item['pitcher_rows']);total['run_out_note_total']+=sum(b['run_out'] for b in item['batter_rows'])
  if item['pitcher_source_issue']:total['pitcher_missing_games'].append(g['game_id'])
 report['years'][str(year)]=total
 report['unresolved'] += fail
 print(year,total,flush=True)
atomic(ROOT/'checkpoint-report.json',report)
print('unresolved',[(f['game']['game_id'],f['error']) for f in report['unresolved']],flush=True)

