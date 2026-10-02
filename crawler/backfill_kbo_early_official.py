"""Resumable official-only KBO first-team backfill, strictly 2001--2007.

Raw sources, discovery manifests, validated plans and write receipts are separate.
No daily crawler entry point or existing-season row is rewritten.
"""
import argparse
import concurrent.futures
import gzip
import html
import json
import os
import re
import time
from collections import defaultdict
from pathlib import Path

import pymysql
import requests

ROOT=Path('/home/bitnami/wesiper/official-2001-2007')
API='https://www.koreabaseball.com/ws/Schedule.asmx/'
HEADERS={'User-Agent':'Mozilla/5.0','Referer':'https://www.koreabaseball.com/','Origin':'https://www.koreabaseball.com','X-Requested-With':'XMLHttpRequest'}
SERIES=(0,1,3,5,7,6,9)

def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_suffix(path.suffix+'.tmp')
    tmp.write_text(json.dumps(data,ensure_ascii=False,default=str),encoding='utf-8')
    tmp.replace(path)

def raw(method,params,path):
    if path.exists():
        with gzip.open(path,'rt',encoding='utf-8') as f:return json.load(f)
    for attempt in range(4):
        try:
            r=requests.post(API+method,data=params,headers=HEADERS,timeout=40)
            r.raise_for_status()
            p=r.json()
            path.parent.mkdir(parents=True,exist_ok=True)
            tmp=path.with_suffix('.tmp')
            with gzip.open(tmp,'wt',encoding='utf-8') as f:json.dump(p,f,ensure_ascii=False)
            tmp.replace(path)
            time.sleep(.15)
            return p
        except (requests.RequestException,ValueError):
            if attempt==3:raise
            time.sleep(2**attempt)

def clean(v):return ' '.join(html.unescape(re.sub('<[^>]+>',' ',str(v or ''))).replace('\\r\\n',' ').split())

def table(v):
    p=json.loads(v) if isinstance(v,str) else v
    return [[clean(c.get('Text')) for c in r['row']] for r in p.get('rows',[])]

def discover(year):
    manifest=ROOT/str(year)/'games.json'
    if manifest.exists():return json.loads(manifest.read_text())
    found={}
    errors=[]
    for sr in SERIES:
        for month in range(1,13):
            try:
                p=raw('GetScheduleList',{'leId':1,'srIdList':str(sr),'seasonId':year,'gameMonth':f'{month:02}','teamId':''},ROOT/str(year)/f'schedule-s{sr}-{month:02}.json.gz')
                day=None
                for entry in p['rows']:
                    cells=entry['row']
                    d=next((c for c in cells if c.get('Class')=='day'),None)
                    if d:
                        m=re.search(r'(\d{2})\.(\d{2})',clean(d['Text']))
                        if not m:raise ValueError('bad schedule date')
                        day=f'{year}-{m[1]}-{m[2]}'
                    i=next((i for i,c in enumerate(cells) if c.get('Class')=='play'),None)
                    if i is None:continue
                    joined=' '.join(c.get('Text') or '' for c in cells)
                    if '취소' in clean(joined):continue
                    parts=[clean(v) for v in re.findall(r'<span\b[^>]*>(.*?)</span>',cells[i]['Text'],re.S)]
                    if len(parts)!=5:continue
                    codes=set(re.findall(r'gameId=([A-Z0-9]+)',html.unescape(joined),re.I))
                    if len(codes)!=1:raise ValueError(f'missing/ambiguous schedule code {day} {parts}')
                    request_id=next(iter(codes)).upper()
                    if not re.fullmatch(r'\d{8}[A-Z]{4}\d',request_id):raise ValueError('bad game ID '+request_id)
                    request_id=str(year)+request_id[4:]
                    db_id=(str(sr)*4+request_id[4:]) if sr in (1,3,5,7) else request_id
                    g={'request_id':request_id,'game_id':db_id,'game_date':day,'series':sr,'away_team':parts[0],'home_team':parts[-1],'away_score':int(parts[1]),'home_score':int(parts[3]),'tv':clean(cells[i+3]['Text']) or None,'stadium':clean(cells[i+5]['Text']),'is_allstar':int(sr in (6,9))}
                    key=f'{sr}-{request_id}'
                    if key in found:raise ValueError('duplicate schedule '+key)
                    found[key]=g
            except Exception as e:errors.append({'series':sr,'month':month,'error':str(e)})
        print(f'{year} schedule s{sr}: {len(found)} games',flush=True)
    atomic(ROOT/str(year)/'schedule-failures.json',errors)
    if errors:raise ValueError(f'{year}: {len(errors)} discovery failures')
    games=sorted(found.values(),key=lambda g:(g['game_date'],g['series'],g['request_id']))
    if len({g['game_id'] for g in games})!=len(games):raise ValueError('DB code collision')
    atomic(manifest,games)
    return games

def fetch_game(g):
    params={'leId':1,'srId':g['series'],'seasonId':g['game_date'][:4],'gameId':g['request_id']}
    folder=ROOT/g['game_date'][:4]
    key=f"s{g['series']}-{g['request_id']}"
    for method in ('GetScoreBoardScroll','GetBoxScoreScroll'):
        raw(method,params,folder/f'{key}-{method}.json.gz')
    return g['game_id']

def collect(year):
    games=discover(year)
    failures=[]
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        futures={pool.submit(fetch_game,g):g for g in games}
        for n,f in enumerate(concurrent.futures.as_completed(futures),1):
            try:f.result()
            except Exception as e:failures.append({'game':futures[f],'error':str(e)})
            if n%50==0 or n==len(games):
                atomic(ROOT/str(year)/'collection-progress.json',{'completed':n,'total':len(games),'failures':failures})
                print(f'{year} raw {n}/{len(games)}, failures={len(failures)}',flush=True)
    atomic(ROOT/str(year)/'collection-failures.json',failures)

def connect():
    return pymysql.connect(host=os.environ['DB_HOST'],port=int(os.getenv('DB_PORT','3306')),user=os.environ['DB_USER'],password=os.environ['DB_PASSWORD'],database=os.environ['DB_NAME'],charset='utf8mb4',connect_timeout=15,read_timeout=900)

def load_raw(g,method):
    path=ROOT/g['game_date'][:4]/f"s{g['series']}-{g['request_id']}-{method}.json.gz"
    with gzip.open(path,'rt',encoding='utf-8') as f:return json.load(f)

def validate_plan(item):
    g=item['game'];b=item['batter_rows'];p=item['pitcher_rows']
    if not (2001<=int(g['game_date'][:4])<=2007):raise ValueError('outside authorized range')
    if not b:raise ValueError('empty batter plan')
    for row in b+p:
        if row['league_level']!=1 or row['game_id']!=g['game_id'] or row['game_date']!=g['game_date']:raise ValueError('plan scope mismatch')
        if not row['player_id'] or int(row['player_id'])<=0:raise ValueError('missing player ID')
    for team in (g['away_team'],g['home_team']):
        indexes=[r['batting_index'] for r in b if r['team']==team and r['pa_result']]
        if sorted(indexes)!=list(range(1,len(indexes)+1)):raise ValueError('noncontinuous batting indices')
        if not item.get('pitcher_source_issue'):
            source=load_raw(g,'GetBoxScoreScroll')
            side=0 if team==g['away_team'] else 1
            faced=sum(int(row['row'][7]['Text']) for row in json.loads(source['arrPitcher'][1-side]['table'])['rows'])
            if len(indexes)!=faced:raise ValueError(f'{g["game_id"]}: visible PA {len(indexes)} differs from official BF {faced} for {team}')
    for row in b:
        if (row['pitcher_id'] is None)!=(row['pitcher_name'] is None):raise ValueError('partial pitcher identity')
        if any(row[k] is None for k in ('sb','cs','run_out')):raise ValueError('null running counter')
    keys=[(r['team'],r['player_id']) for r in p]
    if len(keys)!=len(set(keys)):raise ValueError('duplicate pitchers')

def dry(year,resolver,game_id=None,retry=False):
    from kbo_early_parser import parse
    games=discover(year);failures=[];passed=[]
    if game_id:games=[g for g in games if g['game_id']==game_id or g['request_id']==game_id]
    previous_path=ROOT/str(year)/'dry-failures.json'
    previous=json.loads(previous_path.read_text()) if previous_path.exists() else []
    if retry:
        ids={v['game']['game_id'] for v in previous}
        games=[g for g in games if g['game_id'] in ids]
    for n,g in enumerate(games,1):
        path=ROOT/str(year)/'plans'/f"{g['game_id']}.json"
        try:
            if path.exists() and not game_id and not retry:
                item=json.loads(path.read_text());validate_plan(item)
            else:
                item=parse(g,load_raw(g,'GetScoreBoardScroll'),load_raw(g,'GetBoxScoreScroll'),resolver,ROOT)
                validate_plan(item);atomic(path,item)
            passed.append(item)
        except Exception as e:
            failures.append({'game':g,'type':type(e).__name__,'error':str(e)})
        if n%25==0 or n==len(games):
            atomic(ROOT/str(year)/'dry-progress.json',{'completed':n,'total':len(games),'passed':len(passed),'failures':failures})
            print(f'{year} dry {n}/{len(games)}, passed={len(passed)}, failures={len(failures)}',flush=True)
    if game_id or retry:
        attempted={g['game_id'] for g in games}
        failures=[v for v in previous if v['game']['game_id'] not in attempted]+failures
    atomic(previous_path,failures)
    return passed,failures

def protected_snapshot(connection,baseline=None):
    output={}
    with connection.cursor() as c:
        for t,pk in (('kbo_season_records','PK'),('kbo_season_pitch_records','id'),('kbo_schedule',None)):
            cutoff=baseline[t]['cutoff'] if baseline else None
            if pk and cutoff is None:
                c.execute(f'SELECT COALESCE(MAX(`{pk}`),0) FROM `{t}`');cutoff=c.fetchone()[0]
            params=()
            restriction=''
            if pk:restriction=f' AND `{pk}`<=%s';params=(cutoff,)
            index={'kbo_season_records':'idx_league_player_date','kbo_season_pitch_records':'idx_pitch_league_player_date','kbo_schedule':'idx_schedule_league_date'}[t]
            c.execute(f'''SELECT league_level,YEAR(game_date),COUNT(*) FROM `{t}` FORCE INDEX (`{index}`)
                WHERE (league_level<>1 OR game_date<'2001-01-01' OR game_date>='2008-01-01' OR game_date IS NULL){restriction}
                GROUP BY league_level,YEAR(game_date) ORDER BY league_level,YEAR(game_date)''',params)
            output[t]={'cutoff':cutoff,'counts':[list(r) for r in c.fetchall()]}
            print('protected snapshot',t,flush=True)
    return output

BATTER_FIELDS=('league_level','game_id','game_date','player_id','player_name','inning','pa_result','sb','cs','run_out','pitcher_id','pitcher_name','team','pos','rbi','r','is_gwrbi','order','is_gs','batting_index')
PITCH_FIELDS=('league_level','game_id','game_date','team','player_id','inning','record','pitched','order','er','r')

def register_parents(con,items,dry_run):
    from kbo_early_parser import cached_page
    from player_ingest import parse_profile,FIELDS
    wanted={}
    for item in items:
        for role,key in (('batter','batter_rows'),('pitcher','pitcher_rows')):
            for r in item[key]:wanted.setdefault(int(r['player_id']),(role,int(r['game_date'][:4])))
    if not wanted:return 0
    with con.cursor() as c:
        ids=sorted(wanted)
        c.execute('SELECT player_id FROM kbo_player_data WHERE player_id IN ('+','.join(['%s']*len(ids))+')',ids)
        existing={int(r[0]) for r in c.fetchall()}
        profiles=[];errors=[]
        for pid in sorted(wanted.keys()-existing):
            role,year=wanted[pid];notes=[]
            for ep in ('PitcherDetail/Total','HitterDetail/Basic'):
                try:
                    url=f'https://www.koreabaseball.com/Record/Player/{ep}.aspx?playerId={pid}'
                    page=cached_page(url,ROOT/str(year)/'player-profiles'/f'{pid}-{ep.replace("/","-")}.html.gz')
                    profiles.append(parse_profile(page,pid,None,role));break
                except Exception as e:notes.append(str(e))
            else:errors.append({'player_id':pid,'errors':notes})
        atomic(ROOT/'parent-failures.json',errors)
        if errors:raise ValueError(f'{len(errors)} unverified parent players; see parent-failures.json')
        if not dry_run:
            sql='INSERT INTO kbo_player_data (`'+'`,`'.join(FIELDS)+'`) VALUES ('+','.join(['%s']*len(FIELDS))+')'
            for r in profiles:c.execute(sql,[r[k] for k in FIELDS])
        return len(profiles)

def verify_year(con,year,game_ids=None):
    ids=list(game_ids) if game_ids is not None else [g['game_id'] for g in discover(year)]
    if not ids:raise ValueError('verification requires explicit nonempty game scope')
    params=(f'{year}-01-01',f'{year+1}-01-01',*ids)
    scope='league_level=1 AND game_date >= %s AND game_date < %s AND game_id IN ('+','.join(['%s']*len(ids))+')'
    checks={}
    with con.cursor() as c:
        c.execute(f'''SELECT COUNT(*),COUNT(DISTINCT game_id),COALESCE(SUM(run_out),0),
            COALESCE(SUM(pa_result IS NOT NULL AND batting_index IS NULL),0),
            COALESCE(SUM((pitcher_id IS NULL)<>(pitcher_name IS NULL)),0),
            COALESCE(SUM(run_out IS NULL OR sb IS NULL OR cs IS NULL),0),
            COALESCE(SUM(player_id IS NULL OR player_id<=0),0)
            FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE {scope}''',params)
        v=c.fetchone();checks.update(batter_rows=v[0],games=v[1],run_out=v[2],null_batting_index=v[3],partial_pitcher=v[4],null_running=v[5],missing_player=v[6])
        c.execute(f'SELECT COUNT(*),COALESCE(SUM(player_id IS NULL OR player_id<=0),0) FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE {scope}',params)
        checks['pitcher_rows'],checks['missing_pitch_player']=c.fetchone()
        for name,sql in (
            ('invalid_index_groups',f'''SELECT COUNT(*) FROM (SELECT game_id,team FROM kbo_season_records WHERE {scope} AND pa_result IS NOT NULL GROUP BY game_id,team HAVING MIN(batting_index)<>1 OR MAX(batting_index)<>COUNT(*) OR COUNT(DISTINCT batting_index)<>COUNT(*)) x'''),
            ('duplicate_pitchers',f'''SELECT COUNT(*) FROM (SELECT game_id,player_id FROM kbo_season_pitch_records WHERE {scope} GROUP BY game_id,player_id HAVING COUNT(*)>1) x'''),
            ('duplicate_batters',f'''SELECT COUNT(*) FROM (SELECT game_id,team,player_id,inning,pa_result,batting_index FROM kbo_season_records WHERE {scope} GROUP BY game_id,team,player_id,inning,pa_result,batting_index HAVING COUNT(*)>1) x''')):
            sql=sql.replace('FROM kbo_season_records WHERE','FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE').replace('FROM kbo_season_pitch_records WHERE','FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE')
            c.execute(sql,params);checks[name]=c.fetchone()[0]
        c.execute(f'SELECT COUNT(*) FROM kbo_schedule WHERE {scope.replace("game_id IN", "game_code IN")}',params);checks['schedule_games']=c.fetchone()[0]
        c.execute(f'''SELECT DISTINCT b.game_id FROM kbo_season_records b FORCE INDEX (idx_league_game_team_batting_index) WHERE b.{scope}
            AND NOT EXISTS(SELECT 1 FROM kbo_season_pitch_records p FORCE INDEX (idx_pitch_league_game) WHERE p.league_level=1 AND p.game_id=b.game_id) ORDER BY b.game_id''',params)
        checks['pitcher_missing_games']=[r[0] for r in c.fetchall()]
    # SUM returns Decimal; checkpoints must retain numeric counters so resumed
    # aggregation cannot accidentally concatenate JSON strings.
    for name in checks:
        if name!='pitcher_missing_games':checks[name]=int(checks[name])
    for name in ('null_batting_index','partial_pitcher','null_running','missing_player','missing_pitch_player','invalid_index_groups','duplicate_pitchers','duplicate_batters'):
        if checks[name]:raise ValueError(f'{year}: final check failed {name}={checks[name]}')
    return checks

def write_year(con,year,allow_incomplete=False,batch_games=1):
    if not 1<=batch_games<=5:raise ValueError('transaction limited to 1--5 games')
    all_games=discover(year)
    receipt=ROOT/str(year)/'write-receipt.json'
    failures=json.loads((ROOT/str(year)/'dry-failures.json').read_text())
    if failures and not allow_incomplete:raise ValueError(f'{year}: refusing write with {len(failures)} failures')
    blocked={v['game']['game_id'] for v in failures}
    games=[g for g in all_games if g['game_id'] not in blocked]
    prior=json.loads(receipt.read_text()) if receipt.exists() else None
    committed=set(prior.get('committed_game_ids',[])) if prior else set()
    if prior and not committed:
        if prior['games']==len(all_games):committed={g['game_id'] for g in all_games}
        else:raise ValueError('legacy incomplete receipt requires exact reconciliation')
    new_games=[g for g in games if g['game_id'] not in committed][:batch_games]
    if not new_games:
        report=verify_year(con,year)
        report.update(discovered_games=len(all_games),unresolved_games=sorted(blocked),committed_game_ids=sorted(committed))
        return report
    items=[]
    for g in new_games:
        item=json.loads((ROOT/str(year)/'plans'/f"{g['game_id']}.json").read_text());validate_plan(item);items.append(item)
    try:
        with con.cursor() as c:
            for t in ('kbo_season_records','kbo_season_pitch_records'):
                ids=[g['game_id'] for g in new_games]
                index='idx_league_game_team_batting_index' if t=='kbo_season_records' else 'idx_pitch_league_game'
                c.execute(f"SELECT COUNT(*) FROM `{t}` FORCE INDEX (`{index}`) WHERE league_level=1 AND game_id IN ("+','.join(['%s']*len(ids))+')',ids)
                if c.fetchone()[0]:raise ValueError(f'{year}: existing rows without receipt; refusing overwrite {t}')
            register_parents(con,items,False)
            # Group historic index inserts by player while retaining the
            # original within-player/game row order (including total rows).
            # This avoids random reads of old player/date B-tree pages per game.
            for t,fields,key in (('kbo_season_records',BATTER_FIELDS,'batter_rows'),('kbo_season_pitch_records',PITCH_FIELDS,'pitcher_rows')):
                all_rows=sorted((r for item in items for r in item[key]),key=lambda r:int(r['player_id']))
                sql='INSERT INTO '+t+' (`'+'`,`'.join(fields)+'`) VALUES ('+','.join(['%s']*len(fields))+')'
                for offset in range(0,len(all_rows),5000):
                    chunk=all_rows[offset:offset+5000]
                    c.executemany(sql,[tuple(r[k] for k in fields) for r in chunk])
                    print(f'{year} staged {t} {offset+len(chunk)}/{len(all_rows)}',flush=True)
            for item in items:
                g=item['game']
                c.execute('SELECT game_date FROM kbo_schedule WHERE league_level=1 AND game_code=%s FOR UPDATE',(g['game_id'],))
                existing=c.fetchone()
                if existing and str(existing[0])!=g['game_date']:raise ValueError('schedule date collision')
                fields=('league_level','game_code','game_date','away_team','home_team','away_score','home_score','tv','stadium','is_allstar','away_inning_scores','home_inning_scores')
                values=(1,g['game_id'],g['game_date'],g['away_team'],g['home_team'],g['away_score'],g['home_score'],g['tv'],g['stadium'],g['is_allstar'],json.dumps(item['away_innings']),json.dumps(item['home_innings']))
                if existing:
                    c.execute('UPDATE kbo_schedule SET '+','.join('`'+f+'`=%s' for f in fields[2:])+' WHERE league_level=1 AND game_code=%s AND game_date>=%s AND game_date<%s',values[2:]+(g['game_id'],f'{year}-01-01',f'{year+1}-01-01'))
                else:c.execute('INSERT INTO kbo_schedule (`'+'`,`'.join(fields)+'`) VALUES ('+','.join(['%s']*len(fields))+')',values)
        # Validate only the staged games before committing. An entire year's
        # reads must not hold a write transaction open while its undo grows.
        report=verify_year(con,year,[g['game_id'] for g in new_games])
        expected=(len(new_games),sum(len(v['batter_rows']) for v in items),sum(len(v['pitcher_rows']) for v in items))
        if (report['games'],report['batter_rows'],report['pitcher_rows'])!=expected:raise ValueError('written counts differ from plans')
        if report['schedule_games']!=len(new_games):raise ValueError('schedule count differs from plans')
        for name in ('games','batter_rows','pitcher_rows','run_out','schedule_games'):
            report[name]+=int(prior.get(name,0)) if prior else 0
        report['pitcher_missing_games']=sorted(set(report['pitcher_missing_games'])|set(prior.get('pitcher_missing_games',[]) if prior else []))
        report['discovered_games']=len(all_games);report['unresolved_games']=sorted(blocked)
        report['committed_game_ids']=sorted(committed|{g['game_id'] for g in new_games})
        con.commit();atomic(receipt,report)
        display={k:v for k,v in report.items() if k!='committed_game_ids'}
        print(f'{year} WRITE VERIFIED {display}',flush=True)
        return report
    except Exception:con.rollback();raise

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--start-year',type=int,default=2001)
    p.add_argument('--end-year',type=int,default=2007)
    p.add_argument('--collect',action='store_true')
    p.add_argument('--dry-run',action='store_true')
    p.add_argument('--write',action='store_true')
    p.add_argument('--game-id')
    p.add_argument('--retry-failures',action='store_true')
    p.add_argument('--allow-incomplete',action='store_true',help='write validated games only; preserve unresolved games in report')
    p.add_argument('--batch-games',type=int,default=1,help='commit and checkpoint 1--5 games per transaction; default 1')
    p.add_argument('--max-games',type=int,help='stop cleanly after this many newly committed games; resume from receipts')
    a=p.parse_args()
    if not 2001<=a.start_year<=a.end_year<=2007:raise ValueError('only first team 2001--2007 authorized')
    if not 1<=a.batch_games<=5:raise ValueError('batch-games must be 1--5')
    if a.max_games is not None and a.max_games<1:raise ValueError('max-games must be positive')
    if a.collect:
        for y in range(a.start_year,a.end_year+1):collect(y)
        return
    con=connect()
    try:
        if a.dry_run:
            from kbo_early_parser import Resolver
            resolver=Resolver(con,ROOT)
            for year in range(a.start_year,a.end_year+1):
                passed,failures=dry(year,resolver,a.game_id,a.retry_failures)
                print(year,'dry summary',len(passed),len(failures),flush=True)
                if passed:print('parent check',register_parents(con,passed,True),flush=True)
        elif a.write:
            import fcntl
            import signal
            stop_requested={'value':False}
            def request_stop(signum,frame):stop_requested['value']=True
            signal.signal(signal.SIGTERM,request_stop)
            signal.signal(signal.SIGINT,request_stop)
            lock=(ROOT/'write.lock').open('w');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            backup_status=ROOT/'recovery-backups'/'latest-status.json'
            backup=json.loads(backup_status.read_text())
            if backup.get('phase')!='backup_created_and_gzip_verified':raise ValueError('verified current backup required before write')
            backup_file=backup_status.parent/backup['file']
            import hashlib
            digest=hashlib.sha256()
            with backup_file.open('rb') as source:
                for chunk in iter(lambda:source.read(1024*1024),b''):digest.update(chunk)
            if digest.hexdigest()!=backup['sha256']:raise ValueError('backup checksum mismatch; write refused')
            with con.cursor() as c:c.execute('SET SESSION max_statement_time=15')
            baseline_path=ROOT/'protected-before.json'
            if baseline_path.exists():
                baseline=json.loads(baseline_path.read_text())
                if any(any(len(row)!=3 for row in value['counts']) for value in baseline.values()):raise ValueError('protected baseline must contain league/year/count groups')
            else:baseline=protected_snapshot(con);atomic(baseline_path,baseline)
            reports={}
            written_this_run=0
            limited=False
            for year in range(a.start_year,a.end_year+1):
                receipt=ROOT/str(year)/'write-receipt.json'
                previous_games=json.loads(receipt.read_text())['games'] if receipt.exists() else 0
                while True:
                    batch_size=min(a.batch_games,a.max_games-written_this_run) if a.max_games else a.batch_games
                    report=write_year(con,year,a.allow_incomplete,batch_size)
                    written_this_run+=report['games']-previous_games
                    previous_games=report['games']
                    reports[year]=report
                    if stop_requested['value']:
                        limited=True
                        print('stop requested; current batch committed and checkpointed; no next batch',flush=True)
                        break
                    if a.max_games and written_this_run>=a.max_games:
                        limited=True
                        break
                    if report['games']+len(report.get('unresolved_games',[]))==len(discover(year)):break
                if limited:break
                # Full year verification happens once after all game commits,
                # with no staged writes or large rollback to retain.
                full=verify_year(con,year)
                for name in ('games','batter_rows','pitcher_rows','run_out','schedule_games','pitcher_missing_games'):
                    if full[name]!=report[name]:raise ValueError(f'{year}: final totals differ from committed checkpoints')
                con.commit()
            with con.cursor() as c:c.execute('SET SESSION max_statement_time=30')
            after=protected_snapshot(con,baseline);atomic(ROOT/'protected-after.json',after)
            if after!=baseline:raise ValueError('protected outside-range counts changed')
            if limited:atomic(ROOT/'write-progress.json',{'phase':'run_limit_reached','new_games_committed':written_this_run,'reports':reports})
            else:
                final_path=ROOT/'final-report.json'
                existing=json.loads(final_path.read_text()) if final_path.exists() else {}
                existing.update({str(year):report for year,report in reports.items()})
                atomic(final_path,existing)
            from kbo_candle_crawl import publish_ranking_revision
            publish_ranking_revision()
        else:raise ValueError('choose --collect, --dry-run or --write')
    finally:con.close()

if __name__=='__main__':main()
