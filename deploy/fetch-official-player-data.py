"""Read KBO history postbacks and official player searches without writes."""
import json
import argparse
import re
import runpy
import sys
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from lxml import html

ROOT = Path(__file__).resolve().parent.parent
CACHE = ROOT / '.player-history'
expanded = runpy.run_path(str(ROOT/'deploy/build-player-history.py'))['expanded']

def fetch(url,data=None):
    req = urllib.request.Request(urllib.parse.quote(url,safe=':/?=&%'),data=data,headers={'User-Agent':'Mozilla/5.0'})
    with urllib.request.urlopen(req,timeout=40) as response: return response.read()

def history():
    mapping={'22/W_CN':'다승','23/ERA_RT':'평균자책점','25/KK_CN':'탈삼진','28/RELIEF_W_CN':'세이브포인트','27/SV_CN':'세이브','13/HRA_RT':'타율','14/HIT_CN':'안타','15/HR_CN':'홈런','17/RBI_CN':'타점','16/RUN_CN':'득점','20/SB_CN':'도루','18/SLG_RT':'장타율','19/OBP_RT':'출루율'}
    tasks=[]
    for key,page in [('kbo_pitcher','Pitcher'),('kbo_hitter','Hitter')]:
        doc=html.fromstring((CACHE/(key+'.html')).read_bytes())
        select=doc.xpath('//select')[0]
        for option in select.xpath('./option'):
            tasks.append((page,doc,select.get('name'),option.get('value')))
    def one(task):
        page,doc,name,value=task
        path=CACHE/('official-'+mapping[value]+'.html')
        if not path.exists():
            fields={x.get('name'):x.get('value','') for x in doc.xpath('//input[@type="hidden"]')}
            fields.update({name:value,'__EVENTTARGET':name,'__EVENTARGUMENT':''})
            path.write_bytes(fetch('https://www.koreabaseball.com/Record/History/Player/'+page+'.aspx',urllib.parse.urlencode(fields).encode()))
        table=html.fromstring(path.read_bytes()).xpath('//table')[0]
        rows=expanded(table)
        print(mapping[value],rows[0],len(rows)-1,flush=True)
        return [{'type':mapping[value],'year':int(r[0]),'name':r[1],'team':r[2],'record':r[3] if mapping[value]=='다승' else r[-1]} for r in rows[1:] if len(r)>3 and re.fullmatch(r'\d{4}',r[0])]
    records=[]
    with ThreadPoolExecutor(max_workers=3) as pool:
        for result in pool.map(one,tasks): records.extend(result)
    (CACHE/'titles-official.json').write_text(json.dumps(records,ensure_ascii=False,indent=2),encoding='utf-8')

def roster():
    names=[x['name'] for x in json.loads((CACHE/'roster-extracted.json').read_text(encoding='utf-8'))]
    def one(name):
        path=CACHE/('search-'+name+'.html')
        if not path.exists():path.write_bytes(fetch('https://www.koreabaseball.com/Player/Search.aspx?searchWord='+name))
        doc=html.fromstring(path.read_bytes())
        result=[]
        for row in doc.xpath('//table//tr'):
            cells=[x.text_content().strip() for x in row.xpath('./td')]
            links=row.xpath('.//a/@href')
            if cells and links:
                pid=re.search(r'playerId=(\d+)',links[0])
                if pid:result.append({'player_id':int(pid[1]),'cells':cells,'url':urllib.parse.urljoin('https://www.koreabaseball.com',links[0])})
        print(name,[(x['player_id'],x['cells'][:5]) for x in result],flush=True)
        return name,result
    with ThreadPoolExecutor(max_workers=3) as pool: results=dict(pool.map(one,names))
    (CACHE/'roster-official-search.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')

def profiles():
    results=json.loads((CACHE/'roster-official-search.json').read_text(encoding='utf-8'))
    selected=[]
    for name,rows in results.items():
        players=[r for r in rows if r['cells'][1]==name and r['cells'][2]=='울산']
        if len(players)!=1:raise ValueError((name,players))
        selected.append(players[0])
    def one(player):
        pid=player['player_id'];path=CACHE/('profile-'+str(pid)+'.html')
        url='https://www.koreabaseball.com/Record/Player/PitcherDetail/Basic.aspx?playerId='+str(pid)
        if not path.exists():path.write_bytes(fetch(url))
        doc=html.fromstring(path.read_bytes())
        fields={}
        for li in doc.xpath('//li'):
            value=li.text_content().strip()
            if ':' in value and len(value)<180:
                label,content=value.split(':',1)
                if label in ['선수명','등번호','생년월일','포지션','신장/체중','경력','지명순위','입단년도']:fields[label]=content.strip()
        if fields.get('선수명')!=player['cells'][1]:raise ValueError((pid,fields))
        return pid,{**player,'profile':fields,'source':url}
    with ThreadPoolExecutor(max_workers=3) as pool: data=dict(pool.map(one,selected))
    (CACHE/'roster-profiles.json').write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')
    print('Official roster profiles',len(data),flush=True)

if __name__=='__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    parser=argparse.ArgumentParser();parser.add_argument('--history',action='store_true');parser.add_argument('--roster',action='store_true');parser.add_argument('--profiles',action='store_true');args=parser.parse_args()
    if args.history:history()
    if args.roster:roster()
    if args.profiles:profiles()
