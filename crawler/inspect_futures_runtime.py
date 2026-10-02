from backfill_kbo_early_official import connect
import subprocess
c=connect()
try:
 with c.cursor() as q:
  q.execute('SELECT @@innodb_buffer_pool_size,@@event_scheduler'); print('runtime',q.fetchone(),flush=True)
finally:c.close()
p='/opt/bitnami/mariadb/conf/bitnami/memory.conf'
print('original_config_equal',subprocess.run(['sudo','-n','cmp','-s',p,p+'.wesiper-early-backfill-original']).returncode==0,flush=True)
