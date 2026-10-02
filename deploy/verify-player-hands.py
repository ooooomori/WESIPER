import json
import runpy
import urllib.request
from pathlib import Path

parse = runpy.run_path(str(Path(__file__).with_name('fetch-missing-player-profiles.py')))['parse']
for throws, bat in [('우투','우타'),('좌투','좌타'),('우언','우타'),('우사','우타'),('우투','양타')]:
    _, values = parse(f'<li>선수명: 선수</li><li>포지션: 투수({throws}{bat})</li>')
    assert values['throw'] == throws and values['bat'] == bat
for pid, name, throws, bat in [(62349,'김병현','우언','우타'),(67768,'김태욱','좌투','좌타')]:
    with urllib.request.urlopen(f'http://127.0.0.1:5173/api/playerProfile.php?pid={pid}&part=profile',timeout=30) as response:
        result = json.load(response)
    player = result.get('Player',result.get('player',result))
    assert player['Name'] == name and player['Throws'] == throws and player['Bat'] == bat, result
    print(json.dumps({'player_id':pid,'name':name,'throw':throws,'bat':bat,'api_verified':True},ensure_ascii=False))
for name in ['김병현','김태욱']:
    request = urllib.request.Request('http://127.0.0.1:5173/api/kbocandle/get_player_list.php',data=json.dumps({'name':name}).encode(),headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(request,timeout=30) as response:
        rows = json.load(response)
    assert any(str(row['PlayerId']) == ('62349' if name == '김병현' else '67768') and row['Name'] == name for row in rows), rows
    print(json.dumps({'search':name,'results':[{k:r.get(k) for k in ['PlayerId','Name','FormerTeam']} for r in rows]},ensure_ascii=False))
print('Five hand formats and both player APIs/searches verified')
