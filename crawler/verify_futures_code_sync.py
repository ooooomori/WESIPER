"""Read-only local/server byte equality and compilation verification."""
import hashlib,json,py_compile
from pathlib import Path
from backfill_futures_history import ROOT,atomic
home=Path(__file__).resolve().parent
manifest=json.loads((ROOT/'futures-code-manifest.json').read_text(encoding='utf-8-sig'));failures=[]
for item in manifest:
 p=home/item['name']
 if not p.exists() or hashlib.sha256(p.read_bytes()).hexdigest()!=item['sha256']:failures.append(item['name'])
 elif p.suffix=='.py':py_compile.compile(str(p),doraise=True)
result={'files_checked':len(manifest),'mismatches':failures,'python_compilation_passed':not failures};atomic(ROOT/'code-sync-verification.json',result);print(json.dumps(result),flush=True)
if failures:raise ValueError('local/server file mismatch')
