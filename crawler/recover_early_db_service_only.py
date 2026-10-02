"""Wait for authorized normal shutdown, recover service, restore settings; no ingest."""
import subprocess
import time
from pathlib import Path
from backfill_kbo_early_official import ROOT, atomic, connect

STATUS = ROOT / 'service-backfill-progress.json'
OLD_PID = 3815180

def ready(expected):
    while True:
        try:
            connection = connect()
            with connection.cursor() as cursor:
                cursor.execute('SELECT @@innodb_buffer_pool_size, @@event_scheduler, 1')
                values = cursor.fetchone()
            connection.close()
            if values[0] != expected:
                raise RuntimeError('unexpected runtime buffer size')
            if values[1] != 'ON':
                raise RuntimeError('original event scheduler state not restored')
            return values
        except RuntimeError:
            raise
        except Exception:
            time.sleep(5)

def run(*args):
    subprocess.run(['sudo', '-n', *args], check=True)

atomic(STATUS, {'phase': 'waiting_normal_shutdown_service_only', 'old_pid': OLD_PID,
               'force_kill': False, 'automatic_backfill': False})
while Path(f'/proc/{OLD_PID}').exists():
    time.sleep(10)
print('old MariaDB exited normally; starting service only', flush=True)
run('python3', 'preserve_early_events.py')
run('/opt/bitnami/ctlscript.sh', 'start', 'mariadb')
atomic(STATUS, {'phase': 'recovering_service_128M', 'automatic_backfill': False})
ready(128 * 1024 * 1024)
atomic(STATUS, {'phase': 'service_ready_128M_verification_pending',
               'buffer_bytes': 128 * 1024 * 1024, 'automatic_backfill': False,
               'original_16M_restore_pending': True})
print('SQL connection recovered; backfill held; verify recovery before original-setting restart', flush=True)
