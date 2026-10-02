from backfill_kbo_early_official import connect
c=connect()
with c.cursor() as cur:
 cur.execute("SHOW TRIGGERS LIKE 'kbo_season_records'")
 print('TRIGGERS',[(r[0],r[1],r[4],r[3][:500]) for r in cur.fetchall()],flush=True)
 cur.execute('SHOW FULL PROCESSLIST')
 for r in cur.fetchall():
  if r[7] and r[7].startswith(('INSERT INTO kbo_season_records','INSERT INTO kbo_season_pitch_records','SELECT COUNT','SELECT league')):print(r[0],r[5],r[6],r[7][:180],flush=True)
 cur.execute("SELECT COUNT(*) FROM kbo_season_records FORCE INDEX(idx_league_game_team_batting_index) WHERE league_level=1 AND game_id='20010405HTOB0'")
 print('committed sample',cur.fetchone(),flush=True)
 cur.execute('SHOW ENGINE INNODB STATUS')
 status=cur.fetchone()[2]
 print('\n'.join(line for line in status.splitlines() if any(token in line for token in ('LOCK WAIT','lock struct','OS file','pending','reads/s','writes/s','Buffer pool size','Free buffers','Database pages','Modified db pages','rows inserted','inserts/s','History list length','fetching rows','inserting','Trx read'))),flush=True)
c.close()
