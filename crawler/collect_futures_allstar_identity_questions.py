import gzip,json
from backfill_futures_history import ROOT,atomic
from futures_history_parser import Resolver,parse
from recheck_futures_history_failures import api_page
from backfill_kbo_early_official import connect
class CollectingResolver(Resolver):
 def __init__(self,con):super().__init__(con);self.questions=[]
 def resolve(self,*args,**kwargs):
  try:return super().resolve(*args,**kwargs)
  except LookupError as e:
   self.questions.append(json.loads(str(e)));return -100000-len(self.questions)
con=connect();resolver=CollectingResolver(con);questions=[]
try:
 for year,gid in [(2017,'20170714FSFN0'),(2018,'20180713FNFS0')]:
  folder=ROOT/str(year);g=next(g for g in json.loads((folder/'games.json').read_text()) if g['game_id']==gid);decision=json.loads((folder/(gid+'-source.json')).read_text());page=gzip.open(folder/decision['path'],'rt',encoding='utf-8').read();resolver.questions=[]
  try:parse(g,page,decision['series'],resolver)
  except Exception as e:questions.append({'game_id':gid,'additional_source_error':str(e)})
  questions.extend(resolver.questions)
 atomic(ROOT/'allstar-player-identity-questions.json',questions)
 print(json.dumps(questions,ensure_ascii=False),flush=True)
finally:con.close()
