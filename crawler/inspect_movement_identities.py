import json,re
from collect_player_movements import ROOT
from backfill_kbo_early_official import connect
p=json.loads((ROOT/'number-plan.json').read_text());ids=sorted({pid for r in p['unresolved_number_identities'] for pid in r['candidate_player_ids']});con=connect()
with con.cursor() as c:
 c.execute('SELECT player_id,name,oldname,team,pos,birth,draft,backNo FROM kbo_player_data WHERE player_id IN ('+','.join(['%s']*len(ids))+')',ids)
 print(json.dumps(c.fetchall(),ensure_ascii=False,default=str))
for row in p['unresolved_number_identities']:
 print(row['event_date'],row['team'],row['player_text'])
 with con.cursor() as c:
  for pid in row['candidate_player_ids']:
   c.execute('SELECT league_level,team,COUNT(*) FROM kbo_season_pitch_records WHERE player_id=%s AND game_date BETWEEN %s AND %s GROUP BY league_level,team',(pid,str(row['event_date'][:4])+'-01-01',str(row['event_date'][:4])+'-12-31'));print(pid,'pitch',c.fetchall())
con.close()
