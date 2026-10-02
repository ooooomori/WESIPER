import gzip,json
from backfill_futures_history import ROOT
from recheck_futures_history_failures import api_page
import kbo_futures_crawl as b
for gid in ('20100819LGSK0','20150723OBLG0','20160409PLSK0','20200506KTHH0','20210925OBWO0'):
 folder=ROOT/gid[:4];g=next(g for g in json.loads((folder/'games.json').read_text()) if g['game_id']==gid);d=json.loads((folder/(gid+'-source.json')).read_text());page=gzip.open(folder/d['path'],'rt',encoding='utf-8').read()
 print('GAME',g,flush=True)
 for label,raw in [('HTML',page),('API',api_page(g,page))]:
  tables=b.parse_tables(raw);_,score=b.find_table(tables,'tblScordboard2');print(label,'SCORE',[b.row_values(r) for r in score['rows']],flush=True)
  print(label,'WINNING',b.parse_note_rows(tables).get('결승타'),flush=True)
  for side in ('away','home'):
   for row in b.parse_lineup_group(tables,side):
    if row['name'] in ('손인호','이학준','이시찬','김경민','김준태','양원혁','이경록','장준원','오명진') or (gid=='20200506KTHH0' and row['innings'][5] and '중안' in row['innings'][5]):print(label,side,row,flush=True)
