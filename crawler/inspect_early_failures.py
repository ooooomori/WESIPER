import gzip,json
from backfill_kbo_early_official import ROOT
from kbo_early_parser import rows
for year in (2003,2004,2005,2006,2007):
 path=ROOT/str(year)/'dry-failures.json'
 if not path.exists():path=ROOT/str(year)/'dry-progress.json'
 if not path.exists():continue
 data=json.loads(path.read_text());failures=data if isinstance(data,list) else data['failures']
 print('YEAR',year,'FAILURES',len(failures))
 for fail in failures[:8]:
  g=fail['game'];print(g['game_id'],fail['error'])
  p=ROOT/str(year)/f"s{g['series']}-{g['request_id']}-GetBoxScoreScroll.json.gz"
  with gzip.open(p,'rt') as f:d=json.load(f)
  notes=rows(d['tableEtc']);print('NOTES',notes)
  names=[]
  for k,v in notes:
   if k=='주루사':names=v
  for item in d['arrHitter']:
   for l,r,stat in zip(rows(item['table1']),rows(item['table2']),rows(item['table3'])):
    if l[2] in names or 'winning hitter' in fail['error']:print('RUNNER',l,r,stat)
