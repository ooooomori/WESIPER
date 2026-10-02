"""Recheck failed games only, allowing an independently cached official API."""
import gzip,json
from backfill_futures_history import ROOT,cached,atomic
from futures_history_parser import Resolver,parse
from backfill_kbo_early_official import connect
import kbo_futures_crawl as base
def api_page(g,page):
 folder=ROOT/g['game_date'][:4]
 decision=json.loads((folder/(g['game_id']+'-source.json')).read_text());series=decision['series'];prefix=g['game_id']+(f'-s{series}' if series!=0 else '')
 box=json.loads(cached('https://www.koreabaseball.com/ws/Schedule.asmx/GetBoxScoreScroll',folder/(prefix+'-GetBoxScoreScroll.json.gz'),data={'leId':2,'srId':series,'seasonId':g['game_date'][:4],'gameId':g['request_id']}))
 score=json.loads(cached('https://www.koreabaseball.com/ws/Schedule.asmx/GetScoreBoardScroll',folder/(prefix+'-GetScoreBoardScroll.json.gz'),data={'leId':2,'srId':series,'seasonId':g['game_date'][:4],'gameId':g['request_id']}))
 if str(box.get('code'))!='100' or str(score.get('LE_ID'))!='2' or score.get('G_ID')!=g['request_id'] or score.get('G_DT')!=g['game_date']:raise ValueError('official Futures API identity mismatch')
 if len(box.get('arrHitter',[]))!=2:raise ValueError('official Futures API no hitter tables')
 tables=base.parse_tables(page)
 def decoded(v):return json.loads(v) if isinstance(v,str) else v
 def body(table):
  table=decoded(table);groups=table.get('headers',[])+table.get('rows',[])
  return ''.join('<tr>'+''.join('<td>'+str(c.get('Text') or '')+'</td>' for c in r['row'])+'</tr>' for r in groups)
 for side,hitter in zip(('away','home'),box['arrHitter']):
  prefix='tbl'+side.title()+'Hitter';idx,lineup=base.find_table(tables,prefix+'1');_,stats=base.find_table(tables,prefix+'3')
  for old,new in [(lineup,hitter['table1']),(tables[idx+1],hitter['table2']),(stats,hitter['table3'])]:page=page.replace(old['body'],body(new),1)
 return page

def run(year):
 folder=ROOT/str(year);report=json.loads((folder/'dry-report.json').read_text());games={g['game_id']:g for g in json.loads((folder/'games.json').read_text())};con=connect();resolver=Resolver(con);remaining=[]
 try:
  for failure in report['failures']:
   g=games[failure['game_id']];decision=json.loads((folder/(g['game_id']+'-source.json')).read_text());page=gzip.open(folder/decision['path'],'rt',encoding='utf-8').read()
   try:
    try:result=parse(g,page,decision['series'],resolver)
    except Exception:result=parse(g,api_page(g,page),decision['series'],resolver);result['supplemental_source']='official GetBoxScoreScroll'
    atomic(folder/'plans'/(g['game_id']+'.json'),result);print(g['game_id'],'single recheck PASS',flush=True)
   except Exception as e:
    remaining.append({'game_id':g['game_id'],'error_type':type(e).__name__,'error':str(e)});print(g['game_id'],'single recheck FAIL',str(e),flush=True)
   atomic(folder/'recheck-progress.json',{'remaining_failures':remaining,'last_game_id':g['game_id']})
  report['failures']=remaining;report.update(games=0,batter_rows=0,pitcher_rows=0,run_out=0)
  blocked={v['game_id'] for v in remaining}
  for p in (folder/'plans').glob('*.json'):
   if p.stem in blocked:continue
   item=json.loads(p.read_text());report['games']+=1;report['batter_rows']+=len(item['batter_rows']);report['pitcher_rows']+=len(item['pitcher_rows']);report['run_out']+=sum(r['run_out'] for r in item['batter_rows'])
  atomic(folder/'dry-report.json',report);print(year,'rechecked failures',len(remaining),flush=True)
 finally:con.close()
if __name__=='__main__':
 import argparse
 p=argparse.ArgumentParser();p.add_argument('--year',type=int);p.add_argument('--all-failed',action='store_true');a=p.parse_args()
 if a.all_failed:
  for year in range(2010,2022):
   path=ROOT/str(year)/'dry-report.json'
   if path.exists() and json.loads(path.read_text())['failures']:run(year)
 elif a.year and 2010<=a.year<=2021:run(a.year)
 else:raise ValueError('scope')
