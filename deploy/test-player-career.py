"""Public, read-only career/foreign-name and existing API regression checks."""
import argparse,json,urllib.request,urllib.error,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
def request(base,endpoint,body=None,expected=200):
 data=None if body is None else json.dumps(body).encode()
 req=urllib.request.Request(base+'/api/'+endpoint,data=data,headers={'Content-Type':'application/json'})
 try:
  with urllib.request.urlopen(req,timeout=45) as r:status,raw=r.status,r.read()
 except urllib.error.HTTPError as e:status,raw=e.code,e.read()
 assert status==expected,(endpoint,status,raw[:300])
 return json.loads(raw)
def run(base):
 careers=json.loads((ROOT/'data/player-career/careers.json').read_text(encoding='utf-8'))
 selected=[71752,95436,84999,64914,69209,95103,78536,82303,94415,89220,99151,83147,67341,31012]
 for pid in selected:
  profile=request(base,'playerProfile.php?pid='+str(pid))
  assert int(profile['player']['PlayerId'])==pid
  expected=[{k:v for k,v in r.items() if k!='player_id'} for r in careers if r['player_id']==pid]
  actual=profile['career']
  for r in actual:
   for k in ['year','month']:
    if r[k] is not None:r[k]=int(r[k])
  canonical=lambda rows:sorted(json.dumps(r,ensure_ascii=False,sort_keys=True) for r in rows)
  assert canonical(actual)==canonical(expected),(pid,actual,expected)
  if pid==71752:
   assert sum(r['type']=='WBC' for r in actual)==4
   assert sum(r['type']=='올스타' for r in actual)>1
  if pid in [64914,69209]:assert int(profile['player']['IsForeign'])==1 and profile['player']['FullName']
  if pid==84999:assert profile['player']['IsForeign'] is None
 print('PASS 14 exact career profiles, repeated WBC/All-Star years and foreign registration criteria',flush=True)
 # Same registration name/fullname must return the same player through all search routes.
 for short,full,pid in [('테임즈','에릭 테임즈',64914),('페르난데스','호세 미겔 페르난데스',69209)]:
  result=request(base,'kbocandle/get_player_list.php',{'name':full})
  assert any(int(r['PlayerId'])==pid for r in result),(full,result)
  result=request(base,'kbobingo/search.php',{'keyword':full})
  player=next(r for r in result['list'] if int(r['SporkId'])==pid)
  assert player['Profile']['is_WBC']==any(r['player_id']==pid and r['type']=='WBC' for r in careers)
 print('PASS candle/bingo full-name searches and career-derived WBC flags',flush=True)
 assert request(base,'kbodle/get_player_list.php',{'keyword':'__career_probe__'})['success'] is True
 assert request(base,'kbodle/get_today_kbodle.php')['success'] is True
 assert request(base,'kbodle/get_roster.php')
 request(base,'playerProfile.php?pid=invalid',expected=400)
 request(base,'playerProfile.php?pid=9999999999',expected=404)
 print('PASS Kbodle roster/search/daily answer and profile error contracts',flush=True)
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--staged',action='store_true');parser.add_argument('--base',default='https://wesiper.xyz');args=parser.parse_args()
 if not args.staged:run(args.base);return
 files=list((ROOT/'backend/api').rglob('*.php'))+list((ROOT/'backend/lib').glob('*.php'))+[ROOT/'deploy/import-player-career.php']
 for f in files:
  r=subprocess.run(['/opt/bitnami/php/bin/php','-l',str(f)],capture_output=True,text=True)
  assert r.returncode==0,r.stdout+r.stderr
 print('PASS PHP syntax',len(files),'files',flush=True)
 with (ROOT/'career-stage-api.log').open('w') as log:
  server=subprocess.Popen(['sudo','-u','daemon','/opt/bitnami/php/bin/php','-d','display_errors=0','-S','127.0.0.1:18087','-t',str(ROOT/'backend')],stdout=log,stderr=log)
  try:
   for _ in range(30):
    try:request('http://127.0.0.1:18087','playerProfile.php?pid=invalid',expected=400);break
    except OSError:time.sleep(.1)
   run('http://127.0.0.1:18087')
  finally:
   server.terminate()
   try:server.wait(timeout=5)
   except subprocess.TimeoutExpired:server.kill();server.wait()
if __name__=='__main__':main()
