"""Collect official Basic profiles for empty cells; keep resumable source evidence."""
import argparse
import html
import json
import re
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import date
from pathlib import Path

FIELDS = ('bat', 'throw', 'draft', 'birth', 'body')
BASE = 'https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId='


def parse(page):
    labels = {}
    for fragment in re.findall(r'<li\b[^>]*>(.*?)</li>', page, re.I | re.S):
        text = ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', fragment)).split())
        if ':' in text and len(text) < 180:
            label, value = text.split(':', 1)
            labels[label.strip()] = value.strip()
    if not labels.get('선수명'):
        raise ValueError('Official player name missing')
    values = {}
    pos = re.search(r'([우좌양])(투|언|사)([우좌양])타', labels.get('포지션', ''))
    if pos:
        values.update(throw=pos[1] + pos[2], bat=pos[3] + '타')
    birth = re.fullmatch(r'(\d{4})년\s*(\d{1,2})월\s*(\d{1,2})일', labels.get('생년월일', ''))
    if birth:
        values['birth'] = date(*map(int, birth.groups())).isoformat()
    body = labels.get('신장/체중', '')
    if re.fullmatch(r'[1-9]\d{1,2}cm/[1-9]\d{1,2}kg', body):
        values['body'] = body
    draft = labels.get('지명순위', '').strip()
    if draft in ('-', '--'):
        draft = ''
    if not draft:
        draft = re.sub(r'^(\d{2,4})\s*(\S)', r'\1 \2', labels.get('입단년도', '').strip())
    if draft and draft not in ('-', '--'):
        values['draft'] = draft
    return labels, values


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, default=Path('.player-profile-backfill'))
    parser.add_argument('--workers', type=int, default=6)
    args = parser.parse_args()
    directory = args.directory
    cache = directory / 'html'
    cache.mkdir(parents=True, exist_ok=True)
    rows = json.loads((directory / 'missing.json').read_text(encoding='utf-8'))

    def one(row):
        pid = row['player_id']
        url = BASE + str(pid)
        path = cache / f'{pid}.html'
        for attempt in range(3):
            try:
                if path.exists():
                    page = path.read_text(encoding='utf-8')
                else:
                    request = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
                    with urllib.request.urlopen(request, timeout=35) as response:
                        page = response.read().decode('utf-8-sig')
                    path.write_text(page, encoding='utf-8')
                labels, values = parse(page)
                # Stable KBO IDs allow legal name changes; birth confirms a mismatch.
                names = {row['name'], row.get('oldname')}
                if labels['선수명'] not in names and row.get('birth') != values.get('birth'):
                    raise ValueError(f"Identity mismatch: {row['name']} / {labels['선수명']}")
                return dict(player_id=pid, original_name=row['name'], source_name=labels['선수명'], source=url,
                            draft_source='지명순위' if labels.get('지명순위') not in (None, '', '-', '--') else '입단년도',
                            values={f: values[f] for f in FIELDS if not str(row.get(f) or '').strip() and f in values},
                            unresolved=[f for f in FIELDS if not str(row.get(f) or '').strip() and f not in values])
            except ValueError as error:
                return dict(player_id=pid, original_name=row['name'], source=url, error=str(error))
            except Exception as error:
                if attempt == 2:
                    return dict(player_id=pid, original_name=row['name'], source=url, error=str(error))
                time.sleep(attempt + 1)

    results = []
    started = time.monotonic()
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [pool.submit(one, row) for row in rows]
        for future in as_completed(futures):
            results.append(future.result())
            if len(results) % 100 == 0:
                (directory / 'progress.json').write_text(json.dumps(results, ensure_ascii=False), encoding='utf-8')
                print(f'{len(results)}/{len(rows)} collected; {time.monotonic()-started:.0f}s', flush=True)
    results.sort(key=lambda r: r['player_id'])
    (directory / 'collected.json').write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding='utf-8')
    updates = [r for r in results if r.get('values')]
    (directory / 'updates.json').write_text(json.dumps(updates, ensure_ascii=False, indent=2), encoding='utf-8')
    summary = dict(players=len(rows), updated_players=len(updates), cells={f:sum(f in r.get('values', {}) for r in results) for f in FIELDS},
                   errors=[r for r in results if 'error' in r], unresolved_players=sum(bool(r.get('unresolved')) for r in results),
                   draft_fallback=sum(r.get('draft_source') == '입단년도' and 'draft' in r.get('values', {}) for r in results))
    (directory / 'summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({k:v if k != 'errors' else len(v) for k,v in summary.items()}, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    main()
