import gzip,json
from backfill_futures_history import ROOT
for gid in ('20100513HHWO0','20100608SKSS0','20100819LGSK0','20100901LGWO0'):
 p=ROOT/'2010'/(gid+'-GetBoxScoreScroll.json.gz');data=json.load(gzip.open(p,'rt',encoding='utf-8'))
 print(gid,'CODE',data.get('code'))
 for side,h in enumerate(data.get('arrHitter',[])):
  def dec(v):return json.loads(v) if isinstance(v,str) else v
  lineup=dec(h['table1'])['rows'];results=dec(h['table2'])['rows'];stats=dec(h['table3'])['rows']
  for a,b,c in zip(lineup,results,stats):
   print(side,[v['Text'] for v in a['row']],[v['Text'] for v in b['row']],[v['Text'] for v in c['row']])
