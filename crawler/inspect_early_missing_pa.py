import gzip,json,re
from backfill_kbo_early_official import ROOT
from kbo_early_parser import rows,clean
from kbo_candle_crawl import _ordered_plate_appearances
audit=json.loads((ROOT/'cached-plan-audit.json').read_text())
for issue in audit['bf_mismatches']:
 gid=issue['game_id'];year=gid[:4]
 plan=json.loads((ROOT/year/'plans'/f'{gid}.json').read_text());g=plan['game']
 with gzip.open(ROOT/year/f's0-{gid}-GetBoxScoreScroll.json.gz','rt') as f:d=json.load(f)
 print(gid,issue,rows(d['tableEtc']),flush=True)
 side=0 if issue['team']==g['away_team'] else 1
 item=d['arrHitter'][side];bats=[]
 for l,results,stat in zip(rows(item['table1']),rows(item['table2']),rows(item['table3'])):
  b={'name':l[2],'batOrder':int(l[0]),'stat':stat}
  for inn,v in enumerate(results,1):b[f'inn{inn}']=v
  bats.append(b)
 events,_=_ordered_plate_appearances(bats,gid,issue['team'])
 for event in events:
  if event.get('missing'):
   print('MISSING SLOT',event,flush=True)
   print('CANDIDATES',[(b['name'],b['stat'],[(inn,b.get(f'inn{inn}')) for inn in range(1,13) if b.get(f'inn{inn}')]) for b in bats if b['batOrder']==event['order']],flush=True)
