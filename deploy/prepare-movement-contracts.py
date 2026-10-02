"""Build a reviewable contract enrichment plan from official annual tables."""
import json
import re
from decimal import Decimal
from pathlib import Path
import hashlib
import runpy
from datetime import date

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / '.contract-enrichment'
OFFICIAL_URL = 'https://6ptotvmi5753.edge.naverncp.com/KBO_FILE/ebook/pdf/2026_%EC%97%B0%EA%B0%90.pdf'

def money(text):
    text = re.split(r'[,(]',text)[0].replace(' ','').replace('원','')
    total = 0
    if '억' in text:
        high,text = text.split('억',1)
        total += int(Decimal(high)*100_000_000)
    if '만' in text:
        low,text = text.split('만',1)
        subtotal = 0
        for number,unit in re.findall(r'(\d+(?:\.\d+)?)(천|백|십)?',low):
            subtotal += Decimal(number)*{'천':1000,'백':100,'십':10,'':1}[unit]
        total += int(subtotal*10_000)
    if text:
        total += int(Decimal(text))
    if total<=0:
        raise ValueError('Missing monetary amount')
    return total

def normalized_name(name):
    return re.sub(r'\([^)]*\)|\s+','',name)

def run():
    research=runpy.run_path(str(ROOT/'deploy/movement-contract-research.py'))
    official = []
    for page in json.loads((CACHE/'official-tables.json').read_text(encoding='utf-8')):
        for table in page['tables']:
            if table[0] != ['연도','선수명','위치','계약팀','계약 기간','총액']:
                continue
            for cells in table[1:]:
                year = int(re.search(r'\d{4}',cells[0])[0])
                if not 2018<=year<=2026:
                    continue
                columns=[(c or '').split('\n') for c in cells[1:]]
                names,positions,teams,terms,amounts=columns
                n=0
                for name,position,team in zip(names,positions,teams):
                    if team=='미계약':
                        continue
                    term,amount=terms[n],amounts[n];n+=1
                    official.append(dict(year=year,name=normalized_name(name),position=position,team=team,term=term,amount=amount,page=page['page']+1,source_url=OFFICIAL_URL))
                assert n==len(terms)==len(amounts),(year,page['page'],n,len(terms),len(amounts))
    (CACHE/'official-contracts.json').write_text(json.dumps(official,ensure_ascii=False,indent=2),encoding='utf-8')
    plan=[];unresolved=[]
    for item in json.loads((ROOT/'.movement-contracts.json').read_text(encoding='utf-8')):
        if item['event_type'] not in ['FA 계약','해외 복귀 FA 계약']:
            continue
        year=int(item['event_date'][:4])+(int(item['event_date'][5:7])>=10)
        options=[r for r in official if r['year']==year and r['name']==item['player_name'] and item['team'] in r['team'].split(' → ')]
        if len(options)!=1:
            unresolved.append({'movement':item,'candidates':options});continue
        source=options[0]
        term=source['term'].replace('년+','+')
        total=money(source['amount'])
        details='KBO 연감 계약서 금액 기준(2019년 이후). 연장 조건 및 인센티브는 별도 확정 지급액이 아니며 원문 표기 기준. 총액: '+source['amount'].split(',')[0]
        plan.append({'id':item['id'],'identity':{k:item[k] for k in ['event_date','event_type','team','player_id','player_name']},'contract_years':sum(map(int,re.findall(r'\d+',term))),'contract_term':term,'contract_total_amount':total,'contract_registered_amount':total,'contract_currency':'KRW','contract_details':details,'contract_source_url':OFFICIAL_URL+'#page='+str(source['page']),'evidence':source})
    remaining=[]
    for row in unresolved:
        item=row['movement'];override=research['MISSING_EXISTING'].get(int(item['id']))
        if not override:remaining.append(row);continue
        term,amount,url,details=override
        plan.append({'id':item['id'],'identity':{k:item[k] for k in ['event_date','event_type','team','player_id','player_name']},'contract_years':sum(map(int,re.findall(r'\d+',term))),'contract_term':term,'contract_total_amount':money(amount),'contract_registered_amount':None,'contract_currency':'KRW','contract_details':details,'contract_source_url':url,'evidence':{'term':term,'amount':amount,'source_url':url}})
    for row in plan:
        if int(row['id']) in research['OVERRIDES']:
            term,amount,url,details=research['OVERRIDES'][int(row['id'])]
            row.update(contract_years=sum(map(int,re.findall(r'\d+',term))),contract_term=term,contract_total_amount=money(amount),contract_details=details,contract_source_url=row['contract_source_url']+'\n'+url)
    parents={int(p['player_id']):p for p in json.loads((CACHE/'players.json').read_text(encoding='utf-8'))}
    ids={'문승원':62869,'박종훈':60841,'한유섬':62895,'구자욱':62404,'김광현':77829,'박세웅':64021,'구창모':65933,'이원석':75566,'김태군':78122,'최형우':72443,'김성현':76802,'고영표':64001,'김상수':76430,'류현진':76715,'최주환':76267,'김재현':62332,'김재환':78224,'이지영':79456,'김진성':75867,'노시환':69737,'서건창':78168,'하영민':64350,'홍건희':61643}
    existing=json.loads((CACHE/'movements.json').read_text(encoding='utf-8'))
    inserts=[]
    for name,club,event_date,term,amount,url,details in research['NEW']:
        pid=ids[name];p=parents[pid]
        assert name==p['name'] or name in (p['oldname'] or ''),(name,p)
        source_key=hashlib.sha256(f'researched-contract|{pid}|{club}|{event_date}'.encode()).hexdigest()
        duplicate=[r for r in existing if r['player_id']==pid and r['event_type'] in ['FA 계약','해외 복귀 FA 계약','비FA 다년계약','자유계약'] and abs((date.fromisoformat(r['event_date'])-date.fromisoformat(event_date)).days)<32 and r['source_key']!=source_key]
        assert not duplicate,(name,event_date,duplicate)
        kind='자유계약' if name=='홍건희' else '비FA 다년계약'
        raw={'player_id':pid,'player_name':name,'team':club,'event_date':event_date,'term':term,'amount':amount,'source_url':url,'details':details,'amount_basis':'announced_maximum_including_options'}
        raw_json=json.dumps(raw,ensure_ascii=False,separators=(',',':'))
        inserts.append({'source_key':source_key,'year':int(event_date[:4]),'event_date':event_date,'event_type':kind,'team':club,'player_id':pid,'player_name':name,'player_text':name+'('+p['pos']+')','note':details,'old_back_no':None,'new_back_no':None,'source_url':url,'source_file':'movement-contract-research.py','source_page':0,'source_row':len(inserts)+1,'source_sha256':hashlib.sha256(raw_json.encode()).hexdigest(),'raw_json':raw_json,'contract_years':sum(map(int,re.findall(r'\d+',term))),'contract_term':term,'contract_total_amount':money(amount),'contract_registered_amount':None,'contract_currency':'KRW','contract_details':details,'contract_source_url':url})
    assert len(plan)==160 and not remaining
    assert all(r['identity']['player_id'] is not None for r in plan)
    (CACHE/'plan.json').write_text(json.dumps({'updates':plan,'unresolved':remaining,'inserts':inserts},ensure_ascii=False,indent=2),encoding='utf-8')
    print('official contracts',len(official))
    print('Final existing contracts:',len(plan),'new contracts:',len(inserts),'unresolved:',len(remaining))

if __name__=='__main__':run()
