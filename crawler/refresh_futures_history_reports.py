import json
from backfill_futures_history import ROOT,atomic
from write_futures_history import validate
for year in range(2010,2022):
 folder=ROOT/str(year);path=folder/'dry-report.json'
 if not path.exists():continue
 report=json.loads(path.read_text());blocked={v['game_id'] for v in report['failures']};counts={'games':0,'batter_rows':0,'pitcher_rows':0,'run_out':0}
 for p in (folder/'plans').glob('*.json'):
  if p.stem in blocked:continue
  item=json.loads(p.read_text());validate(item);counts['games']+=1;counts['batter_rows']+=len(item['batter_rows']);counts['pitcher_rows']+=len(item['pitcher_rows']);counts['run_out']+=sum(r['run_out'] for r in item['batter_rows'])
 report.update(counts);atomic(path,report);print(year,counts,'unresolved',len(blocked),flush=True)
