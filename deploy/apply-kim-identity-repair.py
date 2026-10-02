"""Install the scoped identity correction on the existing crawler host."""
import gzip
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path('/home/bitnami/wesiper')
STAGE = ROOT / 'identity-repair-20261002'
expected = {
    'plan_player_renames.py': '4d181e4c6a2f73800a7e26f351b2d77fa3ec335aee7ecde5561b9fddb2023881',
    'import_player_movements.py': '0e5b5fb956c94968f48bc36a5c58bb0cef36a4b792dd50a31d9b6c6b753cdb73',
    'plan_player_movement_numbers.py': 'c488131dde04dd56c2b02df92294191414978cb99b16865251d91f71ff025051',
}
for name, digest in expected.items():
    actual=hashlib.sha256((ROOT/name).read_bytes()).hexdigest()
    assert actual in (digest,hashlib.sha256((STAGE/name).read_bytes()).hexdigest()), f'Concurrent remote edit: {name}'
name = 'futures_history_parser.py'
updated = (STAGE/name).read_text().replace('\r\n', '\n')
original = updated.replace('from player_identity_corrections import kim_taeuk_id\n', '').replace("  confirmed=kim_taeuk_id(name,team,season)\n  if confirmed is not None:\n   if confirmed not in self.current_names:raise ValueError('Confirmed Kim Tae-uk parent missing')\n   return confirmed\n", '')
assert (ROOT/name).read_text().replace('\r\n', '\n') in (original,updated), 'Concurrent remote historical parser edit'
name = 'kbo_futures_player_overrides.json'
updated_rules = json.loads((STAGE/name).read_text())
original_rules = [r for r in updated_rules if not (r.get('team') == '한화' and r.get('name') == '김병현' and r.get('player_id') == 67768 and r.get('season') in (2017,2018,2019,2020))]
assert json.loads((ROOT/name).read_text()) in (original_rules,updated_rules), 'Concurrent remote overrides edit'
files = list(expected) + ['futures_history_parser.py', 'kbo_futures_player_overrides.json', 'player_identity_corrections.py']
before = STAGE/'before'
before.mkdir(exist_ok=True)
for name in files:
    source = STAGE/name
    if name.endswith('.py'):
        compile(source.read_text(), str(source), 'exec')
    if (ROOT/name).exists() and not (before/name).exists():
        shutil.copy2(ROOT/name, before/name)
    temporary = ROOT/(name+'.identity-tmp')
    shutil.copy2(source, temporary)
    temporary.replace(ROOT/name)
cache = ROOT/'official-player-movements-2017-2026'
for name in ('rename-plan.json', 'resolved-plan.json', 'number-plan.json'):
    if not (before/(name+'.gz')).exists():
        with gzip.open(before/(name+'.gz'), 'wb') as stream:
            stream.write((cache/name).read_bytes())
python = ROOT/'.venv/bin/python'
for name in ('plan_player_renames.py', 'plan_player_movement_numbers.py'):
    result = subprocess.run([str(python), str(ROOT/name)], cwd=ROOT, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(f'{name} failed: {result.stderr[-1500:]}')
renames = json.loads((cache/'rename-plan.json').read_text())
assert not any(r['player_id'] == 62349 for r in renames['updates']), 'MLB rename must be absent'
assert any(r['player_id'] == 67768 and r['oldname'] == '김병현' for r in renames['updates'])
resolved = json.loads((cache/'resolved-plan.json').read_text())
assert all(r['player_id'] == 67768 for r in resolved['rows'] if r['team'] == '한화' and r['player_name'] == '김병현' and 2017 <= r['year'] <= 2020)
# Refresh existing code manifest and derived-cache revision atomically.
manifest_path = cache/'code-manifest.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8-sig'))
for name in files:
    manifest[name] = hashlib.sha256((ROOT/name).read_bytes()).hexdigest()
manifest_path.write_text(json.dumps(manifest, indent=2), encoding='utf-8')
revision = Path('/tmp/wesiper-candle-data-revision')
temporary = revision.with_name(revision.name+'.identity-tmp')
temporary.write_text('identity-kim-62349-67768-'+str(time.time_ns()))
temporary.replace(revision)
print(json.dumps({'passed':True,'installed_files':files,'rename_plan_corrected':True,'movement_plan_corrected':True,'revision_refreshed':True}))
