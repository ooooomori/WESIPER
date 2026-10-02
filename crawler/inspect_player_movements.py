import json
from collections import Counter
from collect_player_movements import ROOT
from backfill_kbo_early_official import connect
p=json.loads((ROOT/'plan.json').read_text());rows=p['rows']
print('types',dict(Counter(r['event_type'] for r in rows)))
numbers=[r for r in rows if r['event_type']=='등번호 변경']
print('number rows',len(numbers),'unparsed',sum(r['new_back_no'] is None for r in numbers))
for r in numbers[:15]:print({k:r[k] for k in ('event_date','team','player_text','note','player_id')})
print('all IDs',sum(r['player_id'] is not None for r in rows))
con=connect()
with con.cursor() as c:
 c.execute('SHOW COLUMNS FROM kbo_player_data');print('columns',[r[0] for r in c.fetchall()])
 c.execute('SELECT player_id,name,oldname,team,pos,backNo,is_kbodle FROM kbo_player_data');players=c.fetchall()
 (ROOT/'players-before.json').write_text(json.dumps(players,ensure_ascii=False),encoding='utf-8')
print('parent rows',len(players))
