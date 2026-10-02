"""Reconcile exact commit gaps, resume bounded ingest, restore approved setting."""
import json
import subprocess
import time
from backfill_kbo_early_official import ROOT, connect, discover, atomic

def runtime(expected):
    for attempt in range(30):
        try:
            connection=connect()
            with connection.cursor() as cursor:
                cursor.execute('SELECT @@innodb_buffer_pool_size,@@event_scheduler')
                value=cursor.fetchone()
            connection.close()
            if value==(expected,'ON'):return
            # The service manager may return while the old process still
            # answers; wait for the requested new runtime without restarting.
            time.sleep(2)
        except Exception:time.sleep(2)
    raise RuntimeError('DB not ready; inspect before any further restart')

runtime(128*1024*1024)
for year in range(2001,2008):
    receipt=ROOT/str(year)/'write-receipt.json'
    prior=json.loads(receipt.read_text()) if receipt.exists() else {}
    blocked={value['game']['game_id'] for value in json.loads((ROOT/str(year)/'dry-failures.json').read_text())}
    ids=[game['game_id'] for game in discover(year)
         if game['game_id'] not in set(prior.get('committed_game_ids',[]))|blocked][:5]
    if not ids:continue
    connection=connect()
    with connection.cursor() as cursor:
        cursor.execute('SELECT DISTINCT game_id FROM kbo_season_records FORCE INDEX(idx_league_game_team_batting_index) WHERE league_level=1 AND game_id IN ('+','.join(['%s']*len(ids))+')',ids)
        existing=[row[0] for row in cursor.fetchall()]
    connection.close()
    for game_id in existing:
        subprocess.run(['python3','reconcile_early_committed_checkpoint.py','--year',str(year),'--game-id',game_id],check=True)

atomic(ROOT/'service-backfill-progress.json',{'phase':'writing_verified_games_128M','buffer_bytes':128*1024*1024,'batch_games':5})
result=subprocess.call(['python3','-u','backfill_kbo_early_official.py','--write','--allow-incomplete','--batch-games','5'])
if result:
    atomic(ROOT/'service-backfill-progress.json',{'phase':'write_failed_128M_inspect_before_restore','write_exit_code':result})
    raise SystemExit(result)
atomic(ROOT/'service-backfill-progress.json',{'phase':'restoring_original_settings_after_verified_write'})
subprocess.run(['sudo','-n','python3','configure_early_db_memory.py','--restore'],check=True)
subprocess.run(['sudo','-n','python3','preserve_early_events.py'],check=True)
subprocess.run(['sudo','-n','/opt/bitnami/ctlscript.sh','restart','mariadb'],check=True)
runtime(16*1024*1024)
subprocess.run(['sudo','-n','python3','preserve_early_events.py','--restore-config-only'],check=True)
atomic(ROOT/'service-backfill-progress.json',{'phase':'verified_write_completed_original_settings_restored',
       'buffer_bytes':16*1024*1024,'event_scheduler':'ON','unresolved_missing_pa_games':7})
print('verified scoped write completed; original buffer and configuration restored',flush=True)
