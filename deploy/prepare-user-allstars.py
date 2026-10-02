"""Resolve the user's 2024-2026 roster without changing existing career PK order."""
import json,re
from collections import Counter
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
DATA=ROOT/'data/player-career'
players=json.loads((ROOT/'.player-career/catalog.json').read_text(encoding='utf-8-sig'))['players']
reviewed={(2024,'KT','김민'):68043,(2024,'KT','로하스'):67025}
aliases={'성양탁':'성영탁'}
rows=[];seen=set();duplicates=[]
for line in (DATA/'allstar-user-2024-2026.txt').read_text(encoding='utf-8').splitlines():
 if not line:continue
 if re.fullmatch(r'\d{4}년',line):year=int(line[:-1]);continue
 team,*names=line.split()
 for token in names:
  name,explicit=re.fullmatch(r'([^()]+)(?:\((\d+)\))?',token).groups()
  name=aliases.get(name,name)
  pid=int(explicit) if explicit else reviewed.get((year,team,name))
  candidates=[p for p in players if p['player_id']==pid] if pid else [p for p in players if p['name']==name]
  if len(candidates)>1:candidates=[p for p in candidates if p['team']==team]
  assert len(candidates)==1,(year,team,token,candidates)
  player=candidates[0];assert player['name']==name,(token,player)
  key=(player['player_id'],year)
  if key in seen:
   assert next(r['team'] for r in rows if (r['player_id'],r['year'])==key)==team
   duplicates.append((year,team,name));continue
  seen.add(key)
  rows.append(dict(player_id=player['player_id'],name=name,input_name=token,team=team,year=year))
(DATA/'allstar-user-2024-2026.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('Unique user occurrences',len(rows),dict(Counter(r['year'] for r in rows)))
print('Duplicate input skipped',duplicates)
