"""Install the verified profile parser without overwriting concurrent edits."""
import hashlib
import os
import py_compile
import shutil
from pathlib import Path

root = Path('/home/bitnami/wesiper')
stage = root / 'identity-repair-20261002'
source = stage / 'player_ingest.py'
target = root / 'player_ingest.py'
if hashlib.sha256(target.read_bytes()).hexdigest() != '7405a4ac057273b3511b606b20eb89e6c9f270d176e8bb1a9cbfe083763540f4':
    raise RuntimeError('Deployed parser changed; review before installing')
old = target.read_text()
new = source.read_text()
old_pattern = r"r'(투수|포수|내야수|외야수)?\s*\((우|좌|양)투(우|좌|양)타\)'"
new_pattern = r"r'(투수|포수|내야수|외야수)?\s*\(((?:우|좌|양)(?:투|언|사))((?:우|좌|양)타)\)'"
if old.replace(old_pattern, new_pattern) != new:
    raise RuntimeError('Unexpected parser changes')
shutil.copy2(target, stage / 'before' / 'player_ingest.py')
shutil.copy2(source, root / 'player_ingest.py.next')
os.replace(root / 'player_ingest.py.next', target)
py_compile.compile(str(target), doraise=True)
from player_ingest import parse_profile
for throws, bat in [('우투', '우타'), ('좌투', '좌타'), ('우언', '우타'), ('우사', '우타'), ('우투', '양타')]:
    page = f'<h4 id="h4Team" class="regular/2026/emblem_HT"></h4><li>선수명: 선수</li><li>생년월일: 1979년 1월 19일</li><li>포지션: 투수({throws}{bat})</li>'
    row = parse_profile(page, 62349)
    assert row['throw'] == throws and row['bat'] == bat
Path('/tmp/wesiper-candle-data-revision').write_text('player-hands-two-characters-20261002')
print('Installed profile parser; five hand formats verified; API revision refreshed')
