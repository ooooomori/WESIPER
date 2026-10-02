import concurrent.futures
import html
import json
import re
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CACHE=ROOT/'.contract-enrichment'
def profile(pid):
    p=ROOT/'.player-profile-backfill/html'/f'{pid}.html'
    page=p.read_text(encoding='utf-8') if p.exists() else ''
    if '선수명:' not in re.sub(r'<[^>]*>','',page):
        p=CACHE/'profiles'/f'{pid}.html';p.parent.mkdir(exist_ok=True)
        if not p.exists():p.write_bytes(urllib.request.urlopen('https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId='+str(pid),timeout=30).read())
        page=p.read_text(encoding='utf-8-sig')
    fields={}
    for fragment in re.findall(r'<li\b[^>]*>(.*?)</li>',page,re.S|re.I):
        text=' '.join(html.unescape(re.sub(r'<[^>]*>',' ',fragment)).split())
        if ':' in text and len(text)<180:
            label,value=text.split(':',1);fields[label.strip()]=value.strip()
    return pid,fields
if __name__=='__main__':
    plan=json.loads((CACHE/'identity-plan.json').read_text(encoding='utf-8'))
    ids={int(p['player_id']) for r in plan['unresolved'] for p in r['candidates']}
    players=json.loads((CACHE/'players.json').read_text(encoding='utf-8'))
    ids.update(int(p['player_id']) for p in players if p['name']=='애디튼')
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        result=dict(pool.map(profile,sorted(ids)))
    (CACHE/'official-profile-fields.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
    for pid,fields in result.items():print(pid,json.dumps(fields,ensure_ascii=False))
