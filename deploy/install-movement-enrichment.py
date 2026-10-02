"""Install reviewed ID overrides and verify importer against enriched production rows."""
import hashlib
import os
import py_compile
import shutil
from pathlib import Path
root=Path('/home/bitnami/wesiper')
stage=root/'movement-enrichment-20261002'
target=root/'import_player_movements.py'
before=target.read_bytes()
if hashlib.sha256(before).hexdigest()!='6300c89733757fa4fdad197d2614ddbe32c2c8b6a9453e2fdfe1067f171a41fa':
    raise RuntimeError('Deployed movement importer changed')
for name in ['movement_identity.py','movement_identity_overrides.json']:
    if (root/name).exists():raise RuntimeError('Unexpected existing enrichment module')
# Store one compact evidence copy inside the task staging directory.
(stage/'before').mkdir(exist_ok=True)
shutil.copy2(target,stage/'before/import_player_movements.py')
for name in ['movement_identity.py','movement_identity_overrides.json','import_player_movements.py']:
    shutil.copy2(stage/name,root/(name+'.next'))
    os.replace(root/(name+'.next'),root/name)
for name in ['movement_identity.py','import_player_movements.py']:
    py_compile.compile(str(root/name),doraise=True)
print('Movement enrichment protection installed')
