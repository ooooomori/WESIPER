"""Wait only for this task's cancelled transactions, then resume scoped writes."""
import subprocess,sys,time
from backfill_kbo_early_official import connect
cancelled={3146772,3147191}
while True:
 c=connect()
 try:
  with c.cursor() as q:
   q.execute('SHOW FULL PROCESSLIST');pending=[(r[0],r[4],r[5]) for r in q.fetchall() if r[0] in cancelled]
 finally:c.close()
 if not pending:break
 print('waiting own cancelled transactions',pending,flush=True);time.sleep(30)
print('own rollbacks finished; starting validated-game backfill',flush=True)
raise SystemExit(subprocess.call([sys.executable,'backfill_kbo_early_official.py','--write','--allow-incomplete','--batch-games','20']))
