"""Normalize only the new Futures scope, with per-game commits and resumable checks."""
import argparse,json
from collections import Counter
from backfill_futures_history import ROOT,atomic
from backfill_kbo_early_official import connect
mapping={'홀드':'홀','세이브':'세','무':None}
p=argparse.ArgumentParser();p.add_argument('--write',action='store_true');a=p.parse_args()
con=connect();counts=Counter();games=0
try:
 with con.cursor() as c:c.execute('SET SESSION max_statement_time=30')
 for year in range(2010,2022):
  folder=ROOT/str(year);committed=set(json.loads((folder/'write-receipt.json').read_text())['committed_game_ids'])
  for gid in sorted(committed):
   path=folder/'plans'/(gid+'.json');item=json.loads(path.read_text());changes=[r for r in item['pitcher_rows'] if r['record'] in mapping]
   if not changes:continue
   for row in changes:counts[row['record']]+=1
   games+=1
   if not a.write:continue
   try:
    with con.cursor() as c:
     for row in changes:
      old=row['record'];new=mapping[old];params=(2,gid,item['game']['game_date'],row['player_id'])
      c.execute('SELECT record FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE league_level=%s AND game_id=%s AND game_date=%s AND player_id=%s FOR UPDATE',params);actual=c.fetchall()
      if len(actual)!=1 or actual[0][0] not in (old,new):raise ValueError(f'{gid}: pitcher result checkpoint mismatch')
      if actual[0][0]!=new:
       c.execute('UPDATE kbo_season_pitch_records SET record=%s WHERE league_level=%s AND game_id=%s AND game_date=%s AND player_id=%s AND record <=> %s',(new,*params,old))
       if c.rowcount!=1:raise ValueError('unexpected update count')
      row['record']=new
    con.commit();atomic(path,item);atomic(ROOT/'pitcher-result-normalization-progress.json',{'last_game_id':gid,'games':games,'source_counts':dict(counts)})
   except Exception:con.rollback();raise
  print(year,'pitcher result normalization',dict(counts),'games',games,flush=True)
 atomic(ROOT/('pitcher-result-normalization.json' if a.write else 'pitcher-result-normalization-dry.json'),{'games':games,'source_counts':dict(counts),'written':a.write})
finally:con.close()
