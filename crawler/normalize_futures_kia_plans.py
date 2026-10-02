import json
from backfill_futures_history import ROOT,atomic
changed=[]
for year in range(2010,2022):
 folder=ROOT/str(year);receipt=folder/'write-receipt.json';committed=set(json.loads(receipt.read_text())['committed_game_ids']) if receipt.exists() else set()
 for path in (folder/'plans').glob('*.json'):
  item=json.loads(path.read_text());g=item['game']
  if '기아' not in (g['away_team'],g['home_team']):continue
  if path.stem in committed:raise ValueError(f'{path.stem}: committed spelling requires separate reconciliation')
  for field,code in [('away_team',g['request_id'][8:10]),('home_team',g['request_id'][10:12])]:
   if g[field]=='기아':
    if code!='HT':raise ValueError('official spelling/team code mismatch')
    g[field]='KIA'
  for r in item['batter_rows']+item['pitcher_rows']:
   if r['team']=='기아':r['team']='KIA'
  atomic(path,item);changed.append(path.stem)
atomic(ROOT/'team-spelling-normalization.json',{'games':changed,'rule':'official 기아 spelling and HT code normalized to existing KIA convention'})
print('KIA spelling plans normalized',len(changed),flush=True)
