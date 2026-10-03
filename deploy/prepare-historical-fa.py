"""Read the KBO annual FA table without inventing missing dates or terms."""
import hashlib
import json
import re
from collections import Counter
from pathlib import Path
import runpy

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / '.contract-enrichment'
BASE_URL = 'https://6ptotvmi5753.edge.naverncp.com/KBO_FILE/ebook/pdf/2026_%EC%97%B0%EA%B0%90.pdf'
money = runpy.run_path(str(ROOT/'deploy/prepare-movement-contracts.py'))['money']


def extract():
    rows = []
    unsigned = []
    for page in json.loads((CACHE/'official-tables-all.json').read_text(encoding='utf-8')):
        for table in page['tables']:
            if table[0] != ['연도','선수명','위치','계약팀','계약 기간','총액']:
                continue
            for cells in table[1:]:
                year = int(re.search(r'\d{4}',cells[0])[0])
                if year > 2017:
                    continue
                names,positions,teams,terms,_ = [(c or '').split('\n') for c in cells[1:]]
                amounts=[]
                for line in cells[5].split('\n'):
                    if re.match(r'^(?:각각\s*)?\d+(?:\.\d+)?(?:억|천|백|만)|^스플릿 계약|^계약 조건',line):
                        amounts.append(line)
                    else:
                        assert amounts, line
                        amounts[-1] += ' '+line
                assert len(names)==len(positions)==len(teams)
                n=0
                for name,position,team in zip(names,positions,teams):
                    if team=='미계약':
                        unsigned.append(dict(year=year,name=name));continue
                    term=None if (year==2007 and name=='이병규(74)') else terms[n]
                    amount=amounts[n];n+=1
                    rows.append(dict(year=year,name=re.sub(r'\([^)]*\)|\s+','',name),source_name=name,position=position.replace(' ',''),team=team.split(' → ')[-1],original_team=team,term=term,amount=amount,page=page['page']+1))
                assert n==len(amounts) and len(terms)==n-(year==2007),(year,n,len(terms),len(amounts))
    expected={2000:5,2001:6,2002:4,2003:4,2004:13,2005:11,2006:14,2007:10,2008:6,2009:11,2010:8,2011:2,2012:17,2013:11,2014:16,2015:19,2016:22,2017:14}
    assert dict(Counter(r['year'] for r in rows))==expected
    return rows,unsigned


def build():
    rows,unsigned=extract()
    parents=json.loads((CACHE/'players.json').read_text(encoding='utf-8'))
    existing=json.loads((CACHE/'movements.json').read_text(encoding='utf-8'))
    overrides=json.loads((CACHE/'historical-player-ids.json').read_text(encoding='utf-8')) if (CACHE/'historical-player-ids.json').exists() else {}
    plan=[];unresolved=[];duplicates=[]
    for r in rows:
        candidates=[p for p in parents if int(p['player_id'])>=10000 and (r['name']==p['name'] or r['name'] in re.split(r'[,/\s]+',p['oldname'] or ''))]
        suffix=re.search(r'\((\d{2})\)',r['source_name'])
        if suffix:candidates=[p for p in candidates if (p['birth'] or '')[2:4]==suffix[1]]
        candidates=[p for p in candidates if p['birth'] and int(p['birth'][:4])<=r['year']-25 and (('투수' in p['pos'])==('투수'==r['position']))]
        key=f"{r['year']}|{r['source_name']}|{r['team']}"
        override=overrides.get(key,overrides.get(r['name']))
        if override:candidates=[p for p in parents if int(p['player_id'])==override]
        if len(candidates)!=1:
            unresolved.append(dict(source=r,candidates=candidates));continue
        p=candidates[0];pid=int(p['player_id'])
        matches=[]
        for e in existing:
            if e['player_id']!=pid or e['team']!=r['team'] or e['event_type'] not in ('FA 계약','해외 복귀 FA 계약'):continue
            season=int(e['event_date'][:4])+(int(e['event_date'][5:7])>=10) if e['event_date'] else e['year']
            if season==r['year']:matches.append(e)
        if matches:
            assert len(matches)==1,(key,matches)
            duplicates.append(dict(source=r,existing_id=matches[0]['id']));continue
        currency='USD' if '달러' in r['amount'] else 'JPY' if '엔' in r['amount'] or '日' in r['team'] else 'KRW'
        if r['term'] is None:total=None
        elif r['name']=='황재균' and r['year']==2017:total=3_100_000
        else:
            text=r['amount'].replace('달러','').replace('엔','').removeprefix('각각 ')
            text=re.split(r'\+|\s*별도',text)[0]
            try:total=money(text)
            except Exception as e:raise ValueError(r) from e
            if r['year']==2006 and r['name']=='박재홍':total*=2
            # A yearly salary does not establish the complete contract total.
            if r['year']==2004 and r['name']=='이승엽':total=None
        details=f"KBO 2026 연감: {r['year']}년 FA 적용 연도. 계약 체결일 미기재. 구단 발표액 기준. 원문: {r['term'] or '기간 비공개'}, {r['amount']}"
        if r['original_team']!=r['team']:details+='; 이적: '+r['original_team']
        if r['name']=='황재균' and r['year']==2017:details+='; 스플릿 계약, MLB 기준 150만 달러+인센티브 최대 160만 달러.'
        if r['year']==2006 and r['name']=='박재홍':details+='; 2년씩 각각 15억원, 조건부 4년 합계 최대 30억원.'
        if r['year']==2004 and r['name']=='이승엽':details+='; 원문은 연봉 2억엔만 기재, 계약금 등 전체 총액 미기재로 총액 NULL. 인센티브 제외.'
        raw=json.dumps(r,ensure_ascii=False,separators=(',',':'))
        url=BASE_URL+'#page='+str(r['page'])
        plan.append(dict(source_key=hashlib.sha256(('kbo-yearbook-fa|'+str(r['year'])+'|'+str(pid)+'|'+r['team']).encode()).hexdigest(),year=r['year'],event_date=None,event_type='FA 계약',team=r['team'],player_id=pid,player_name=r['name'],player_text=r['name']+'('+r['position']+')',note=details,old_back_no=None,new_back_no=None,source_url=url,source_file='2026_연감.pdf',source_page=r['page'],source_row=len(plan)+1,source_sha256=hashlib.sha256((CACHE/'kbo-2026.pdf').read_bytes()).hexdigest(),raw_json=raw,contract_years=sum(map(int,re.findall(r'\d+',r['term']))) if r['term'] else None,contract_term=r['term'],contract_total_amount=total,contract_registered_amount=None,contract_currency=currency,contract_details=details,contract_source_url=url))
    output=dict(inserts=plan,duplicates=duplicates,unsigned=unsigned,unresolved=unresolved,source_rows=len(rows))
    (CACHE/'historical-fa-plan.json').write_text(json.dumps(output,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps({'rows':len(rows),'inserts':len(plan),'duplicates':len(duplicates),'unsigned':len(unsigned),'unresolved':[(u['source']['name'],u['source']['year'],[(p['player_id'],p['birth'],p['pos']) for p in u['candidates']]) for u in unresolved]},ensure_ascii=True))

if __name__=='__main__':build()
