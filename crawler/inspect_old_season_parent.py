import gzip,json,re
from collect_official_old_seasons import ROOT
from backfill_kbo_early_official import connect
for path in ROOT.glob('*/*/s*/ALL/manifest.json'):
 g=json.loads(path.read_text())
 for r in g['records']:
  if r['player_id']==20001:print('SEASON',g['year'],g['role'],g['series_id'],r['player_name'],r['team_name'],r['values'],flush=True)
for path in ROOT.glob('*/player-profiles/20001-*.html.gz'):
 text=re.sub(r'\s+',' ',re.sub('<[^>]+>',' ',gzip.open(path,'rt',encoding='utf-8').read()))
 start=text.find('선수명:');print('PROFILE',path.name,text[start:start+550],flush=True)
c=connect()
try:
 with c.cursor() as q:
  q.execute("SHOW COLUMNS FROM kbo_player_data WHERE Field IN ('pos','mainPos','name','team','birth')");print('SCHEMA',q.fetchall(),flush=True)
finally:c.close()
