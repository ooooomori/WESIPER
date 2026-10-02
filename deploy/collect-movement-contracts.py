"""Collect annual FA reference tables, retaining HTML and candidate evidence."""
import concurrent.futures
import html
import json
import re
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / '.contract-enrichment'
CACHE.mkdir(exist_ok=True)

def plain(fragment):
    fragment = re.sub(r'<sup\b[^>]*>.*?</sup>', '', fragment, flags=re.S)
    return ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', fragment)).split())

def collect(year):
    url = 'https://www.namu.moe/w/' + urllib.parse.quote(f'KBO 리그/역대 FA/{year}')
    path = CACHE / f'fa-{year}.html'
    if not path.exists():
        path.write_bytes(urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent':'Mozilla/5.0'}),timeout=45).read())
    page = path.read_text(encoding='utf-8')
    rows = []
    for table in re.findall(r'<table\b[^>]*>(.*?)</table>', page, re.S):
        if '계약' not in plain(table)[:700]:
            continue
        for tr in re.findall(r'<tr\b[^>]*>(.*?)</tr>', table, re.S):
            cells = [plain(c) for c in re.findall(r'<t[dh]\b[^>]*>(.*?)</t[dh]>',tr,re.S)]
            if not cells or not any(re.search(r'\d.*(?:억|만 원|만원)',c) for c in cells):
                continue
            rows.append({'cells':cells,'source_url':url,'year':year})
    return rows

if __name__ == '__main__':
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        candidates = [row for rows in pool.map(collect, range(2018,2027)) for row in rows]
    (CACHE/'candidates.json').write_text(json.dumps(candidates,ensure_ascii=False,indent=2),encoding='utf-8')
    movements = json.loads((ROOT/'.movement-contracts.json').read_text(encoding='utf-8'))
    matches = []
    for movement in movements:
        if movement['event_type'] not in ['FA 계약','해외 복귀 FA 계약']:
            continue
        year = int(movement['event_date'][:4]) + (int(movement['event_date'][5:7]) >= 10)
        options = [r for r in candidates if r['year']==year and movement['player_name'] in r['cells'][:2]]
        matches.append({'movement':movement,'candidates':options})
        # Display one richest contract row, preferring a scale containing years and amount.
        best = sorted(options,key=lambda r: (any(re.search(r'\d(?:\+\d)?년.*\d.*억',c) for c in r['cells']),len(r['cells'])),reverse=True)
        print(movement['id'],year,movement['player_name'],json.dumps(best[0]['cells'] if best else [],ensure_ascii=False))
    (CACHE/'matches.json').write_text(json.dumps(matches,ensure_ascii=False,indent=2),encoding='utf-8')
