"""Official-only resumable Futures 2010--2021 collection and bounded backfill."""
import argparse
import concurrent.futures
import gzip
import html
import json
import re
import time
from pathlib import Path
import requests
from kbo_futures_crawl import HEADERS, clean, normalize_team, valid_box_page

ROOT=Path('/home/bitnami/wesiper/official-futures-2010-2021')
SCHEDULE='https://www.koreabaseball.com/ws/Schedule.asmx/GetScheduleList'
BOX='https://www.koreabaseball.com/Futures/Schedule/BoxScore.aspx'

def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    temporary=path.with_suffix(path.suffix+'.tmp')
    temporary.write_text(json.dumps(data,ensure_ascii=False,default=str),encoding='utf-8')
    temporary.replace(path)

def cached(url,path,params=None,data=None):
    if path.exists():
        with gzip.open(path,'rt',encoding='utf-8') as stream:return stream.read()
    for attempt in range(4):
        try:
            response=requests.post(url,data=data,headers=HEADERS,timeout=30) if data is not None else requests.get(url,params=params,headers=HEADERS,timeout=30)
            response.raise_for_status();response.encoding='utf-8'
            path.parent.mkdir(parents=True,exist_ok=True)
            temporary=path.with_suffix('.tmp')
            with gzip.open(temporary,'wt',encoding='utf-8') as stream:stream.write(response.text)
            temporary.replace(path)
            time.sleep(.2)
            return response.text
        except (requests.RequestException,ValueError):
            if attempt==3:raise
            time.sleep(2**attempt)

def discover(year):
    destination=ROOT/str(year)/'games.json'
    if destination.exists():return json.loads(destination.read_text())
    found={};failures=[];unlinked=[]
    for month in range(1,13):
        try:
            payload=json.loads(cached(SCHEDULE,ROOT/str(year)/f'schedule-{month:02}.json.gz',
                data={'leId':2,'srIdList':'0,1,3,5,6,7,9','seasonId':year,'gameMonth':f'{month:02}','teamId':''}))
            current=None
            for entry in payload['rows']:
                cells=entry.get('row',[])
                day=next((cell for cell in cells if cell.get('Class')=='day'),None)
                if day:
                    match=re.search(r'(\d{2})\.(\d{2})',clean(day.get('Text')))
                    if not match:raise ValueError('invalid official date')
                    current=f'{year}-{match[1]}-{match[2]}'
                index=next((i for i,cell in enumerate(cells) if cell.get('Class')=='play'),None)
                if index is None:continue
                joined=' '.join(str(cell.get('Text') or '') for cell in cells)
                if '취소' in clean(joined):continue
                parts=[clean(value) for value in re.findall(r'<span\b[^>]*>(.*?)</span>',cells[index].get('Text',''),re.I|re.S)]
                if len(parts)!=5 or parts[2].lower()!='vs':continue
                codes=set(re.findall(r'gameId=([A-Z0-9]+)',html.unescape(joined),re.I))
                if len(codes)!=1:
                    unlinked.append({'game_date':current,'away_team':parts[0],'home_team':parts[4],'away_score':parts[1],'home_score':parts[3],'official_row':entry,'reason':'official schedule has no unique game code'})
                    continue
                source_id=next(iter(codes)).upper()
                if not re.fullmatch(r'\d{8}[A-Z]{4}\d',source_id):raise ValueError('unexpected official code')
                request_id=str(year)+source_id[4:]
                series_match=re.search(r'(?:seriesId|srId)=(\d+)',html.unescape(joined),re.I)
                series=int(series_match[1]) if series_match else (int(source_id[0]) if source_id[:4] in ('1111','3333','5555','7777') else 0)
                game_id=str(series)*4+request_id[4:] if series in (1,3,5,7) else request_id
                if current is None or request_id[:8]!=current.replace('-',''):raise ValueError('schedule/request date mismatch')
                game={'game_id':game_id,'request_id':request_id,'game_date':current,'series':series,
                      'away_team':normalize_team(parts[0]),'home_team':normalize_team(parts[4]),
                      'away_score':int(parts[1]),'home_score':int(parts[3]),
                      'tv':clean(cells[index+3].get('Text')) or None if len(cells)>index+3 else None,
                      'stadium':clean(cells[index+5].get('Text')) if len(cells)>index+5 else '',
                      'is_allstar':int(series in (6,9) or parts[0] in ('북부','남부','동군','서군') or parts[4] in ('북부','남부','동군','서군'))}
                if game_id in found and found[game_id]!=game:raise ValueError('duplicate official game code')
                found[game_id]=game
        except Exception as error:failures.append({'year':year,'month':month,'error_type':type(error).__name__,'error':str(error)})
    atomic(ROOT/str(year)/'schedule-failures.json',failures)
    atomic(ROOT/str(year)/'unlinked-schedule.json',unlinked)
    if failures:raise ValueError(f'{year}: schedule failures {len(failures)}')
    games=sorted(found.values(),key=lambda game:(game['game_date'],game['game_id']))
    atomic(destination,games)
    print(year,'official completed games',len(games),flush=True)
    return games

def collect_game(game):
    folder=ROOT/game['game_date'][:4]
    decision=folder/f"{game['game_id']}-source.json"
    if decision.exists():return json.loads(decision.read_text())
    series_order=list(dict.fromkeys([game['series'],0,6,1,3,5,7,9]))
    attempts=[]
    for series in series_order:
        path=folder/f"s{series}-{game['request_id']}-box.html.gz"
        page=cached(BOX,path,params={'leagueId':2,'seriesId':series,'seasonId':game['game_date'][:4],'gameId':game['request_id']})
        attempts.append(series)
        if valid_box_page(page):
            result={'game_id':game['game_id'],'series':series,'path':path.name,'valid_box':True,'attempts':attempts}
            atomic(decision,result)
            return result
    result={'game_id':game['game_id'],'series':game['series'],'valid_box':False,'attempts':attempts,'source_issue':'official box has no valid scoreboard/hitter data'}
    atomic(decision,result)
    return result

def collect(start,end):
    for year in range(start,end+1):
        try:games=discover(year)
        except Exception as error:
            print(year,'schedule discovery failed',str(error),flush=True);continue
        failures=[];source_issues=[]
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
            pending={executor.submit(collect_game,game):game for game in games}
            for completed,future in enumerate(concurrent.futures.as_completed(pending),1):
                game=pending[future]
                try:
                    result=future.result()
                    if not result['valid_box']:source_issues.append(result)
                except Exception as error:failures.append({'game':game,'error_type':type(error).__name__,'error':str(error)})
                if completed%50==0 or completed==len(games):
                    atomic(ROOT/str(year)/'collection-progress.json',{'completed':completed,'total':len(games),'failures':failures,'source_issues':source_issues})
                    print(year,'cached',completed,'/',len(games),'failures',len(failures),'empty boxes',len(source_issues),flush=True)
        atomic(ROOT/str(year)/'collection-failures.json',failures)
        atomic(ROOT/str(year)/'source-issues.json',source_issues)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--start-year',type=int,default=2010)
    parser.add_argument('--end-year',type=int,default=2021)
    parser.add_argument('--collect',action='store_true')
    args=parser.parse_args()
    if not 2010<=args.start_year<=args.end_year<=2021:raise ValueError('only Futures 2010--2021 authorized')
    if args.collect:collect(args.start_year,args.end_year)
    else:raise ValueError('select --collect')

if __name__=='__main__':main()
