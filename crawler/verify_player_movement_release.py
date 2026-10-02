"""Check deployed scripts and API health; never display private settings."""
import hashlib,json
from pathlib import Path
import requests
from collect_player_movements import ROOT,atomic
files=json.loads((ROOT/'code-manifest.json').read_text(encoding='utf-8-sig'))
for name,digest in files.items():
 path=Path('/home/bitnami/wesiper')/name;assert hashlib.sha256(path.read_bytes()).hexdigest()==digest;compile(path.read_text(encoding='utf-8'),str(path),'exec')
health={}
for endpoint in ('todayGames.php','teamRank.php','playerProfile.php?pid=65948&part=profile','playerProfile.php?pid=61145&part=profile'):
 r=requests.get('https://wesiper.xyz/api/'+endpoint,timeout=45);r.raise_for_status();data=r.json();assert not (isinstance(data,dict) and data.get('error'));health[endpoint]={'status':r.status_code,'valid_json':True}
 if endpoint.startswith('playerProfile'):
  # Profile payload may be wrapped by API part; persist only public results.
  atomic(ROOT/('profile-'+('65948' if '65948' in endpoint else '61145')+'-verification.json'),data)
atomic(ROOT/'release-verification.json',{'passed':True,'code_files':list(files),'api_health':health});print(json.dumps({'passed':True,'code_files':len(files),'api_health':health},ensure_ascii=False))
