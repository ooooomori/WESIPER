"""Explicitly approved temporary MariaDB memory file edit, with exact restoration."""
import argparse,re,shutil
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--restore',action='store_true');a=p.parse_args()
config=Path('/opt/bitnami/mariadb/conf/bitnami/memory.conf')
backup=config.with_name('memory.conf.wesiper-early-backfill-original')
original=config.read_bytes()
if a.restore:
 if not backup.exists():raise RuntimeError('original configuration backup missing')
 restored=backup.read_bytes();config.write_bytes(restored)
 if config.read_bytes()!=restored:raise RuntimeError('restore verification failed')
 print('memory.conf restored exactly',flush=True)
else:
 text=original.decode()
 changed,n=re.subn(r'(?m)^innodb_buffer_pool_size[ \t]*=[ \t]*16M[ \t]*$', 'innodb_buffer_pool_size=128M',text)
 if n!=1:raise RuntimeError('expected one original 16M setting; no changes made')
 if backup.exists():
  if backup.read_bytes()!=original:raise RuntimeError('existing backup differs from current original configuration; no changes made')
 else:
  shutil.copy2(config,backup);backup.chmod(0o600)
 config.write_bytes(changed.encode())
 print('memory.conf buffer 16M -> 128M; original backed up',flush=True)

