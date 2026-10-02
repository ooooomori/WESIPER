import json
from backfill_kbo_early_official import ROOT,connect,dry
from kbo_early_parser import Resolver
c=connect();r=Resolver(c,ROOT)
try:
 for v in json.loads((ROOT/'cached-plan-audit.json').read_text())['bf_mismatches']:
  gid=v['game_id'];passed,failed=dry(int(gid[:4]),r,gid)
  print(gid,'passed',len(passed),'failures',len(failed),flush=True)
finally:c.close()
