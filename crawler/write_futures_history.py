"""Insert only verified historical Futures plans, one game per transaction."""
import argparse,hashlib,json,signal
from pathlib import Path
from backfill_futures_history import ROOT,atomic
from backfill_kbo_early_official import connect,BATTER_FIELDS,PITCH_FIELDS
import backfill_kbo_early_official as early

def protected(con):
 out={}
 with con.cursor() as c:
  for t,idx in [('kbo_season_records','idx_league_player_date'),('kbo_season_pitch_records','idx_pitch_league_player_date'),('kbo_schedule','idx_schedule_league_date')]:
   c.execute(f"SELECT league_level,YEAR(game_date),COUNT(*) FROM {t} FORCE INDEX ({idx}) WHERE league_level<>2 OR game_date<'2010-01-01' OR game_date>='2022-01-01' OR game_date IS NULL GROUP BY league_level,YEAR(game_date) ORDER BY league_level,YEAR(game_date)")
   out[t]=[list(r) for r in c.fetchall()];print('protected snapshot',t,flush=True)
 return out

def verify(con,year,ids):
 scope='league_level=2 AND game_date>=%s AND game_date<%s AND game_id IN ('+','.join(['%s']*len(ids))+')';params=(f'{year}-01-01',f'{year+1}-01-01',*ids)
 out={}
 with con.cursor() as c:
  c.execute(f"SELECT COUNT(*),COUNT(DISTINCT game_id),COALESCE(SUM(run_out),0),COALESCE(SUM(pa_result IS NOT NULL AND batting_index IS NULL),0),COALESCE(SUM((pitcher_id IS NULL)<>(pitcher_name IS NULL)),0),COALESCE(SUM(run_out IS NULL OR sb IS NULL OR cs IS NULL),0),COALESCE(SUM(player_id IS NULL OR player_id<=0),0) FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE {scope}",params)
  out.update(zip(('batter_rows','games','run_out','null_batting_index','partial_pitcher','null_running','missing_player'),map(int,c.fetchone())))
  c.execute(f"SELECT COUNT(*),COALESCE(SUM(player_id IS NULL OR player_id<=0),0) FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE {scope}",params)
  out['pitcher_rows'],out['missing_pitch_player']=map(int,c.fetchone())
  queries={
   'invalid_index_groups':f"SELECT COUNT(*) FROM (SELECT game_id,team FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE {scope} AND pa_result IS NOT NULL GROUP BY game_id,team HAVING MIN(batting_index)<>1 OR MAX(batting_index)<>COUNT(*) OR COUNT(DISTINCT batting_index)<>COUNT(*)) x",
   'duplicate_pitchers':f"SELECT COUNT(*) FROM (SELECT game_id,player_id FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE {scope} GROUP BY game_id,player_id HAVING COUNT(*)>1) x",
   'duplicate_batters':f"SELECT COUNT(*) FROM (SELECT game_id,team,player_id,inning,pa_result,batting_index FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE {scope} GROUP BY game_id,team,player_id,inning,pa_result,batting_index HAVING COUNT(*)>1) x"}
  for name,sql in queries.items():c.execute(sql,params);out[name]=int(c.fetchone()[0])
  c.execute('SELECT COUNT(*) FROM kbo_schedule WHERE '+scope.replace('game_id IN','game_code IN'),params);out['schedule_games']=int(c.fetchone()[0])
 for k in ('null_batting_index','partial_pitcher','null_running','missing_player','missing_pitch_player','invalid_index_groups','duplicate_pitchers','duplicate_batters'):
  if out[k]:raise ValueError(f'{year}: {k}={out[k]}')
 return out

def validate(item):
 g=item['game'];year=int(g['game_date'][:4])
 if not 2010<=year<=2021:raise ValueError('outside scope')
 if not item['batter_rows']:raise ValueError('empty batter plan')
 for r in item['batter_rows']+item['pitcher_rows']:
  if r['league_level']!=2 or r['game_id']!=g['game_id'] or r['game_date']!=g['game_date'] or not r['player_id'] or int(r['player_id'])<=0:raise ValueError('invalid plan scope/identity')
 for team in (g['away_team'],g['home_team']):
  idx=[r['batting_index'] for r in item['batter_rows'] if r['team']==team and r['pa_result']]
  if sorted(idx)!=list(range(1,len(idx)+1)):raise ValueError('invalid batting indices')
 for r in item['batter_rows']:
  if (r['pitcher_id'] is None)!=(r['pitcher_name'] is None) or any(r[k] is None for k in ('run_out','sb','cs')):raise ValueError('invalid nullable fields')
 if len({(r['team'],r['player_id']) for r in item['pitcher_rows']})!=len(item['pitcher_rows']):raise ValueError('duplicate pitcher identity')
 if any(r['record'] not in (None,'승','패','홀','세') for r in item['pitcher_rows']):raise ValueError('noncanonical pitcher result')

def run(start,end,write=False,prepare_only=False):
 con=connect();early.ROOT=ROOT
 stopping={'value':False}
 for sig in (signal.SIGINT,signal.SIGTERM):signal.signal(sig,lambda *_:stopping.update(value=True))
 try:
  if write:
   baseline=json.loads((ROOT/'protected-before.json').read_text())
   status=json.loads((ROOT/'backup-status.json').read_text())
   if status['phase']!='backup_created_and_gzip_verified':raise ValueError('verified backup required')
   backup=ROOT/'recovery-backups'/status['file'];digest=hashlib.sha256(backup.read_bytes()).hexdigest()
   if digest!=status['sha256']:raise ValueError('backup checksum mismatch')
  else:
   atomic(ROOT/'protected-before.json',protected(con));baseline=None
   if prepare_only:return
  report_path=ROOT/'write-report.json'
  report=json.loads(report_path.read_text()) if report_path.exists() else {}
  for year in range(start,end+1):
   folder=ROOT/str(year);dry=json.loads((folder/'dry-report.json').read_text())
   blocked={v['game_id'] for v in dry['failures']}
   plans=[json.loads(p.read_text()) for p in sorted((folder/'plans').glob('*.json')) if p.stem not in blocked]
   for item in plans:validate(item)
   early.register_parents(con,plans,True);print(year,'parent profiles verified',flush=True)
   if not write:continue
   receipt=folder/'write-receipt.json';done=json.loads(receipt.read_text()) if receipt.exists() else {'committed_game_ids':[]}
   committed=set(done['committed_game_ids'])
   with con.cursor() as c:c.execute('SET SESSION max_statement_time=30')
   for item in plans:
    if stopping['value']:print('stopped after committed checkpoint',flush=True);return
    g=item['game'];gid=g['game_id']
    if gid in committed:continue
    try:
     with con.cursor() as c:
      for t,idx in [('kbo_season_records','idx_league_game_team_batting_index'),('kbo_season_pitch_records','idx_pitch_league_game')]:
       c.execute(f'SELECT COUNT(*) FROM {t} FORCE INDEX ({idx}) WHERE league_level=2 AND game_id=%s',(gid,))
       if c.fetchone()[0]:raise ValueError(f'{gid}: existing rows require checkpoint reconciliation; no overwrite')
      early.register_parents(con,[item],False)
      for t,fields,key in [('kbo_season_records',BATTER_FIELDS,'batter_rows'),('kbo_season_pitch_records',PITCH_FIELDS,'pitcher_rows')]:
       rows=sorted(item[key],key=lambda r:int(r['player_id']))
       if rows:c.executemany('INSERT INTO '+t+' (`'+'`,`'.join(fields)+'`) VALUES ('+','.join(['%s']*len(fields))+')',[tuple(r[k] for k in fields) for r in rows])
      c.execute('SELECT game_date FROM kbo_schedule WHERE league_level=2 AND game_code=%s FOR UPDATE',(gid,));existing=c.fetchone()
      if existing:raise ValueError(f'{gid}: existing schedule requires explicit reconciliation; no overwrite')
      fields=('league_level','game_code','game_date','away_team','home_team','away_score','home_score','tv','stadium','is_allstar','away_inning_scores','home_inning_scores')
      values=(2,gid,g['game_date'],g['away_team'],g['home_team'],g['away_score'],g['home_score'],g['tv'],g['stadium'],g['is_allstar'],json.dumps(item['away_innings']),json.dumps(item['home_innings']))
      c.execute('INSERT INTO kbo_schedule (`'+'`,`'.join(fields)+'`) VALUES ('+','.join(['%s']*len(fields))+')',values)
     result=verify(con,year,[gid])
     if result['batter_rows']!=len(item['batter_rows']) or result['pitcher_rows']!=len(item['pitcher_rows']) or result['schedule_games']!=1:raise ValueError('written counts mismatch')
     con.commit();committed.add(gid);atomic(receipt,{'committed_game_ids':sorted(committed)})
     if len(committed)%10==0:print(year,'committed',len(committed),'/',len(plans),flush=True)
    except Exception:con.rollback();raise
   result=verify(con,year,sorted(committed));result['unresolved_games']=sorted(blocked);result['pitcher_missing_games']=[v['game']['game_id'] for v in plans if not v['pitcher_rows']];report[str(year)]=result;atomic(ROOT/'write-report.json',report);print(year,'WRITE VERIFIED',result,flush=True)
  if write:
   after=protected(con);atomic(ROOT/'protected-after.json',after)
   if after!=baseline:raise ValueError('outside-range counts changed')
   atomic(ROOT/'verified-summary.json',{'years':report,'outside_counts_unchanged':True})
 finally:con.close()

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--start-year',type=int,default=2010);p.add_argument('--end-year',type=int,default=2021);p.add_argument('--write',action='store_true');p.add_argument('--prepare-only',action='store_true');a=p.parse_args()
 if not 2010<=a.start_year<=a.end_year<=2021:raise ValueError('scope')
 run(a.start_year,a.end_year,a.write,a.prepare_only)
