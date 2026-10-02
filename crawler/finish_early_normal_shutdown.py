"""Authorized normal-shutdown wait, startup, scoped write, and exact restoration."""
import subprocess,time
from pathlib import Path
from backfill_kbo_early_official import ROOT,connect,atomic
old_pid=3815180
status=ROOT/'service-backfill-progress.json'
atomic(status,{'phase':'waiting_normal_shutdown','old_pid':old_pid,'force_kill':False})
while Path(f'/proc/{old_pid}').exists():time.sleep(10)
print('normal shutdown completed; starting MariaDB with approved 128M setting',flush=True)
subprocess.run(['sudo','-n','python3','preserve_early_events.py'],check=True)
subprocess.run(['sudo','-n','/opt/bitnami/ctlscript.sh','start','mariadb'],check=True)
for attempt in range(60):
 try:
  c=connect()
  with c.cursor() as q:q.execute('SELECT @@innodb_buffer_pool_size');size=q.fetchone()[0]
  c.close();break
 except Exception:time.sleep(5)
else:raise RuntimeError('DB startup not ready; configuration backup retained')
if size!=128*1024*1024:raise RuntimeError('expected 128M runtime buffer; write refused')
atomic(status,{'phase':'writing_validated_games','buffer_bytes':size,'skipped_missing_pa_games':7})
print('DB ready with buffer',size,'starting validated games',flush=True)
result=1
try:
 preflight=subprocess.call(['python3','-u','recheck_early_historical_names.py'])
 if preflight:raise RuntimeError('historical-name single-game verification failed; plans retained')
 result=subprocess.call(['python3','-u','backfill_kbo_early_official.py','--write','--allow-incomplete','--batch-games','20'])
finally:
 atomic(status,{'phase':'restoring_original_config','write_exit_code':result})
 subprocess.run(['sudo','-n','python3','configure_early_db_memory.py','--restore'],check=True)
 subprocess.run(['sudo','-n','python3','preserve_early_events.py'],check=True)
 subprocess.run(['sudo','-n','/opt/bitnami/ctlscript.sh','restart','mariadb'],check=True)
 for attempt in range(60):
  try:
   c=connect()
   with c.cursor() as q:q.execute('SELECT @@innodb_buffer_pool_size');restored=q.fetchone()[0]
   c.close();break
  except Exception:time.sleep(5)
 else:raise RuntimeError('DB not ready after restore; inspect normal shutdown before any further restart')
 if restored!=16*1024*1024:raise RuntimeError('original runtime buffer was not restored')
 subprocess.run(['sudo','-n','python3','preserve_early_events.py','--restore-config-only'],check=True)
 atomic(status,{'phase':'complete' if result==0 else 'write_failed_original_settings_restored','write_exit_code':result,'buffer_bytes':restored})
 print('original 16M runtime buffer restored; write exit',result,flush=True)
raise SystemExit(result)


