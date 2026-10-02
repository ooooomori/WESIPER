"""Incremental KBO movements, verified identities and fresh contract announcements."""
import argparse
from collections import Counter,defaultdict
from datetime import date,datetime,timedelta
from decimal import Decimal
from html.parser import HTMLParser
import gzip,hashlib,html,json,re
from pathlib import Path
from urllib.parse import urljoin
from zoneinfo import ZoneInfo
import requests
from backfill_kbo_early_official import connect
from collect_player_movements import clean,atomic,URL
from movement_identity import resolved_movement_id
from player_identity_corrections import kim_taeuk_id
from player_ingest import parse_profile,FIELDS as PLAYER_FIELDS

OFFICIAL='https://www.koreabaseball.com/Player/Trade.aspx'
NEWS='https://www.koreabaseball.com/MediaNews/News/KboPhoto/List.aspx'
KST=ZoneInfo('Asia/Seoul')
ROOT=Path(__file__).resolve().parent/'daily-movement-contracts'
ALIASES={'SK':'SSG','넥센':'키움','kt':'KT'}
def club(value):return ALIASES.get(value,value)
class Inputs(HTMLParser):
    def __init__(self,page):super().__init__();self.values={};self.feed(page)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and a.get('name') and a.get('type') in ('hidden','text'):self.values[a['name']]=a.get('value','')
def get(session,url):
    r=session.get(url,timeout=35);r.raise_for_status();r.encoding='utf-8-sig';return r.text
def cache_html(url,page):
    p=ROOT/'html'/(hashlib.sha256(url.encode()).hexdigest()+'.html.gz');p.parent.mkdir(parents=True,exist_ok=True)
    if not p.exists():
        with gzip.open(p,'wt',encoding='utf-8') as f:f.write(page)
    return str(p.relative_to(ROOT))
def official_rows(session,year):
    get(session,OFFICIAL)
    output=[];total=None;page=1;occurrences=Counter()
    while total is None or len(output)<total:
        r=session.post(URL,data=dict(seasonId=year,monthId=0,bdSc=0,teamName='',searchIf='',pageNo=page,listCount=100),timeout=35);r.raise_for_status()
        data=r.json();assert str(data['code'])=='100'
        n=int(data['totalCnt'])
        if total is not None and n!=total:raise ValueError('Official pagination changed during collection')
        total=n;assert data['rows'] or len(output)==total
        path=ROOT/'official'/date.today().isoformat()/f'{year}-{page}.json.gz';path.parent.mkdir(parents=True,exist_ok=True)
        with gzip.open(path,'wb') as f:f.write(r.content)
        for i,raw in enumerate(data['rows']):
            cells=[clean(c['Text']) for c in raw['row']];assert len(cells)==5
            day,kind,team,player,note=cells;assert day.startswith(str(year)+'-')
            key=json.dumps(cells,ensure_ascii=False);occurrences[key]+=1
            ids=re.findall(r'playerId=(\d+)',json.dumps(raw,ensure_ascii=False))
            output.append(dict(source_key=hashlib.sha256((key+'|'+str(occurrences[key])).encode()).hexdigest(),year=year,event_date=day,event_type=kind,team=team,player_id=int(ids[0]) if len(set(ids))==1 else None,player_text=player,player_name=re.sub(r'\([^)]*\)$','',player).strip(),note=note or None,old_back_no=None,new_back_no=None,source_url=OFFICIAL,source_file=str(path.relative_to(ROOT)),source_page=page,source_row=i+1,source_sha256=hashlib.sha256(r.content).hexdigest(),raw_json=json.dumps(raw,ensure_ascii=False,separators=(',',':'))))
        page+=1
    return output
def parent_index(connection):
    with connection.cursor() as c:
        c.execute('SELECT player_id,name,oldname,birth,pos,team,draft,backNo FROM kbo_player_data');cols=[d[0] for d in c.description];parents={int(r[0]):dict(zip(cols,r)) for r in c.fetchall()}
    names=defaultdict(set)
    for pid,p in parents.items():
        if pid<10000:continue
        for n in [p['name'],*re.split(r'[,/;·\s()]+',p['oldname'] or '')]:
            if n:names[n].add(pid)
    return parents,names
def discover_player(session,row,parents,names,connection,write):
    """Register a new player only after exact official name, team and profile checks."""
    r=session.post('https://www.koreabaseball.com/ws/Controls.asmx/GetSearchPlayer',data={'name':row['player_name']},timeout=35);r.raise_for_status()
    data=json.loads(r.content.decode('utf-8-sig'));assert str(data['code'])=='100'
    matches=[p for p in data.get('now',[]) if p['P_NM']==row['player_name'] and club(p['T_NM'])==club(row['team'])]
    if len(matches)!=1:return None
    candidate=matches[0];pid=int(candidate['P_ID'])
    if pid in parents:return pid
    url=f'https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId={pid}'
    page=get(session,url);cache_html(url,page);profile=parse_profile(page,pid,expected_name=row['player_name'])
    if club(profile['team'])!=club(row['team']):raise ValueError('New official player team mismatch')
    if write:
        with connection.cursor() as c:c.execute('INSERT INTO kbo_player_data (`'+'`,`'.join(PLAYER_FIELDS)+'`) VALUES ('+','.join(['%s']*len(PLAYER_FIELDS))+')',tuple(profile[f] for f in PLAYER_FIELDS))
    parents[pid]=dict(profile,oldname=None);names[profile['name']].add(pid)
    return pid
def identity(row,parents,names,connection):
    pid=resolved_movement_id(row)
    if pid is not None:return pid if pid in parents else None
    if row['player_text']=='신인(지명권)':return None
    pid=kim_taeuk_id(row['player_name'],row['team'],row['year'])
    if pid is not None:return pid if pid in parents else None
    choices=[]
    for pid in names.get(row['player_name'],set()):
        p=parents[pid];birth=p['birth'];draft=re.match(r'(\d{2}|\d{4})\s',p['draft'] or '')
        debut=int(draft[1]) if draft else None
        if debut is not None and debut<100:debut+=2000 if debut<50 else 1900
        if debut and debut>row['year']:continue
        if birth and row['year']-int(str(birth)[:4])<16:continue
        choices.append(pid)
    if len(choices)==1:return choices[0]
    # First use actual historical team membership, then official current profile.
    hits=set()
    if choices:
        marks=','.join(['%s']*len(choices))
        with connection.cursor() as c:
            for table in ('kbo_season_records','kbo_season_pitch_records'):
                c.execute(f'SELECT DISTINCT player_id,team FROM {table} WHERE player_id IN ({marks}) AND game_date BETWEEN %s AND %s',(*choices,f"{row['year']}-01-01",f"{row['year']}-12-31"))
                hits.update(int(pid) for pid,team in c.fetchall() if club(team)==club(row['team']))
    if len(hits)==1:return next(iter(hits))
    candidates=hits or set(choices)
    number=re.search(r'(\d+)번',row['note'] or '')
    if number:
        matched=[p for p in candidates if str(parents[p]['backNo'])==number[1]]
        if len(matched)==1:return matched[0]
    matched=[p for p in candidates if club(parents[p]['team'])==club(row['team'])]
    return matched[0] if len(matched)==1 else None
def amount(text):
    value=re.sub(r'[\s,원]','',text);total=0
    if '억' in value:
        high,value=value.split('억',1);total=int(Decimal(high)*100_000_000)
    if '만' in value:
        low=value.split('만')[0];total+=int(Decimal(low)*10_000)
    elif value:total+=int(Decimal(value))
    return total
def announcement(title,paragraphs,parents,names):
    if '계약' not in title or any(w in title for w in ['협상','전망','예상','취소','재조명','가능성']):return None
    title_names=[n for n in names if len(n)>=2 and n in title]
    if len(title_names)!=1:return None
    name=title_names[0]
    money=r'\d+(?:\.\d+)?\s*억(?:\s*\d+(?:\.\d+)?\s*만)?\s*원|\d+(?:,\d{3})*\s*만원'
    for paragraph in paragraphs[:4]:
        if name not in paragraph or len(paragraph)>700:continue
        if any(w in paragraph for w in ['지난해','지난 시즌','지난달','취소','협상 중','제안','예상']):continue
        if not re.search(r'계약.{0,12}(체결|맺|합의)|계약했|계약했다',paragraph):continue
        term=re.search(r'(?<!\d)(\d{1,2}(?:\s*년)?(?:\s*\+\s*\d{1,2}(?:\s*년)?){0,2})\s*년',paragraph)
        cash=re.search(r'(?:총액|최대|총)\s*('+money+')',paragraph)
        if not term or not cash:continue
        teams=[t for t in ['SSG','LG','KT','kt','KIA','NC','삼성','키움','롯데','두산','한화'] if t in paragraph[:paragraph.find(name)+20]]
        if len({club(t) for t in teams})!=1:continue
        team=club(teams[0]);choices=[p for p in names[name] if club(parents[p]['team'])==team]
        if len(choices)!=1:continue
        years=list(map(int,re.findall(r'\d+',term[1])));total=amount(cash[1]);assert 0<total<100_000_000_000
        return dict(player_id=choices[0],player_name=name,team=team,contract_years=sum(years),contract_term='+'.join(map(str,years))+'년',contract_total_amount=total,contract_registered_amount=None,contract_currency='KRW',contract_details=paragraph,event_type='비FA 다년계약' if re.search(r'비\s*FA|다년',paragraph+title) and not re.search(r'(?<!비)FA\s*계약',paragraph) else 'FA 계약')
    return None
def news_pages(session,today,lookback):
    page=get(session,NEWS);start=today-timedelta(days=lookback)
    prefix='ctl00$ctl00$ctl00$cphContents$cphContents$cphContents$'
    for number in range(1,31):
        form=Inputs(page).values
        target=prefix+'btnSearch' if number==1 else prefix+'ucPager$btnNext' if number%5==1 else prefix+'ucPager$btnNo'+str((number-1)%5+1)
        form.update({prefix+'hdStartDate':start.strftime('%Y%m%d'),prefix+'hdEndDate':today.strftime('%Y%m%d'),prefix+'txtDateStart':start.strftime('%Y.%m.%d'),prefix+'txtDateEnd':today.strftime('%Y.%m.%d'),prefix+'txtSearch':'계약',prefix+'ddlChoice':'REG_DT',prefix+'hfPage':str(number),'__EVENTTARGET':target,'__EVENTARGUMENT':''})
        r=session.post(NEWS,data=form,timeout=35);r.raise_for_status();r.encoding='utf-8-sig';page=r.text
        cache_html(NEWS+'?date='+today.isoformat()+'&page='+str(number),page)
        section=re.search(r'<ul class="photoList\b[^>]*>(.*?)</ul>',page,re.S)
        if section is None:raise ValueError('KBO contract search list layout changed')
        links=re.findall(r'<a\b[^>]*href="(View.aspx\?bdSe=\d+)"[^>]*>(.*?)</a>',section[1],re.S)
        if not links:return
        for href,body in links:
            title=re.search(r'<span class="txt">(.*?)</span>',body,re.S)
            if title:yield urljoin(NEWS,href),clean(title[1])
        if len(links)<30:return
    raise ValueError('Contract news search exceeds 30 pages; review pagination')
def run(write=False,lookback=14):
    today=datetime.now(KST).date();ROOT.mkdir(parents=True,exist_ok=True)
    session=requests.Session();session.headers.update({'User-Agent':'Mozilla/5.0','Referer':OFFICIAL,'X-Requested-With':'XMLHttpRequest'})
    con=connect();parents,names=parent_index(con);pending=[];statistics=Counter()
    required={'contract_years','contract_term','contract_total_amount','contract_registered_amount','contract_currency','contract_details','contract_source_url','contract_verified_at'}
    with con.cursor() as c:
        c.execute('SHOW COLUMNS FROM kbo_player_movements');assert required<={r[0] for r in c.fetchall()},'Contract schema missing'
    with con.cursor() as c:
        c.execute('SELECT source_key,id,player_id,player_name,event_date,team,event_type,contract_term,contract_total_amount FROM kbo_player_movements');cols=[d[0] for d in c.description];existing={r[0]:dict(zip(cols,r)) for r in c.fetchall()}
    try:
        rows=[]
        for year in sorted({today.year,(today-timedelta(days=lookback)).year}):rows.extend(official_rows(session,year))
        fields=['source_key','year','event_date','event_type','team','player_id','player_name','player_text','note','old_back_no','new_back_no','source_url','source_file','source_page','source_row','source_sha256','raw_json']
        for row in rows:
            if row['event_type']=='개명':continue
            old=existing.get(row['source_key'])
            if old and old['player_id'] is not None:continue
            row['player_id']=identity(row,parents,names,con)
            if row['player_id'] is None and row['player_text']!='신인(지명권)' and not names.get(row['player_name']):
                row['player_id']=discover_player(session,row,parents,names,con,write)
            if row['player_id'] is None and row['player_text']!='신인(지명권)':
                pending.append({'reason':'unresolved_player_identity','row':row});continue
            if row['event_type']=='등번호 변경':
                match=re.match(r'(\d+)\s*→\s*(\d+)',row['note'] or '')
                if match:row['old_back_no'],row['new_back_no']=match.groups()
            if old:
                if row['player_id'] is not None:
                    statistics['ids_filled']+=1
                    if write:
                        with con.cursor() as c:c.execute('UPDATE kbo_player_movements SET player_id=%s WHERE id=%s AND player_id IS NULL',(row['player_id'],old['id']))
            else:
                # A researched announcement may precede the official trade post.
                # Promote it to official provenance, keeping enriched contract fields.
                matches=[r for r in existing.values() if row['player_id'] is not None and r['player_id']==row['player_id'] and club(r['team'])==club(row['team']) and row['event_type'] in ('FA 계약','해외 복귀 FA 계약') and r['event_type'] in ('FA 계약','해외 복귀 FA 계약','자유계약') and not r['source_key'] in {x['source_key'] for x in rows} and abs((date.fromisoformat(str(r['event_date']))-date.fromisoformat(row['event_date'])).days)<32]
                if len(matches)>1:raise ValueError('Ambiguous official contract reconciliation')
                if matches:
                    old_contract=matches[0];statistics['announcements_promoted']+=1
                    if write:
                        with con.cursor() as c:
                            c.execute('UPDATE kbo_player_movements SET '+','.join('`'+f+'`=%s' for f in fields)+' WHERE id=%s AND source_key=%s',tuple(row[f] for f in fields)+(old_contract['id'],old_contract['source_key']));assert c.rowcount==1
                    existing.pop(old_contract['source_key']);old_contract.update(row);existing[row['source_key']]=old_contract
                    continue
                statistics['movements_added']+=1
                if write:
                    with con.cursor() as c:
                        c.execute('INSERT INTO kbo_player_movements (`'+'`,`'.join(fields)+'`) VALUES ('+','.join(['%s']*len(fields))+')',tuple(row[f] for f in fields));row['id']=c.lastrowid
                existing[row['source_key']]=row
        seen=set()
        for url,title in news_pages(session,today,lookback):
            if url in seen:continue
            seen.add(url);statistics['news_checked']+=1
            page=get(session,url);source_file=cache_html(url,page)
            content=re.search(r'<span\b[^>]*id="[^"]*lblContents"[^>]*>(.*?)</span>',page,re.S)
            if content is None:raise ValueError('Contract news body layout changed')
            paragraphs=[clean(p) for p in re.findall(r'<p\b[^>]*>(.*?)</p>',content[1],re.S) if clean(p)]
            contract=announcement(title,paragraphs,parents,names)
            if contract is None:
                pending.append({'reason':'no_unambiguous_current_announcement','source_url':url,'title':title});continue
            dates=re.findall(r'/((?:19|20)\d{6})\d*_',html.unescape(page))
            if not dates:
                pending.append({'reason':'announcement_date_missing','source_url':url});continue
            event=date.fromisoformat(dates[0][:4]+'-'+dates[0][4:6]+'-'+dates[0][6:8])
            if not start_date(today,lookback)<=event<=today:continue
            contract['event_date']=event.isoformat()
            matches=[r for r in existing.values() if r['player_id']==contract['player_id'] and club(r['team'])==contract['team'] and r['event_type'] in ('FA 계약','해외 복귀 FA 계약','비FA 다년계약','자유계약') and abs((date.fromisoformat(str(r['event_date']))-event).days)<32]
            if len(matches)>1:
                pending.append({'reason':'multiple_existing_contracts','contract':contract});continue
            if matches and matches[0].get('contract_total_amount') is not None:
                if matches[0]['contract_total_amount']!=contract['contract_total_amount'] or matches[0]['contract_term']!=contract['contract_term']:pending.append({'reason':'conflicting_contract','contract':contract})
                continue
            cf=['contract_years','contract_term','contract_total_amount','contract_registered_amount','contract_currency','contract_details','contract_source_url','contract_verified_at']
            contract.update(contract_source_url=url,contract_verified_at=datetime.now(ZoneInfo('UTC')).strftime('%Y-%m-%d %H:%M:%S'))
            if matches:
                statistics['contracts_filled']+=1
                if write:
                    with con.cursor() as c:c.execute('UPDATE kbo_player_movements SET '+','.join('`'+f+'`=%s' for f in cf)+' WHERE id=%s AND contract_total_amount IS NULL',tuple(contract[f] for f in cf)+(matches[0]['id'],))
                matches[0].update(contract)
            else:
                key=hashlib.sha256(f"researched-contract|{contract['player_id']}|{contract['team']}|{event}".encode()).hexdigest()
                raw=json.dumps({'title':title,'contract':contract},ensure_ascii=False,separators=(',',':'))
                row=dict(source_key=key,year=event.year,player_text=contract['player_name']+'('+parents[contract['player_id']]['pos']+')',note=contract['contract_details'],old_back_no=None,new_back_no=None,source_url=url,source_file=source_file,source_page=0,source_row=0,source_sha256=hashlib.sha256(page.encode()).hexdigest(),raw_json=raw,**contract)
                statistics['contracts_added']+=1
                if write:
                    with con.cursor() as c:c.execute('INSERT INTO kbo_player_movements (`'+'`,`'.join(fields+cf)+'`) VALUES ('+','.join(['%s']*len(fields+cf))+')',tuple(row[f] for f in fields+cf))
                existing[key]=row
        if write:con.commit()
        else:con.rollback()
    except Exception:con.rollback();raise
    finally:con.close()
    result={'date':today.isoformat(),'write':write,'counts':dict(statistics),'pending_review':pending,'source':'KBO Trade API and independently searched KBO news'}
    atomic(ROOT/'last-run.json',result);print(json.dumps({k:v for k,v in result.items() if k!='pending_review'},ensure_ascii=False));print('pending_review',len(pending))
def start_date(today,lookback):return today-timedelta(days=lookback)
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--write',action='store_true');p.add_argument('--lookback',type=int,default=14);a=p.parse_args();run(a.write,a.lookback)
