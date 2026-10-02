"""Read-only HTTP regression checks for overseas/Ulsan player states."""
import json
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent

def request(endpoint,body=None):
 data=None if body is None else json.dumps(body).encode()
 req=urllib.request.Request('https://wesiper.xyz/api/'+endpoint,data=data,headers={'Content-Type':'application/json'})
 with urllib.request.urlopen(req,timeout=30) as response:
  assert response.status==200
  return json.load(response)

def main():
 players=[]
 for filename in ['overseas-players.json','ulsan-roster.json']:
  players.extend(json.loads((ROOT/'data/player-history'/filename).read_text(encoding='utf-8')))
 for player in players:
  profile=request('playerProfile.php?pid='+str(player['player_id']))['player']
  assert int(profile['PlayerId'])==player['player_id'] and profile['Name']==player['name'] and profile['Team']==player['team'],(player,profile)
  custom=request('kbodle/get_custom_kbodle.php',{'p_no':player['player_id']})
  assert custom['success'] is False,(player,custom)
 print('PASS 47 overseas/Ulsan profiles and exclusion from KBO custom games')
 for name,team in [('이정후','키움'),('김하성','키움'),('김혜성','키움'),('송성문','키움'),('하재훈','울산')]:
  result=request('kbocandle/get_player_list.php',{'name':name})
  assert result and all(p['Team']==team for p in result if p['Name']==name),(name,result)
 print('PASS candle team status for overseas players and Ulsan Ha Jae-hoon')
 all_players=request('kbodle/get_player_list.php',{'keyword':''})['list']
 reclassified={p['player_id'] for p in players}
 assert not any(int(p['SporkId']) in reclassified for p in all_players)
 assert request('kbodle/get_today_kbodle.php')['success'] is True
 print('PASS KBO search filters and current daily answer')

if __name__=='__main__':main()
