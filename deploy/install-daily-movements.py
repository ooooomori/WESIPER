"""Add the daily movement job while preserving existing production jobs."""
import hashlib,os,shutil,subprocess
from pathlib import Path
root=Path('/home/bitnami/wesiper')
stage=root/'movement-enrichment-20261002'
runner=root/'run_daily_kbo.sh'
if hashlib.sha256(runner.read_bytes()).hexdigest()!='5651f01bff5fc52e8642b693bf00dd5784268b48680ae9b61eedb41b9bd72a2a':
    raise RuntimeError('Daily runner changed; review concurrent edits')
if subprocess.check_output(['timedatectl','show','-p','Timezone','--value'],text=True).strip()!='Asia/Seoul':raise RuntimeError('Cron timezone must be Asia/Seoul')
for filename in ['daily_movement_contracts.py','run_daily_kbo.sh','test_daily_movements.py']:
    target=root/filename
    if target.exists():shutil.copy2(target,stage/'before'/filename)
    source=(stage/filename).read_text().replace('\r\n','\n')
    temporary=root/(filename+'.next');temporary.write_text(source);os.chmod(temporary,0o755 if filename.endswith('.sh') else 0o644);os.replace(temporary,target)
subprocess.run(['bash','-n',str(runner)],check=True)
cron=subprocess.run(['crontab','-l'],capture_output=True,text=True)
if cron.returncode not in (0,1):raise RuntimeError('Cannot read cron')
before=cron.stdout
entry='30 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh movements'
lines=[line for line in before.splitlines() if not ('run_daily_kbo.sh movements' in line or 'Daily movement and contract enrichment' in line)]
lines+=['# Daily movement and contract enrichment at 02:30 KST.',entry]
after='\n'.join(lines)+'\n'
(stage/'before/crontab.before').write_text(before)
subprocess.run(['crontab','-'],input=after,text=True,check=True)
actual=subprocess.check_output(['crontab','-l'],text=True)
if actual!=after:raise RuntimeError('Installed cron differs')
print(actual)
