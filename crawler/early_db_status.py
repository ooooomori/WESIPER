from backfill_kbo_early_official import connect
c=connect()
with c.cursor() as cur:
 cur.execute('SHOW FULL PROCESSLIST')
 for r in cur.fetchall():print(r[0],r[4],r[5],r[6],r[7][:120] if r[7] and r[7].startswith(('SELECT','INSERT','ROLLBACK')) else '-',flush=True)
 cur.execute("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_data_pending_reads','Innodb_data_pending_writes','Innodb_data_reads','Innodb_data_writes','Innodb_buffer_pool_pages_dirty','Innodb_rows_inserted','Innodb_row_lock_current_waits')")
 print(cur.fetchall(),flush=True)
 cur.execute("SELECT MAX(PK) FROM kbo_season_records");print('max PK',cur.fetchone(),flush=True)
c.close()
