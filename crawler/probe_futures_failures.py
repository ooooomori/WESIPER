import json,gzip
from backfill_futures_history import ROOT,cached,atomic
import kbo_futures_crawl as base
for year in (2010,2011):
 folder=ROOT/str(year);failures=json.loads((folder/'dry-report.json').read_text())['failures'];games={g['game_id']:g for g in json.loads((folder/'games.json').read_text())}
 for fail in failures:
  g=games[fail['game_id']];decision=json.loads((folder/(g['game_id']+'-source.json')).read_text());page=gzip.open(folder/decision['path'],'rt',encoding='utf-8').read();tables=base.parse_tables(page)
  print('GAME',g['game_id'],fail['error'],base.parse_note_rows(tables),flush=True)
  for side,team in [('away',g['away_team']),('home',g['home_team'])]:
   for b in base.parse_lineup_group(tables,side):
    if b['name'] in ('김준호','이학준'):print(team,b,flush=True)
  if fail['error_type']!='LookupError':
   for method in ('GetScoreBoardScroll','GetBoxScoreScroll'):
    text=cached('https://www.koreabaseball.com/ws/Schedule.asmx/'+method,folder/(g['game_id']+'-'+method+'.json.gz'),data={'leId':2,'srId':0,'seasonId':year,'gameId':g['request_id']})
    p=json.loads(text);print(method,'keys',list(p) if isinstance(p,dict) else str(type(p)),flush=True)
