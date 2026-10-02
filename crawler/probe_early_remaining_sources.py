import requests,re,json,gzip
from backfill_kbo_early_official import ROOT,HEADERS,atomic
from kbo_early_parser import rows
for year in (2003,2004,2005,2007):
 for f in json.loads((ROOT/str(year)/'dry-failures.json').read_text()):
  g=f['game'];print(g['game_id'],f['error'],flush=True)
  if 'winning hitter' in f['error']:
   with gzip.open(ROOT/str(year)/f"s0-{g['request_id']}-GetBoxScoreScroll.json.gz",'rt') as z:d=json.load(z)
   print('notes',rows(d['tableEtc']))
   for group in d['arrHitter']:
    for l,v,stat in zip(rows(group['table1']),rows(group['table2']),rows(group['table3'])):
     if l[2]=='심정수':print('winning hitter cells',l,v,stat)
g='20030802SKHH0'
params={'leId':1,'srId':0,'leagueId':1,'seriesId':0,'seasonId':2003,'gameId':g,'gameDate':'20030802','inning':6,'half':2}
for path in ('Schedule/GameCenter/LiveText.aspx','Game/BoxScore.aspx','Futures/Schedule/BoxScore.aspx','Game/LiveText.aspx'):
 r=requests.get('https://www.koreabaseball.com/'+path,params=params,headers=HEADERS,timeout=30);r.encoding='utf-8'
 p=ROOT/'2003'/f'{g}-{path.replace("/","-")}.html.gz'
 with gzip.open(p,'wt',encoding='utf-8') as z:z.write(r.text)
 print(path,r.status_code,r.url,len(r.text),re.findall(r'<script[^>]*src=[\'"]([^\'"]+)',r.text)[-4:],flush=True)
 print([line.strip()[:450] for line in r.text.splitlines() if any(x in line for x in ('주루사','GetText','LiveText','조윤채','ajax'))][:15],flush=True)
