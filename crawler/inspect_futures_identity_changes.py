import json
from collections import Counter
from backfill_futures_history import ROOT
from backfill_kbo_early_official import BATTER_FIELDS,PITCH_FIELDS
summary=Counter();games=Counter();examples=[]
for year in range(2010,2022):
 for path in (ROOT/str(year)/'identity-corrections').glob('*.json'):
  data=json.loads(path.read_text());games[year]+=1
  for key,fields in [('batter_rows',BATTER_FIELDS),('pitcher_rows',PITCH_FIELDS)]:
   old=data['old_plan'][key];new=data['new_plan'][key]
   if len(old)!=len(new):summary['row_count']+=1
   for a,b in zip(old,new):
    for f in fields:
     if a[f]!=b[f]:
      summary[f]+=1
      if len(examples)<12:examples.append({'game_id':path.stem,'field':f,'old':a[f],'new':b[f]})
print(json.dumps({'games_by_year':dict(games),'field_changes':dict(summary),'examples':examples},ensure_ascii=False))
