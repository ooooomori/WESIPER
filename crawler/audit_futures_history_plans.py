"""Check cached plans for running-event omissions without repeating PA dry-runs."""
import gzip,json
from backfill_futures_history import ROOT,atomic
from futures_history_parser import normalize_running
from write_futures_history import validate
for year in range(2010,2022):
 folder=ROOT/str(year);report_path=folder/'dry-report.json'
 if not report_path.exists():continue
 report=json.loads(report_path.read_text());blocked={r['game_id'] for r in report['failures']};totals={'games':0,'batter_rows':0,'pitcher_rows':0,'run_out':0}
 for path in sorted((folder/'plans').glob('*.json')):
  if path.stem in blocked:continue
  try:
   item=json.loads(path.read_text());decision=json.loads((folder/(path.stem+'-source.json')).read_text());page=gzip.open(folder/decision['path'],'rt',encoding='utf-8').read()
   normalize_running(item,page);validate(item);atomic(path,item)
   totals['games']+=1;totals['batter_rows']+=len(item['batter_rows']);totals['pitcher_rows']+=len(item['pitcher_rows']);totals['run_out']+=sum(r['run_out'] for r in item['batter_rows'])
  except Exception as e:report['failures'].append({'game_id':path.stem,'error_type':type(e).__name__,'error':str(e)})
 report.update(totals);report['running_events_audited']=True;atomic(report_path,report)
 print(year,totals,'failures',len(report['failures']),flush=True)
