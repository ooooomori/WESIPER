"""Add the daily fielding job while preserving existing production jobs.

Run on the server after uploading kbo_fielding_crawl.py and run_daily_kbo.sh to the stage directory:
python3 install-daily-fielding.py /home/bitnami/wesiper/<stage>
"""
import os,shutil,subprocess,sys
from pathlib import Path
root=Path('/home/bitnami/wesiper')
stage=Path(sys.argv[1])
runner=root/'run_daily_kbo.sh'
if subprocess.check_output(['timedatectl','show','-p','Timezone','--value'],text=True).strip()!='Asia/Seoul':raise RuntimeError('Cron timezone must be Asia/Seoul')
# The staged runner must differ from the installed one only by the fielding job.
staged=(stage/'run_daily_kbo.sh').read_text(encoding='utf-8').replace('\r\n','\n')
block='elif [[ "$job" == fielding ]]; then\n    if [[ $# == 0 ]]; then set -- --write; fi\n    "$python" "$root/kbo_fielding_crawl.py" --year "$(date +%Y)" "$@"\n'
if block not in staged:raise RuntimeError('Staged runner has no fielding job')
if runner.read_text(encoding='utf-8') not in (staged,staged.replace('movements|fielding','movements').replace(block,'')):
    raise RuntimeError('Installed daily runner differs from the staged one; review concurrent edits')
(stage/'before').mkdir(exist_ok=True)
for filename in ['kbo_fielding_crawl.py','run_daily_kbo.sh']:
    target=root/filename
    if target.exists():shutil.copy2(target,stage/'before'/filename)
    source=(stage/filename).read_text(encoding='utf-8').replace('\r\n','\n')
    temporary=root/(filename+'.next');temporary.write_text(source,encoding='utf-8');os.chmod(temporary,0o755 if filename.endswith('.sh') else 0o644);os.replace(temporary,target)
subprocess.run(['bash','-n',str(runner)],check=True)
cron=subprocess.run(['crontab','-l'],capture_output=True,text=True)
if cron.returncode not in (0,1):raise RuntimeError('Cannot read cron')
before=cron.stdout
entry='10 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh fielding'
lines=[line for line in before.splitlines() if not ('run_daily_kbo.sh fielding' in line or 'Daily fielding records' in line)]
lines+=['# Daily fielding records at 02:10 KST.',entry]
after='\n'.join(lines)+'\n'
(stage/'before/crontab.before').write_text(before)
subprocess.run(['crontab','-'],input=after,text=True,check=True)
actual=subprocess.check_output(['crontab','-l'],text=True)
if actual!=after:raise RuntimeError('Installed cron differs')
print(actual)
