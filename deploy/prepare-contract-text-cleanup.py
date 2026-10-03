"""Prepare text-only changes; contract values and source evidence stay intact."""
import json,re,sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CACHE=ROOT/'.contract-enrichment'
sys.path.insert(0,str(ROOT/'crawler'))
from contract_text import contract_text,normalize_term

updates=[]
for r in json.loads((CACHE/'movements.json').read_text(encoding='utf-8')):
    if not r['contract_details']:
        continue
    raw=json.loads(r['raw_json'])
    details=r['contract_details']
    if r['source_file']=='2026_연감.pdf':
        details=raw['amount']
    term=normalize_term(r['contract_term'])
    after=contract_text(term,r['contract_total_amount'],r['contract_currency'] or 'KRW',details)
    if r['source_file']=='2026_연감.pdf' and r['year']==2004 and r['player_id']==95436:
        after='2년 · 연봉 2억엔 (인센티브 제외)'
    assert not re.search(r'KBO|연감|미기재|원문|NULL|금액 기준|표기 기준|발표액 기준',after),(r['id'],after)
    note=r['note'] if re.fullmatch(r'[^→\s]+\s*→\s*[^→\s]+',r['note'] or '') else after
    if (after,note,term)!=(r['contract_details'],r['note'],r['contract_term']):
        updates.append(dict(id=r['id'],source_key=r['source_key'],before_details=r['contract_details'],before_note=r['note'],before_term=r['contract_term'],after_details=after,after_note=note,after_term=term))
(CACHE/'contract-text-cleanup-plan.json').write_text(json.dumps(updates,ensure_ascii=False,indent=2),encoding='utf-8')
(CACHE/'contract-text-cleanup-review.txt').write_text('\n'.join(str(r['id'])+' '+r['after_details'] for r in updates),encoding='utf-8')
print('text updates',len(updates))
