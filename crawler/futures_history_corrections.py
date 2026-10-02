"""Scoped user-approved interpretations; original cached sources stay untouched."""
import json,re
from pathlib import Path
import kbo_futures_crawl as base

def settings():return json.loads(Path(__file__).with_name('futures_history_user_corrections.json').read_text(encoding='utf-8'))
def game_settings(gid):return settings()['games'].get(gid,{})

def corrected_page(g,page):
 gid=g['game_id'];config=game_settings(gid);corrections=[]
 if config.get('note_names'):
  # Change notes only, retaining the actual batting-row historical names.
  tables=base.parse_tables(page)
  for table in tables:
   if not any(base.row_values(row)[0]=='결승타' for row in table['rows'] if base.row_values(row)):continue
   body=table['body']
   for old,new in config['note_names'].items():body=body.replace(old,new);corrections.append({'field':'note_name','from':old,'to':new,'evidence':'user correction'})
   page=page.replace(table['body'],body,1)
 if gid not in settings()['missing_pa_games']:return page,corrections
 tables=base.parse_tables(page);pitchers=base.parse_pitchers(tables,(g['away_team'],g['home_team']))
 for side,team,opponent in [('away',g['away_team'],g['home_team']),('home',g['home_team'],g['away_team'])]:
  lineup=base.parse_lineup_group(tables,side);idx,_=base.find_table(tables,'tbl'+side.title()+'Hitter1');_,stat=base.find_table(tables,'tbl'+side.title()+'Hitter3');stats=[base.row_values(r) for r in stat['rows'] if len(base.row_values(r))>=5 and base.row_values(r)[0].isdigit()]
  events=base.ordered_events(lineup,gid,team);holes=[e for e in events if e.get('missing')];bf=sum(r['pitched'] for r in pitchers[opponent]);visible=sum(not e.get('missing') for e in events)
  if visible==bf:continue
  if len(holes)!=bf-visible or not holes:raise ValueError('missing PA slots do not match official BF deficit')
  result_table=tables[idx+1];edits={}
  for hole in holes:
   candidates=[]
   for i,b in enumerate(lineup):
    if b['order']!=hole['order']:continue
    ab=sum(not re.search(r'4구|고4|사구|희번|희비|타방',v) for cell in b['innings'] for v in cell)
    if int(stats[i][0])-ab==1:candidates.append(i)
   if len(candidates)!=1:raise ValueError(f'{gid}: missing PA batter ambiguous {hole}')
   i=candidates[0];inning=hole['inning'];existing=lineup[i]['innings'][inning-1];mode='empty'
   if gid=='20190514SSSK0':inning=7;existing=lineup[i]['innings'][6];mode='append'
   if gid=='20160523PLHT0':
    # The ordering gap crosses the inning boundary: third PA of order 9
    # finishes the long fifth inning, then order 1 leads off the sixth.
    if hole['order']==9:inning=5;existing=lineup[i]['innings'][4];mode='append'
   if existing and mode!='append':raise ValueError('refusing to replace a recorded PA')
   edits[(i+1,inning-1)]=existing+['아웃']
   lineup[i]['innings'][inning-1]=existing+['아웃']
   corrections.append({'field':'missing_pa','team':team,'name':lineup[i]['name'],'lineup_index':i,'order':hole['order'],'inning':inning,'result':'아웃','existing_results':existing,'official_ab':int(stats[i][0]),'evidence':'user authorized generic 아웃; official batting order, batter AB deficit, and opponent BF'})
  row_index=-1
  def row_replace(match):
   nonlocal row_index
   row_index+=1;cell_index=-1
   def cell_replace(cell):
    nonlocal cell_index
    cell_index+=1
    values=edits.get((row_index,cell_index))
    if values is None:return cell[0]
    return cell[1]+'/'.join(values)+cell[3]
   return match[1]+re.sub(r'(<t[hd]\b[^>]*>)(.*?)(</t[hd]>)',cell_replace,match[2],flags=re.I|re.S)+match[3]
  body=re.sub(r'(<tr\b[^>]*>)(.*?)(</tr>)',row_replace,result_table['body'],flags=re.I|re.S)
  page=page.replace(result_table['body'],body,1)
 return page,corrections

def winning_override(notes,events,gid,original):
 override=game_settings(gid).get('winning_hit')
 if not override:return original(notes,events,gid)
 found=[e for e in events if not e.get('missing') and e['batter']['name']==override['name'] and e['inning']==override['inning'] and e['pa_result']==override['result']]
 if len(found)!=1:raise ValueError('user winning hit must match exactly one actual PA')
 e=found[0];return e['batter']['_key'],e['inning'],e['result_index']
