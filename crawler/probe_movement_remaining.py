import json
from collect_player_movements import ROOT
from plan_player_movement_numbers import profile
n=json.loads((ROOT/'number-plan.json').read_text());r=json.loads((ROOT/'rename-plan.json').read_text())
for event in n['unresolved_number_identities']+r['unresolved']:
 print({k:v for k,v in event.items() if k!='raw'})
 for pid in event['candidate_player_ids']:
  if pid<10000:continue
  try:print(profile(pid))
  except Exception as e:print(pid,str(e))
print('rename notes',[(e['player_text'],e['note']) for e in json.loads((ROOT/'renames.json').read_text())])
