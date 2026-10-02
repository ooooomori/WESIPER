"""Verify every explicitly corrected event in the operating database."""
import json
from collections import Counter
from backfill_futures_history import ROOT,atomic
from backfill_kbo_early_official import connect
from futures_history_corrections import settings
config=settings();ids=sorted(set(config['games'])|set(config['missing_pa_games']));con=connect();summary={'games':len(ids),'generic_out_rows':0,'verified_events':[]}
try:
 with con.cursor() as c:
  c.execute('SET SESSION max_statement_time=30')
  for gid in ids:
   item=json.loads((ROOT/gid[:4]/'plans'/(gid+'.json')).read_text());g=item['game']
   c.execute('SELECT player_id,player_name,team,inning,pa_result,batting_index,`order`,sb,cs,run_out,is_gwrbi,pitcher_id,pitcher_name FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE league_level=2 AND game_id=%s AND game_date=%s',(gid,g['game_date']))
   fields=('player_id','player_name','team','inning','pa_result','batting_index','order','sb','cs','run_out','is_gwrbi','pitcher_id','pitcher_name');rows=[dict(zip(fields,r)) for r in c.fetchall()]
   if len(rows)!=len(item['batter_rows']):raise ValueError('corrected game row count differs')
   if any(r['pa_result'] is not None and (r['pitcher_id'] is None or r['pitcher_name'] is None) for r in rows):raise ValueError('corrected game PA lacks matchup')
   for event in item['source_corrections']:
    if event.get('field')!='missing_pa':continue
    found=[r for r in rows if (r['team'],r['player_name'],r['inning'],r['order'],r['pa_result'])==(event['team'],event['name'],event['inning'],event['order'],'아웃')]
    if len(found)!=1:raise ValueError('generic out does not uniquely match intended row')
    summary['generic_out_rows']+=1;summary['verified_events'].append({'game_id':gid,**event,'player_id':found[0]['player_id'],'batting_index':found[0]['batting_index']})
   correction=config['games'].get(gid,{})
   win=correction.get('winning_hit')
   if gid=='20200506KTHH0':win={'name':'김경민','inning':6,'result':'중안'}
   if gid=='20210925OBWO0':win={'name':'오명진','inning':6,'result':'우중3'}
   if win:
    found=[r for r in rows if r['is_gwrbi']]
    if len(found)!=1 or (found[0]['player_name'],found[0]['inning'],found[0]['pa_result'])!=(win['name'],win['inning'],win['result']):raise ValueError('winning hit row differs from correction')
    summary['verified_events'].append({'game_id':gid,'field':'winning_hit',**win,'player_id':found[0]['player_id']})
   for event in correction.get('running',[]):
    field='cs' if event['label']=='도루자' else 'run_out';named=[r for r in rows if r['player_name']==event['name'] and r['inning']==event['inning'] and r[field]]
    if len(named)!=1 or int(named[0]['player_id'])!=event['player_id'] or named[0][field]!=1:raise ValueError('running event linked to wrong identity')
    summary['verified_events'].append({'game_id':gid,'field':field,**event,'batting_index':named[0]['batting_index']})
   if gid=='20110818LGPL0':
    found=[r for r in rows if r['player_name']=='황선일' and r['inning']==7 and r['run_out']==1]
    if len(found)!=1 or found[0]['pa_result']!='좌안':raise ValueError('황선일 run out mismatch')
 if summary['generic_out_rows']!=29:raise ValueError('expected exactly 29 user-authorized generic outs')
 atomic(ROOT/'user-correction-verification.json',summary);print(json.dumps({k:v for k,v in summary.items() if k!='verified_events'},ensure_ascii=False),flush=True)
finally:con.close()
