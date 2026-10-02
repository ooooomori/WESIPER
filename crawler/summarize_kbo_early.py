import json,gzip
from collections import Counter
from backfill_kbo_early_official import ROOT
for year in range(2001,2008):
 plans=[json.loads(p.read_text()) for p in (ROOT/str(year)/'plans').glob('*.json')]
 if not plans:continue
 print(year,len(plans),sum(len(p['batter_rows']) for p in plans),sum(len(p['pitcher_rows']) for p in plans),sum(r['run_out'] for p in plans for r in p['batter_rows']),[p['game']['game_id'] for p in plans if p['pitcher_source_issue']])
 path=ROOT/str(year)/'dry-failures.json'
 if path.exists():
  failures=json.loads(path.read_text());print('failures',len(failures));print(json.dumps(failures,ensure_ascii=False)[:20000])
print('2001 notes',Counter(r[0] for p in (ROOT/'2001').glob('s0-*-GetBoxScoreScroll.json.gz') for r in []))
for p in (ROOT/'2001').glob('s0-*-GetBoxScoreScroll.json.gz'):
 with gzip.open(p,'rt') as f:d=json.load(f)
 if '주루사' in d.get('tableEtc',''):print('runout box',p.name);break
