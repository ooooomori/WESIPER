from backfill_kbo_early_official import connect
c=connect()
with c.cursor() as cur:
 for t in ('kbo_season_records','kbo_season_pitch_records','kbo_schedule'):
  cur.execute('SHOW CREATE TABLE '+t);r=cur.fetchone();print(r[1][-250:])
  cur.execute('SHOW INDEX FROM '+t);print(t,[(r[2],r[3],r[4]) for r in cur.fetchall()])
 cur.execute('SHOW FULL PROCESSLIST')
 print([(r[0],r[4],r[5],r[6],r[7][:150] if r[7] and r[7].startswith('SELECT') else 'other') for r in cur.fetchall()])
c.close()
