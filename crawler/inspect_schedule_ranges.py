"""Print season boundaries from cached game metadata without DB writes."""
import json
import argparse
from collections import defaultdict
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--start-year', type=int, default=2014)
parser.add_argument('--end-year', type=int, default=2017)
args = parser.parse_args()
root = Path(f'/home/bitnami/wesiper/backfill-{args.start_year}-{args.end_year}')
for year in range(args.start_year, args.end_year + 1):
    schedule = json.loads((root / f'schedule-{year}.json').read_text())
    groups = defaultdict(list)
    for gid, day in schedule.items():
        data = json.loads((root / str(year) / f'{gid}.json').read_text())
        info = data.get('result', {}).get('recordData', {}).get('gameInfo', {})
        groups[(str(info.get('gameFlag')), gid[:4] if int(gid[:4]) > 2100 else 'year')].append(day)
    print(year, [(key, min(days), max(days), len(days)) for key, days in groups.items()])
