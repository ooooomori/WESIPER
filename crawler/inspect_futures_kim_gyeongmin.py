import gzip,json,traceback
from backfill_futures_history import ROOT
from backfill_kbo_early_official import connect
from futures_history_parser import Resolver,parse
from recheck_futures_history_failures import api_page
gid='20200506KTHH0';folder=ROOT/'2020';g=next(g for g in json.loads((folder/'games.json').read_text()) if g['game_id']==gid);d=json.loads((folder/(gid+'-source.json')).read_text());page=gzip.open(folder/d['path'],'rt',encoding='utf-8').read();c=connect();resolver=Resolver(c)
try:
 for label,raw in [('HTML',page),('API',api_page(g,page))]:
  try:
   item=parse(g,raw,d['series'],resolver);print(label,'PASS',[(r['player_id'],r['player_name'],r['inning'],r['pa_result']) for r in item['batter_rows'] if r['is_gwrbi']],flush=True)
  except Exception as e:print(label,type(e).__name__,str(e),flush=True)
 print('NAME_CATALOG',resolver.by_name.get('김범'),flush=True)
 pid=resolver.resolve(gid,'김범','KT',2020,'batter','좌',opponent='한화');print('RESOLVED_API_ID',pid,flush=True)
 print('OFFICIAL_DAILY',[r for r in resolver.daily(pid,2020,'batter') if r[0]=='05.06'],flush=True)
 with c.cursor() as q:
  q.execute('SELECT player_id,name,oldname,fullname FROM kbo_player_data WHERE player_id=%s',(pid,));print('PROFILE',q.fetchall(),flush=True)
finally:c.close()
