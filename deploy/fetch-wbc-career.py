"""Read WBC tournament rosters from MLB's public Stats API, with player DOBs."""
import json
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
CACHE=ROOT/'.player-career'
def fetch(url,file):
 if file.exists():return json.loads(file.read_text())
 with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=45) as r:data=json.load(r)
 file.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8')
 return data
def roster(args):
 year,team=args
 url=f'https://statsapi.mlb.com/api/v1/teams/{team["id"]}/roster?season={year}&rosterType=fullSeason&hydrate=person'
 data=fetch(url,CACHE/f'wbc-roster-{year}-{team["id"]}.json')
 return [{'year':year,'country':team['name'],'source':url,**p['person']} for p in data.get('roster',[])]
def main():
 tasks=[]
 for year in [2006,2009,2013,2017,2023,2026]:
  teams=fetch(f'https://statsapi.mlb.com/api/v1/teams?sportId=51&season={year}',CACHE/f'wbc-teams-{year}.json')['teams']
  schedule=fetch(f'https://statsapi.mlb.com/api/v1/schedule?sportId=51&startDate={year}-03-01&endDate={year}-03-31',CACHE/f'wbc-schedule-{year}.json')
  games=[g for d in schedule['dates'] for g in d['games']]
  print('SCHEDULE',year,len(games),[(g['gameType'],g['teams']['away']['team']['name'],g['teams']['home']['team']['name']) for g in games[:3]],flush=True)
  tids={g['teams'][s]['team']['id'] for g in games if g['gameType'] in ['F','D','L','W'] for s in ['away','home']}
  teams=[t for t in teams if t['id'] in tids]
  print('WBC TEAMS',year,len(teams),[(t['id'],t['name']) for t in teams],flush=True)
  tasks.extend((year,t) for t in teams)
 rows=[]
 with ThreadPoolExecutor(max_workers=6) as pool:
  for result in pool.map(roster,tasks):rows.extend(result)
 (CACHE/'wbc-rosters.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2),encoding='utf-8')
 print('ROSTERS',len(rows),'DOB',sum('birthDate' in r for r in rows))
 print('BY YEAR',{y:sum(r['year']==y for r in rows) for y in [2006,2009,2013,2017,2023,2026]})
if __name__=='__main__':main()
