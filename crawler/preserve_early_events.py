"""Preserve observed pre-restart event_scheduler=ON; restore exact config bytes."""
import argparse,hashlib,re
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--restore-config-only',action='store_true');a=p.parse_args()
config=Path('/opt/bitnami/mariadb/conf/bitnami/memory.conf')
backup=config.with_name('memory.conf.wesiper-early-backfill-original')
if a.restore_config_only:
 data=backup.read_bytes();config.write_bytes(data)
 if config.read_bytes()!=data:raise RuntimeError('config restoration mismatch')
 print('original configuration bytes restored; runtime event scheduler retained',flush=True)
else:
 text=config.read_text()
 if re.search(r'(?m)^event[-_]scheduler[ \t]*=[ \t]*ON[ \t]*$',text):
  print('original runtime event_scheduler=ON already preserved',flush=True);raise SystemExit(0)
 if re.search(r'(?m)^event[-_]scheduler[ \t]*=',text):raise RuntimeError('unexpected existing event setting')
 config.write_text(text.rstrip('\n')+'\nevent_scheduler=ON\n')
 print('preserving original runtime event_scheduler=ON on this startup',flush=True)

