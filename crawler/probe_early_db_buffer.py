from backfill_kbo_early_official import connect
c=connect()
with c.cursor() as q:
 q.execute("SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','innodb_change_buffering','innodb_page_size','innodb_io_capacity','innodb_io_capacity_max','innodb_flush_log_at_trx_commit','innodb_flush_method')")
 print(q.fetchall(),flush=True)
 q.execute("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_pages_free','Innodb_buffer_pool_pages_total','Innodb_buffer_pool_wait_free','Innodb_log_waits')")
 print(q.fetchall(),flush=True)
 q.execute("SHOW GRANTS FOR CURRENT_USER")
 for r in q.fetchall():
  grant=r[0].split(' TO ')[0]
  print(grant,flush=True)
c.close()
