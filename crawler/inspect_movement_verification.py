import gzip,json
from collect_player_movements import ROOT
from import_player_movements import snapshot
from backfill_kbo_early_official import connect
with gzip.open(ROOT/'parent-full-before.json.gz','rt',encoding='utf-8') as f:before=json.load(f)
con=connect();after=snapshot(con);cols=before['columns'];ididx=cols.index('player_id');expected={r[ididx]:list(r) for r in before['rows']};actual={r[ididx]:r for r in after['rows']}
for item in json.loads((ROOT/'number-plan.json').read_text())['updates']:expected[item['player_id']][cols.index('backNo')]=item['after']
for item in json.loads((ROOT/'rename-plan.json').read_text())['updates']:
 expected[item['player_id']][cols.index('name')]=item['name'];expected[item['player_id']][cols.index('oldname')]=item['oldname']
diff=[]
for pid,values in expected.items():
 for index,(a,b) in enumerate(zip(values,actual[pid])):
  if a!=b:diff.append({'player_id':pid,'column':cols[index],'expected':a,'actual':b})
from collections import Counter
(ROOT/'external-parent-differences.json').write_text(json.dumps(diff,ensure_ascii=False,default=str),encoding='utf-8')
print(json.dumps({'difference_counts':dict(Counter(d['column'] for d in diff)),'samples':diff[:3]},ensure_ascii=False,default=str));con.close()
