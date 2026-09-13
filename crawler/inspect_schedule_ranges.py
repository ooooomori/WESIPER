"""Print season boundaries from cached game metadata without DB writes."""
import json
from collections import defaultdict
from pathlib import Path

root = Path('/home/bitnami/wesiper/backfill-2014-2017')
for year in range(2014, 2018):
    schedule = json.loads((root / f'schedule-{year}.json').read_text())
    groups = defaultdict(list)
    for gid, day in schedule.items():
        data = json.loads((root / str(year) / f'{gid}.json').read_text())
        info = data.get('result', {}).get('recordData', {}).get('gameInfo', {})
        groups[(str(info.get('gameFlag')), gid[:4] if int(gid[:4]) > 2100 else 'year')].append(day)
    print(year, [(key, min(days), max(days), len(days)) for key, days in groups.items()])
