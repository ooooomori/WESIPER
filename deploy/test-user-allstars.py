"""Read-only public API checks for every user-supplied All-Star occurrence."""
import json,urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent;DATA=ROOT/'data/player-career'
people=json.loads((DATA/'allstar-user-2024-2026.json').read_text(encoding='utf-8'))
careers=json.loads((DATA/'careers.json').read_text(encoding='utf-8'))
ids=sorted({p['player_id'] for p in people})
def profile(pid):
 with urllib.request.urlopen(f'https://wesiper.xyz/api/playerProfile.php?pid={pid}',timeout=45) as r:
  assert r.status==200;data=json.load(r)
 assert int(data['player']['PlayerId'])==pid
 for row in data['career']:
  for k in ['year','month']:
   if row[k] is not None:row[k]=int(row[k])
 expected=[{k:v for k,v in row.items() if k!='player_id'} for row in careers if row['player_id']==pid]
 canonical=lambda rows:sorted(json.dumps(row,ensure_ascii=False,sort_keys=True) for row in rows)
 assert canonical(data['career'])==canonical(expected),pid
 return pid,data
with ThreadPoolExecutor(max_workers=4) as pool:profiles=dict(pool.map(profile,ids))
for person in people:
 rows=[r for r in profiles[person['player_id']]['career'] if r['category']=='award' and r['type']=='올스타' and r['year']==person['year']]
 assert len(rows)==1 and rows[0]['team']==person['team'],person
 assert rows[0]['month'] is None and rows[0]['pos'] is None,person
assert int(profiles[56348]['player']['IsForeign'])==1
print(f'PASS all 160 roster occurrences and complete careers for {len(ids)} distinct player profiles; repeated years and team/ID distinctions preserved')
body=json.dumps({'keyword':'성영탁'}).encode()
req=urllib.request.Request('https://wesiper.xyz/api/kbobingo/search.php',data=body,headers={'Content-Type':'application/json'})
with urllib.request.urlopen(req,timeout=45) as r:result=json.load(r)
player=next(p for p in result['list'] if int(p['SporkId'])==54610)
assert player['Season']['kia']['as'] is True,player
print('PASS bingo All-Star team condition derives from added careers')
