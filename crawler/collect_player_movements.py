"""Cache every official Trade.aspx row, excluding name changes from the plan."""
import argparse,gzip,hashlib,html,json,re,time
from collections import Counter
from pathlib import Path
import requests

ROOT=Path('/home/bitnami/wesiper/official-player-movements-2017-2026')
URL='https://www.koreabaseball.com/ws/Player.asmx/GetTradeList'
def atomic(path,value):
 path.parent.mkdir(parents=True,exist_ok=True);tmp=path.with_suffix(path.suffix+'.tmp');tmp.write_text(json.dumps(value,ensure_ascii=False,indent=2),encoding='utf-8');tmp.replace(path)
def clean(value):return ' '.join(html.unescape(re.sub('<[^>]+>',' ',value or '')).split())
def session():
 s=requests.Session();s.headers.update({'User-Agent':'Mozilla/5.0','Referer':'https://www.koreabaseball.com/Player/Trade.aspx','X-Requested-With':'XMLHttpRequest'})
 r=s.get('https://www.koreabaseball.com/Player/Trade.aspx',timeout=60);r.raise_for_status();ROOT.mkdir(exist_ok=True);(ROOT/'Trade.aspx.html').write_bytes(r.content);return s
def collect():
 s=session();failures=[];allrows=[];summary=[];renames=[]
 for year in range(2017,2027):
  try:
   rows=[];total=None;page=1;excluded=0;occurrences=Counter()
   while total is None or len(rows)<total:
    path=ROOT/str(year)/f'page-{page:04d}.json.gz';path.parent.mkdir(exist_ok=True)
    if path.exists():
     with gzip.open(path,'rb') as f:raw=f.read()
    else:
     params={'seasonId':year,'monthId':0,'bdSc':0,'teamName':'','searchIf':'','pageNo':page,'listCount':100}
     for attempt in range(3):
      try:
       r=s.post(URL,data=params,timeout=60);r.raise_for_status();raw=r.content;data=json.loads(raw.decode('utf-8-sig'));assert str(data['code'])=='100';break
      except Exception:
       if attempt==2:raise
       time.sleep(1)
     with gzip.open(path,'wb') as f:f.write(raw)
    data=json.loads(raw.decode('utf-8-sig'));assert str(data['code'])=='100';n=int(data['totalCnt'])
    if total is not None and n!=total:raise ValueError('source total changed during pagination')
    total=n;batch=data['rows'];assert batch or len(rows)==total
    for offset,row in enumerate(batch):
     cells=[clean(c['Text']) for c in row['row']];assert len(cells)==5,cells
     date,kind,team,player,note=cells;assert re.fullmatch(str(year)+r'-\d{2}-\d{2}',date),cells
     if kind=='개명':
      excluded+=1;renames.append({'year':year,'event_date':date,'team':team,'player_text':player,'note':note,'source_file':str(path.relative_to(ROOT)),'raw':row});continue
     key=json.dumps(cells,ensure_ascii=False);occurrences[key]+=1
     source_key=hashlib.sha256((key+'|'+str(occurrences[key])).encode()).hexdigest()
     ids=re.findall(r'playerId=(\d+)',json.dumps(row,ensure_ascii=False))
     number=re.fullmatch(r'(?:No\.?\s*)?(\d{1,3})\s*(?:→|->|⇒|➡)\s*(?:No\.?\s*)?(\d{1,3})',note) if kind=='등번호 변경' else None
     allrows.append({'source_key':source_key,'year':year,'event_date':date,'event_type':kind,'team':team,'player_text':player,'player_id':int(ids[0]) if len(set(ids))==1 else None,'note':note or None,'old_back_no':number[1] if number else None,'new_back_no':number[2] if number else None,'source_file':str(path.relative_to(ROOT)),'source_page':page,'source_row':offset+1,'source_sha256':hashlib.sha256(raw).hexdigest(),'raw':row})
    rows.extend(batch);page+=1
   assert len(rows)==total
   summary.append({'year':year,'official_rows':total,'excluded_name_changes':excluded,'stored_rows':total-excluded});print(summary[-1],flush=True)
  except Exception as e:failures.append({'year':year,'error':str(e)})
 atomic(ROOT/'renames.json',renames);atomic(ROOT/'plan.json',{'rows':allrows,'annual':summary,'failures':failures});atomic(ROOT/'collection-summary.json',{'annual':summary,'failures':failures,'event_types':dict(Counter(r['event_type'] for r in allrows))})
 print('rows',len(allrows),'failures',len(failures),flush=True)
 if failures:raise SystemExit(1)
if __name__=='__main__':collect()
