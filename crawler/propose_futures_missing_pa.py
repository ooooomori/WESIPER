import gzip,json,re
from backfill_futures_history import ROOT,atomic
import kbo_futures_crawl as b
proposals=[]
for f in json.loads((ROOT/'unresolved-report.json').read_text())['failures']:
 if f['category']!='missing_pa':continue
 gid=f['game_id'];folder=ROOT/gid[:4];g=next(g for g in json.loads((folder/'games.json').read_text()) if g['game_id']==gid);d=json.loads((folder/(gid+'-source.json')).read_text());tables=b.parse_tables(gzip.open(folder/d['path'],'rt',encoding='utf-8').read())
 for side,team in [('away',g['away_team']),('home',g['home_team'])]:
  lineup=b.parse_lineup_group(tables,side);_,stats=b.find_table(tables,'tbl'+side.title()+'Hitter3');stats=[b.row_values(r) for r in stats['rows'] if len(b.row_values(r))>=5 and b.row_values(r)[0].isdigit()]
  outs={inn:sum(0 if b._reached_base(v) else 2 if '병' in v else 1 for row in lineup for v in row['innings'][inn-1]) for inn in range(1,10)}
  for label in ('주루사','도루자','견제사'):
   for (name,inn),count in b.parse_running(b.parse_note_rows(tables),label).items():
    if inn in outs and any(r['name']==name for r in lineup):outs[inn]+=count
  for e in b.ordered_events(lineup,gid,team):
   if not e.get('missing'):continue
   candidates=[]
   for i,row in enumerate(lineup):
    if row['order']!=e['order']:continue
    ab=sum(not re.search(r'4구|고4|사구|희번|희비|타방',v) for cell in row['innings'] for v in cell)
    candidates.append({'lineup_index':i,'name':row['name'],'pos':row['pos'],'official_ab':int(stats[i][0]),'visible_ab':ab,'ab_deficit':int(stats[i][0])-ab,'existing_cell':row['innings'][e['inning']-1]})
   proposals.append({'game_id':gid,'side':side,'team':team,'inning':e['inning'],'order':e['order'],'visible_outs':outs,'candidates':candidates})
atomic(ROOT/'missing-pa-proposals.json',proposals);print(json.dumps(proposals,ensure_ascii=False),flush=True)
