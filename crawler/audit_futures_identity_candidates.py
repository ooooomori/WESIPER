import json
from backfill_futures_history import ROOT,atomic
from backfill_kbo_early_official import connect
from kbo_early_parser import Resolver
con=connect();catalog=Resolver(con,ROOT);con.close();changed={}
for p in json.loads((ROOT/'existing-game-name-aliases.json').read_text()):
 old=set(catalog.by_name.get(p['name'],{}))
 if p['player_id'] not in old:changed.setdefault(p['name'],{'catalog_ids':sorted(old),'additional_ids':set()})['additional_ids'].add(p['player_id'])
for item in changed.values():item['additional_ids']=sorted(item['additional_ids'])
affected=[]
for year in range(2010,2022):
 for path in (ROOT/str(year)/'plans').glob('*.json'):
  item=json.loads(path.read_text())
  names={r['player_name'] for r in item['batter_rows']+item['pitcher_rows']}
  risky={n for n in names & changed.keys() if len(changed[n]['catalog_ids'])==1}
  if risky:affected.append({'year':year,'game_id':path.stem,'names':sorted(risky)})
atomic(ROOT/'additional-identity-candidates.json',{'changed_names':changed,'previously_unique_affected_games':affected})
print(json.dumps({'changed_names':changed,'affected_games':len(affected)},ensure_ascii=False),flush=True)
