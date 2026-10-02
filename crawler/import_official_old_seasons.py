"""Create separate season-total tables and insert verified groups with checkpoints."""
import argparse,hashlib,json
from collections import Counter
from collect_official_old_seasons import ROOT,atomic
from old_season_totals_schema import TABLES,columns,ddl
from backfill_kbo_early_official import connect
import backfill_kbo_early_official as early
def protected(con):
 result={}
 with con.cursor() as c:
  for table,index in [('kbo_season_records','idx_league_player_date'),('kbo_season_pitch_records','idx_pitch_league_player_date'),('kbo_schedule','idx_schedule_league_date')]:
   c.execute(f'SELECT league_level,YEAR(game_date),COUNT(*) FROM {table} FORCE INDEX ({index}) GROUP BY league_level,YEAR(game_date) ORDER BY league_level,YEAR(game_date)');result[table]=[list(r) for r in c.fetchall()]
 return result
def prepare_parents(con,report,write=False):
 early.ROOT=ROOT;wanted={}
 for relative in report['plans'].values():
  plan=json.loads((ROOT/relative).read_text())
  for row in plan['rows']:wanted.setdefault(row['player_id'],(plan['role'],row))
 items=[]
 for role,row in wanted.values():
  r={'player_id':row['player_id'],'player_name':row['player_name'],'game_date':f"{row['year']}-01-01"};items.append({'batter_rows':[r] if role=='Hitter' else [],'pitcher_rows':[r] if role=='Pitcher' else []})
 # Some retired profiles now represent coaches and omit the fielding position.
 # Keep that official absence as the parent table's existing empty-string
 # representation; do not invent a hitter position or alter the daily parser.
 import player_ingest
 original=player_ingest.parse_profile;missing_positions=[]
 def historical_profile(page,pid,expected_name=None,role=None):
  try:return original(page,pid,expected_name,role)
  except ValueError as e:
   if str(e)!=f'Unknown official position: {pid}':raise
   result=original(page,pid,expected_name,'pitcher');result['pos']='';missing_positions.append({'player_id':pid,'name':result['name'],'reason':'official current profile omits position'});return result
 player_ingest.parse_profile=historical_profile
 try:missing=early.register_parents(con,items,not write)
 finally:player_ingest.parse_profile=original
 if missing_positions:atomic(ROOT/'parent-missing-position.json',missing_positions)
 if write:con.commit()
 print('player parents',len(wanted),'IDs',missing,'new profiles verified',flush=True);return missing
def run(write=False):
 report=json.loads((ROOT/'validation-report.json').read_text())
 if report['failures'] or report['team_split_discrepancies']:raise ValueError('validation failures or team-filter discrepancies remain')
 con=connect()
 try:
  with con.cursor() as c:c.execute('SET SESSION max_statement_time=30')
  before_path=ROOT/'protected-game-tables-before.json'
  if not before_path.exists():atomic(before_path,protected(con))
  prepare_parents(con,report,False)
  if not write:return
  prepare_parents(con,report,True)
  with con.cursor() as c:
   for role in TABLES:c.execute(ddl(role))
  con.commit();receipt=ROOT/'write-receipt.json';done=json.loads(receipt.read_text()) if receipt.exists() else {};annual=Counter()
  for manifest,relative in sorted(report['plans'].items()):
   path=ROOT/relative;plan=json.loads(path.read_text());sha=hashlib.sha256(path.read_bytes()).hexdigest();role=plan['role'];table=TABLES[role];rows=plan['rows']
   if manifest in done:
    if done[manifest]['plan_sha256']!=sha:raise ValueError('committed plan changed; explicit reconciliation required')
    continue
   try:
    with con.cursor() as c:
     for start in range(0,len(rows),100):
      batch=rows[start:start+100]
      for row in batch:
       if not (1982<=row['year']<=2000 and row['league_level']==1):raise ValueError('import outside historical season scope')
       c.execute(f'SELECT raw_record FROM `{table}` WHERE league_level=%s AND year=%s AND series_id=%s AND player_id=%s AND row_scope=%s AND filter_team_code=%s',(1,row['year'],row['series_id'],row['player_id'],row['row_scope'],row['filter_team_code']))
       if c.fetchone():raise ValueError('existing season total without checkpoint; refusing overwrite')
      fields=list(columns(role));sql=f'INSERT INTO `{table}` (`'+ '`,`'.join(fields)+'`) VALUES ('+','.join(['%s']*len(fields))+')'
      if batch:c.executemany(sql,[tuple(row[k] for k in fields) for row in batch])
    con.commit();done[manifest]={'rows':len(rows),'table':table,'plan_sha256':sha};atomic(receipt,done)
   except Exception:con.rollback();raise
   if len(done)%50==0:print('season-total groups committed',len(done),'/',len(report['plans']),flush=True)
  actual={}
  with con.cursor() as c:
   for role,table in TABLES.items():
    c.execute(f'SELECT year,series_id,row_scope,COUNT(*) FROM `{table}` GROUP BY year,series_id,row_scope ORDER BY year,series_id,row_scope');actual[role]=[list(r) for r in c.fetchall()]
    c.execute(f'SELECT COUNT(*) FROM `{table}` t LEFT JOIN kbo_player_data p ON p.player_id=t.player_id WHERE p.player_id IS NULL OR t.player_id<=0 OR t.year NOT BETWEEN 1982 AND 2000 OR t.league_level<>1 OR NOT JSON_VALID(t.raw_record)')
    if c.fetchone()[0]:raise ValueError('invalid scope, identity, or preserved source JSON')
    c.execute(f'SELECT COUNT(*) FROM `{table}`');count=c.fetchone()[0]
    if count!=sum(report['counts'][role].values()):raise ValueError('database row count differs from validated collection')
  after=protected(con);atomic(ROOT/'protected-game-tables-after.json',after)
  if after!=json.loads(before_path.read_text()):raise ValueError('existing game tables changed')
  atomic(ROOT/'write-summary.json',{'years_series_scope_counts':actual,'existing_game_tables_unchanged':True,'groups_committed':len(done)});print('SEASON TOTAL IMPORT VERIFIED',flush=True)
 finally:con.close()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--write',action='store_true');a=p.parse_args();run(a.write)
