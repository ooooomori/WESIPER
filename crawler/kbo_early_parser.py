"""Official historical box tables converted to the established PA extractor.

Identity resolution deliberately refuses name/position scoring for namesakes.
"""
import gzip
import hashlib
import html
import json
import re
import time
from collections import defaultdict
from pathlib import Path

import requests
from kbo_candle_crawl import extract_baseball_data, extract_pitcher_data, _reached_base
from kbo_futures_crawl import PlayerResolver, parse_tables, row_values
from backfill_official_kbo_pitchers import validate_score, parse_pitchers

HEADERS={'User-Agent':'Mozilla/5.0','Referer':'https://www.koreabaseball.com/'}

def clean(v):return ' '.join(html.unescape(re.sub('<[^>]+>',' ',str(v or ''))).replace('\\r\\n',' ').split())
def decoded(v):return json.loads(v) if isinstance(v,str) else v
def rows(v):return [[clean(c.get('Text')) for c in r['row']] for r in decoded(v).get('rows',[])]

def cached_page(url,path,data=None,session=None):
    if path.exists():
        with gzip.open(path,'rt',encoding='utf-8') as f:return f.read()
    s=session or requests.Session()
    r=s.post(url,data=data,headers=HEADERS,timeout=40) if data is not None else s.get(url,headers=HEADERS,timeout=40)
    r.raise_for_status();r.encoding='utf-8'
    page=r.text
    if '/Error/' in r.url:raise ValueError('official error page: '+url)
    path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_suffix('.tmp')
    with gzip.open(tmp,'wt',encoding='utf-8') as f:f.write(page)
    tmp.replace(path);time.sleep(.1)
    return page

def attrs(fragment):return PlayerResolver.tag_attributes(fragment)

def daily(pid,year,role,sr,root):
    endpoint='PitcherDetail' if role=='pitcher' else 'HitterDetail'
    url=f'https://www.koreabaseball.com/Record/Player/{endpoint}/Daily.aspx?playerId={pid}'
    path=root/str(year)/'player-daily'/f'{pid}-{role}-s{sr}.html.gz'
    if path.exists():return cached_page(url,path)
    s=requests.Session()
    page=cached_page(url,root/str(year)/'player-daily'/f'{pid}-{role}-initial.html.gz',session=s)
    form={}
    for m in re.finditer(r'<input\b([^>]*)>',page,re.I|re.S):
        a=attrs(m[1])
        if a.get('type','').lower()=='hidden' and a.get('name'):form[a['name']]=a.get('value','')
    selects={}
    for m in re.finditer(r'<select\b([^>]*)>(.*?)</select>',page,re.I|re.S):
        a=attrs(m[1]);name=a.get('name')
        if not name:continue
        selected=re.search(r'<option\b[^>]*selected[^>]*value=[\'"]([^\'"]+)',m[2],re.I)
        form[name]=selected[1] if selected else ''
        selects[name]=m[2]
    year_name=next((n for n in selects if n.endswith('ddlYear')),None)
    series_name=next((n for n in selects if n.endswith('ddlSeries')),None)
    if not year_name or not re.search(rf'value=[\'"]{year}[\'"]',selects[year_name]):raise ValueError(f'official daily year not available: {pid}/{year}')
    form[year_name]=str(year)
    if series_name:form[series_name]=str(sr)
    form['__EVENTTARGET']=year_name;form['__EVENTARGUMENT']=''
    page=cached_page(url,path,data=form,session=s)
    if not re.search(rf'<h6>\s*{year}년?\s+일자별 성적',page):raise ValueError(f'official year switch failed: {pid}/{year}')
    return page

def daily_matches(page,g):
    target=g['game_date'][5:].replace('-','.')
    result=[]
    for t in parse_tables(page):
        for r in t['rows']:
            v=row_values(r)
            if v and v[0]==target:result.append(v)
    return result

class Resolver:
    def __init__(self,connection,root):
        self.root=root;self.by_name=defaultdict(dict);self.session=requests.Session();self.memory={}
        with connection.cursor() as c:
            c.execute('SELECT player_id,name,oldname,fullname,pos,team,birth FROM kbo_player_data WHERE player_id NOT BETWEEN 1000 AND 9999')
            columns=[v[0] for v in c.description]
            data=[dict(zip(columns,r)) for r in c.fetchall()]
            c.execute('SELECT p_no,p_name,p_oldname,p_pos,p_birth FROM kbo_playerlist_20250613 WHERE p_no NOT BETWEEN 1000 AND 9999')
            for pid,name,old,pos,birth in c.fetchall():data.append({'player_id':pid,'name':name,'oldname':old,'pos':pos,'birth':birth})
        for p in data:
            for k in ('name','oldname','fullname'):
                for name in re.split(r'[,/()]',str(p.get(k) or '')):
                    if name.strip():self.by_name[name.strip()][int(p['player_id'])]=p

    def search(self,name,year):
        path=self.root/str(year)/'player-search'/(hashlib.sha256(name.encode()).hexdigest()+'.html.gz')
        page=cached_page('https://www.koreabaseball.com/Player/Search.aspx?searchWord='+requests.utils.quote(name),path,session=self.session)
        found={}
        for r in re.findall(r'<tr\b[^>]*>(.*?)</tr>',page,re.I|re.S):
            pid=re.search(r'playerId=(\d+)',r,re.I)
            v=[clean(c) for c in re.findall(r'<td\b[^>]*>(.*?)</td>',r,re.I|re.S)]
            if not pid or len(v)<4 or 1000<=int(pid[1])<=9999:continue
            # Official search can return current legal names for an old alias.
            found[int(pid[1])]={'player_id':int(pid[1]),'name':v[1],'pos':v[3],'team':v[2]}
        return found

    def resolve(self,g,name,role,fragment=''):
        linked=re.findall(r'playerId=(\d+)',fragment,re.I)
        if linked:
            if len(set(linked))!=1:raise ValueError('conflicting official player links')
            return int(linked[0])
        year=int(g['game_date'][:4]);key=(year,g['series'],name,role)
        candidates=dict(self.by_name.get(name,{}))
        if not candidates:
            candidates=self.search(name,year)
            for pid,p in candidates.items():self.by_name[name][pid]=p
        excluded=set(g.get('_excluded_ids',[]))
        candidates={pid:p for pid,p in candidates.items() if pid not in excluded}
        if len(candidates)==1:return next(iter(candidates))
        if role=='pitcher':
            pitchers={pid:p for pid,p in candidates.items() if p.get('pos')=='투수'}
            if len(pitchers)==1:return next(iter(pitchers))
            if pitchers:candidates=pitchers
        # Every namesake must be tested; partial official evidence cannot veto one.
        matches=[];evidence=[]
        opponent=g['home_team'] if g['team']==g['away_team'] else g['away_team']
        for pid,p in candidates.items():
            try:
                page=daily(pid,year,role,g['series'],self.root)
                lines=[v for v in daily_matches(page,g) if len(v)>1 and v[1]==opponent]
                expected=g.get('_daily_stats')
                if expected:
                    if role=='batter':
                        lines=[v for v in lines if len(v)>10 and tuple(int(v[i]) for i in (4,6,10,5))==tuple(expected)]
                    else:
                        lines=[v for v in lines if len(v)>13 and (int(v[5]),v[6],int(v[12]),int(v[13]))==tuple(expected)]
                if lines:matches.append(pid)
                evidence.append({'player_id':pid,'matches':lines})
            except Exception as e:evidence.append({'player_id':pid,'error':str(e)})
        if len(matches)==1 and not any('error' in v for v in evidence):return matches[0]
        raise LookupError(json.dumps({'game_id':g['game_id'],'year':year,'team':g['team'],'name':name,'role':role,'candidates':sorted(candidates),'evidence':evidence},ensure_ascii=False))

def parse(g,score,box,resolver,root):
    if str(box.get('code'))!='100':raise ValueError('official box error '+str(box.get('msg')))
    scoreboard=validate_score(score,g['request_id'],g['game_date'],str(g['series']),(g['away_team'],g['home_team']))
    if (scoreboard['away_score'],scoreboard['home_score'])!=(g['away_score'],g['home_score']):raise ValueError('schedule/score mismatch')
    hitter_tables=box.get('arrHitter',[])
    if len(hitter_tables)!=2:raise ValueError('official hitter tables missing')
    record={'gameInfo':{'aName':g['away_team'],'hName':g['home_team']},'etcRecords':[{'how':v[0],'result':v[1]} for v in rows(box['tableEtc']) if len(v)==2],'battersBoxscore':{},'pitchersBoxscore':{}}
    identity_errors=[]
    for side,team,item in zip(('away','home'),(g['away_team'],g['home_team']),hitter_tables):
        lineup=rows(item['table1']);results=decoded(item['table2']);stats=rows(item['table3'])
        inn_headers=[clean(c['Text']) for c in results['headers'][0]['row']]
        if inn_headers!=[str(n) for n in range(1,len(inn_headers)+1)]:raise ValueError('invalid hitter inning headings')
        if not len(lineup)==len(results['rows'])==len(stats):raise ValueError('lineup/results/stats lengths differ')
        batters=[];seen=set()
        raw_lineup=decoded(item['table1'])['rows']
        for i,(v,result,stat) in enumerate(zip(lineup,results['rows'],stats)):
            if len(v)!=3 or len(stat)!=5:raise ValueError('invalid hitter table width')
            order=int(v[0]);starter=order not in seen;seen.add(order)
            # Normalize all substitution rows to the established 교 convention.
            b={'name':v[2],'batOrder':order,'pos':v[1] if starter else '교','rbi':int(stat[2]),'run':int(stat[3])}
            b['_raw_pos']=v[1];b['_daily_stats']=[int(stat[i]) for i in (0,1,2,3)]
            try:b['playerCode']=resolver.resolve({**g,'team':team,'_daily_stats':b['_daily_stats']} if '투' not in v[1] else {**g,'team':team},b['name'],'pitcher' if '투' in v[1] else 'batter',str(raw_lineup[i]))
            except LookupError as e:b['_identity_error']=str(e);b['playerCode']=None
            for inn,c in enumerate(result['row'],1):
                text=clean(c['Text']);b[f'inn{inn}']=re.sub(r'<br\s*/?>','/',str(c['Text']),flags=re.I) if text else ''
                b[f'inn{inn}']='/'.join(clean(x) for x in b[f'inn{inn}'].split('/'))
            batters.append(b)
        # A name can occur twice in one lineup (a pitcher and a pinch runner).
        # A positively resolved distinct roster row excludes that same ID from
        # the other row; compare official daily evidence for the remaining IDs.
        for b in batters:
            if b['playerCode'] is not None:continue
            excluded=[other['playerCode'] for other in batters if other is not b and other['name']==b['name'] and other['playerCode'] is not None]
            try:b['playerCode']=resolver.resolve({**g,'team':team,'_excluded_ids':excluded,'_daily_stats':b['_daily_stats']},b['name'],'batter')
            except LookupError as e:b['_identity_error']=str(e)
        record['battersBoxscore'][side]=batters
    missing_pitchers=None
    arrays=box.get('arrPitcher') or []
    if len(arrays)!=2 or any(not rows(item.get('table',{})) for item in arrays):
        missing_pitchers='official pitcher table missing'
    else:
        pitchers=parse_pitchers(box,scoreboard)
        for side,team,item in zip(('away','home'),(g['away_team'],g['home_team']),arrays):
            group=[]
            raw_rows=decoded(item['table'])['rows']
            for i,p in enumerate([p for p in pitchers if p['side']==side]):
                try:pid=resolver.resolve({**g,'team':team,'_daily_stats':[p['batters_faced'],p['inning'],p['r'],p['er']]},p['player_name'],'pitcher',str(raw_rows[i]))
                except LookupError as e:identity_errors.append(str(e));pid=None
                group.append({'name':p['player_name'],'pcode':pid,'inn':p['inning'],'pa':p['batters_faced'],'bf':p['pitched'],'er':p['er'],'r':p['r'],'wls':p['record']})
            record['pitchersBoxscore'][side]=group
    for side,lineup in record['battersBoxscore'].items():
        # Official lineup pitcher rows and pitching rows follow substitution /
        # appearance order. Use already resolved pitching identities for these
        # zero-PA rows, whose batting daily lines are otherwise identical.
        for name in {b['name'] for b in lineup if b['playerCode'] is None and '투' in b['_raw_pos']}:
            hitters=[b for b in lineup if b['name']==name and '투' in b['_raw_pos']]
            pitchers=[p for p in record['pitchersBoxscore'].get(side,[]) if p['name']==name]
            if len(hitters)==len(pitchers) and all(p['pcode'] for p in pitchers):
                for b,p in zip(hitters,pitchers):b['playerCode']=p['pcode']
        identity_errors.extend(b.get('_identity_error','unresolved lineup identity') for b in lineup if b['playerCode'] is None)
    if identity_errors:raise LookupError('\n'.join(identity_errors))
    # Historical notes retain legal names at game time while the official box
    # sometimes displays a later name (이승화/이우민, 유지현/류지현).
    # Match only via an already resolved stable ID, then keep the historical
    # note name in game rows. Current parent profile names remain unchanged.
    corrections=[]
    for note in record['etcRecords']:
        if note['how']=='심판':continue
        for name in re.findall(r'([가-힣A-Za-z.·]+)(?:\d+호)*\d*\s*\(',note['result']):
            ids=set(resolver.by_name.get(name,{}))
            if not ids:
                found=resolver.search(name,int(g['game_date'][:4]))
                resolver.by_name[name].update(found);ids=set(found)
            matches=[b for group in record['battersBoxscore'].values() for b in group if int(b['playerCode']) in ids]
            pitching=[p for group in record['pitchersBoxscore'].values() for p in group if int(p['pcode']) in ids]
            found_ids={int(b['playerCode']) for b in matches}|{int(p['pcode']) for p in pitching}
            if len(found_ids)==1:
                for entry in matches+pitching:
                    if entry['name']==name:continue
                    old=entry['name'];entry['name']=name
                    corrections.append({'field':'historical_player_name','player_id':next(iter(found_ids)),'from':old,'to':name,'evidence':note['how']})
                    for other in record['etcRecords']:
                        other['result']=re.sub(r'(?<![가-힣A-Za-z])'+re.escape(old)+r'(?=\d|\s*\()',name,other['result'])
    if g['request_id']=='20070902SSSK0':
        winning=next(n for n in record['etcRecords'] if n['how']=='결승타')
        hitter=next(b for b in record['battersBoxscore']['away'] if b['name']=='심정수')
        if (winning['result']=='심정수(8회 무사 1,3루서 좌전안타)'
                and hitter['inn7']=='좌안' and not hitter['inn8'] and hitter['inn9']=='우홈'
                and scoreboard['away_innings'][6]==2 and scoreboard['away_innings'][8]==1):
            corrections.append({'field':'winning_hit_inning','raw':8,'actual':7,'evidence':'official hitter inn7 single/inn8 empty/inn9 HR; official scoreboard 2 runs in 7th, 1 in 9th'})
            winning['result']=winning['result'].replace('(8회','(7회')
    # Explicit user decision for seven officially blank PA cells. Keep source
    # responses untouched and retain provenance separately from the saved value.
    override_path=Path(__file__).with_name('kbo_early_missing_pa_overrides.json')
    overrides=json.loads(override_path.read_text(encoding='utf-8')) if override_path.exists() else {}
    override=overrides.get(g['game_id'])
    if override:
        side='away' if g['away_team']==override['team'] else 'home' if g['home_team']==override['team'] else None
        if side is None:raise ValueError('manual missing-PA team mismatch')
        matches=[b for b in record['battersBoxscore'][side] if b['name']==override['name'] and b['batOrder']==override['order']]
        if len(matches)!=1:raise ValueError('manual missing-PA player/order mismatch')
        key=f"inn{override['inning']}"
        if matches[0].get(key):raise ValueError('manual missing-PA override refuses nonblank official result')
        if override['pa_result']!='아웃':raise ValueError('unexpected authorized missing-PA value')
        matches[0][key]=override['pa_result']
        corrections.append({'field':'pa_result','raw':None,'stored':'아웃',
                            'player_id':int(matches[0]['playerCode']),'name':override['name'],
                            'team':override['team'],'inning':override['inning'],'order':override['order'],
                            'rule':'user explicitly authorized 아웃 for this officially missing PA'})
    payload={'result':{'recordData':record}}
    batters=extract_baseball_data(payload,g['game_id'],include_pitcher_matchups=not missing_pitchers)
    pitch=[] if missing_pitchers else extract_pitcher_data(payload,g['game_id'])
    if not missing_pitchers:
        for side,team in (('away',g['away_team']),('home',g['home_team'])):
            opposite='home' if side=='away' else 'away'
            faced=sum(int(p['pa']) for p in record['pitchersBoxscore'][opposite])
            actual=sum(1 for b in batters if b['team']==team and b['pa_result'] is not None)
            if actual!=faced:
                raise ValueError(f'{g["game_id"]}: official PA result missing: {team} visible={actual}, opponent BF={faced}; require official event evidence')
    # The shared extractor supplies running totals. Reassign each run-out to a
    # verified on-base PA rather than simply the first PA of a batting-around inning.
    flagged=defaultdict(int)
    for b in batters:
        if b['run_out']:flagged[(b['team'],b['player_id'],b['inning'])]+=b['run_out'];b['run_out']=0
    for (team,pid,inning),count in flagged.items():
        candidates=[b for b in batters if b['team']==team and b['player_id']==pid and b['inning']==inning and b['pa_result'] and _reached_base(b['pa_result']) and '홈' not in b['pa_result']]
        actual=[b for b in batters if b['team']==team and b['player_id']==pid and b['inning']==inning and b['pa_result']]
        # Compact ground-ball codes include fielder's choices. The official
        # run-out note plus a sole PA proves which event put this runner on base.
        if not candidates and len(actual)==1:candidates=actual
        if len(candidates)==1:candidates[0]['run_out']=count
        elif not candidates:
            nonpa=[b for b in batters if b['team']==team and b['player_id']==pid and b['inning']==inning and b['pa_result'] is None]
            if len(nonpa)!=1:raise ValueError(f'{g["game_id"]}: no unique run-out non-PA {pid}/{inning}')
            nonpa[0]['run_out']=count
        else:
            # User authorized assigning an ambiguous historical run-out to
            # the first reaching PA because official live text is unavailable.
            first=min(candidates,key=lambda b:b['batting_index'])
            first['run_out']=count
            corrections.append({'field':'run_out','player_id':pid,'inning':inning,
                'batting_index':first['batting_index'],'rule':'user-authorized first reaching PA',
                'candidates':[(b['batting_index'],b['pa_result']) for b in candidates]})
    for b in batters+pitch:b.update(league_level=1,game_id=g['game_id'],game_date=g['game_date'])
    return {'game':g,'batter_rows':batters,'pitcher_rows':pitch,'away_innings':scoreboard['away_innings'],'home_innings':scoreboard['home_innings'],'pitcher_source_issue':missing_pitchers,'source_corrections':corrections}
