import re,gzip,html,json
from backfill_kbo_early_official import ROOT
for issue in json.loads((ROOT/'cached-plan-audit.json').read_text())['bf_mismatches']:
 gid=issue['game_id']
 with gzip.open(ROOT/gid[:4]/f'{gid}-official-box-html.html.gz','rt') as f:page=f.read()
 print(gid,flush=True)
 for ti,t in enumerate(re.findall(r'<table\b[^>]*>(.*?)</table>',page,re.S)):
  trs=[[ ' '.join(html.unescape(re.sub('<[^>]+>',' ',c)).split()) for c in re.findall(r'<t[dh]\b[^>]*>(.*?)</t[dh]>',tr,re.S)] for tr in re.findall(r'<tr\b[^>]*>(.*?)</tr>',t,re.S)]
  if any(any(n in r for n in ['박종호','양용모','김종국','김수연','박경수','홍현우','최경환']) for r in trs):
   print('TABLE',ti,'count',len(trs),flush=True)
   for i,r in enumerate(trs):
    if any(n in r for n in ['박종호','양용모','김종국','김수연','박경수','홍현우','최경환']):print('ROW',i,r,flush=True)
  elif ti in [2,3,5,6,8,9]:print('TABLE',ti,trs,flush=True)
