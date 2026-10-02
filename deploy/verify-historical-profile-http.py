"""Check deployed profile APIs without writing baseball data."""
import json,requests
from pathlib import Path
results=[]
for pid,kind in ((20001,'batter'),(70122,'batter'),(70121,'pitcher'),(10082,'all'),(78168,'batter')):
    r=requests.get('https://wesiper.xyz/api/playerProfile.php',params={'pid':pid,'part':'year-records','type':kind},timeout=180)
    r.raise_for_status();data=r.json()
    if data.get('error'):raise AssertionError(data['error'])
    records=data['pitcher'] if kind=='all' else data
    years=[row['year'] for row in records['rows']]
    if pid!=78168 and not any(year<=2000 for year in years):raise AssertionError('historical years not served')
    if kind=='all':
        historical=[row for row in records['rows'] if row['year']<=2000 and row['stats']['innings']!='0']
        if any(row['stats']['fip'] is None or (row['stats']['er']>0 and row['stats']['eraPlus'] is None) for row in historical):raise AssertionError('historical pitcher metrics missing')
        post=data['seasons']['postseason']['pitcher']['rows']
        if not any(row.get('series') for row in post if row['year']<=2000):raise AssertionError('postseason stage details missing')
    results.append({'pid':pid,'type':kind,'http_status':r.status_code,'years':years})
r=requests.get('https://wesiper.xyz/api/playerProfile.php',params={'pid':20001},timeout=60);r.raise_for_status();data=r.json()
if data.get('error') or data['records']['stats'][1]!=['안타',2]:raise AssertionError('old-only overview incorrect')
r=requests.get('https://wesiper.xyz/api/playerProfile.php',params={'pid':95576,'part':'year-records','type':'batter'},timeout=60);r.raise_for_status();data=r.json()
if data['career']['obp']!='0.297' or data['career']['slg']!='0.266' or data['career']['ops']!='0.563':raise AssertionError('historical batting career rates incorrect')
if next(row for row in data['rows'] if row['year']==1982)['stats']['obp']!='0.331':raise AssertionError('legacy annual OBP incorrect')
for endpoint in ('todayGames.php','teamRank.php'):
    r=requests.get('https://wesiper.xyz/api/'+endpoint,timeout=30);r.raise_for_status();data=r.json()
    if isinstance(data,dict) and data.get('error'):raise AssertionError('existing API error')
Path('/home/bitnami/wesiper-season-api-release-20261001/http-verification.json').write_text(json.dumps({'passed':True,'fixtures':results},ensure_ascii=False),encoding='utf-8')
print(json.dumps({'passed':True,'profile_requests':7,'historical_rates':'ok','existing_api_health':'ok'}))
