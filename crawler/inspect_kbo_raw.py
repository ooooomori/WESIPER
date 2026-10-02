import gzip,json
from pathlib import Path
root=Path('/home/bitnami/wesiper/official-2001-2007')
for p in sorted((root/'2001').glob('schedule-s*-10.json.gz')):
    with gzip.open(p,'rt') as f:d=json.load(f)
    print(p.name,str(d)[:600])
p=root/'2001/20010405HTOB0-GetBoxScoreScroll.json'
d=json.loads(p.read_text())
for k in ('arrHitter','arrPitcher'):
    for item in d[k]:
        for label,value in item.items():
            t=json.loads(value)
            print(k,label,[[c['Text'] for c in r['row']] for r in t['rows']][-5:])
