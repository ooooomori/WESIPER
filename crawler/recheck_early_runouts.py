from backfill_kbo_early_official import ROOT,connect,dry
from kbo_early_parser import Resolver
ids=['20030802SKHH0','20040425HDOB0','20040519HDLT0','20050813LGHT0','20070629LGHT0']
c=connect();r=Resolver(c,ROOT)
try:
 for gid in ids:
  passed,failed=dry(int(gid[:4]),r,gid)
  print(gid,'passed',len(passed),'remaining year failures',len(failed),flush=True)
  if passed:print('assignment',passed[0]['source_corrections'],flush=True)
finally:c.close()
