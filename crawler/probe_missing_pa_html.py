import gzip,json,time,requests
import re,html
from backfill_kbo_early_official import ROOT,HEADERS
issues=json.loads((ROOT/'cached-plan-audit.json').read_text())['bf_mismatches']
for issue in issues:
 gid=issue['game_id'];year=gid[:4];p=ROOT/year/f'{gid}-official-box-html.html.gz'
 if not p.exists():
  r=requests.get('https://www.koreabaseball.com/Futures/Schedule/BoxScore.aspx',params={'leagueId':1,'seriesId':0,'seasonId':year,'gameId':gid},headers=HEADERS,timeout=40);r.encoding='utf-8';r.raise_for_status()
  with gzip.open(p,'wt',encoding='utf-8') as f:f.write(r.text)
  time.sleep(.3)
 with gzip.open(p,'rt',encoding='utf-8') as f:s=f.read()
 print(gid,flush=True)
 for tr in re.findall(r'<tr\b[^>]*>(.*?)</tr>',s,re.S):
  cells=[' '.join(html.unescape(re.sub('<[^>]+>',' ',c)).split()) for c in re.findall(r'<t[dh]\b[^>]*>(.*?)</t[dh]>',tr,re.S)]
  if any(n in cells for n in ['박종호','양용모','김종국','김수연','박경수','홍현우','최경환']):print(cells,flush=True)

