import json
from collections import Counter
from backfill_futures_history import ROOT,atomic
out={'years':{},'failures':[],'unlinked_schedule':[]};categories=Counter()
for year in range(2010,2022):
 folder=ROOT/str(year);report=json.loads((folder/'dry-report.json').read_text());out['years'][year]={k:v for k,v in report.items() if k!='failures'}
 for failure in report['failures']:
  failure={**failure,'year':year};out['failures'].append(failure)
  category='missing_pa' if 'visible PA' in failure['error'] else 'winning_hit' if 'winning PA' in failure['error'] else 'running_identity' if any(t in failure['error'] for t in ('주루사','도루자','도루 ')) else 'player_identity' if failure['error_type']=='LookupError' else 'source_issue'
  failure['category']=category;categories[category]+=1
 path=folder/'unlinked-schedule.json'
 if path.exists():out['unlinked_schedule'].extend(json.loads(path.read_text()))
out['categories']=dict(categories);atomic(ROOT/'unresolved-report.json',out)
print(json.dumps({'categories':dict(categories),'unresolved_games':len(out['failures']),'failure_list':out['failures']},ensure_ascii=False),flush=True)
