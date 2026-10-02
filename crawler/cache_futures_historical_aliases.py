"""Read existing game-name aliases once with a bounded query; no table changes."""
from backfill_kbo_early_official import connect
from backfill_futures_history import ROOT,atomic
con=connect()
try:
 with con.cursor() as c:
  c.execute('SET SESSION max_statement_time=60')
  c.execute('SELECT DISTINCT player_id,player_name FROM kbo_season_records WHERE player_id NOT BETWEEN 1000 AND 9999 AND player_id>0 AND player_name IS NOT NULL')
  aliases=[{'player_id':int(pid),'name':name} for pid,name in c.fetchall()]
 atomic(ROOT/'existing-game-name-aliases.json',aliases);print('historical name aliases cached',len(aliases),flush=True)
finally:con.close()
