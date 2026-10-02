"""Extract awards/cards and produce reviewable identity groups before DB writes."""
import csv
import json
import re
from collections import Counter,defaultdict
from pathlib import Path
from lxml import html
import runpy

ROOT=Path(__file__).resolve().parent.parent
CACHE=ROOT/'.player-career'
DATA=ROOT/'data/player-career'
DATA.mkdir(exist_ok=True)
SHEET='1kz0EGnWSeUljsjTD9WxLfDm4dg4_5lLOcYjIxu4squQ'
TEAMS={'kia':'KIA','lg':'LG','nc':'NC','kt':'KT','lotte':'롯데','samsung':'삼성','doosan':'두산','hanwha':'한화','hyundai':'현대','ssangbangwool':'쌍방울','haitai':'해태','mbc':'MBC','ob':'OB','binggrae':'빙그레','chungbo':'청보','sammi':'삼미','pacific':'태평양','woori':'우리','nexen':'넥센','ssg':'SSG'}
def team_at(code,year):
 if code=='sbw':return '쌍방울'
 if code=='nexen' and year<2010:return '히어로즈'
 if code=='sk':return 'SSG' if year>=2021 else 'SK'
 if code=='kiwoom':return '키움' if year>=2019 else '넥센' if year>=2010 else '히어로즈'
 if code=='doosan':return '두산' if year>=1999 else 'OB'
 if code=='hanwha':return '한화' if year>=1994 else '빙그레'
 if code=='kia':return 'KIA' if year>=2001 else '해태'
 if code=='lg':return 'LG' if year>=1990 else 'MBC'
 if code not in TEAMS:raise ValueError((code,year))
 return TEAMS[code]
def write(path,value):path.write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
def celltext(cell):return ' '.join(' '.join(cell.itertext()).split())
def award_rows():
 result=[]
 for page,types in [('PlayerPrize',['MVP','신인왕']),('SeriesPrize',['올스타','한국시리즈 MVP']),('GoldenGlove',None),('DefensePrize',None)]:
  tree=html.fromstring((CACHE/(page+'.html')).read_bytes())
  table=tree.xpath('//table')[-1]
  header=[celltext(c) for c in table.xpath('.//tr')[0].xpath('./th|./td')]
  for row in table.xpath('.//tr')[1:]:
   cells=row.xpath('./th|./td')
   if not cells or not re.fullmatch(r'20\d\d|19\d\d',celltext(cells[0])):continue
   year=int(celltext(cells[0]))
   for index,cell in enumerate(cells[1:]):
    tokens=celltext(cell).split()
    if not tokens or tokens[0]=='-':continue
    if types:
     if len(tokens)!=3:raise ValueError((page,year,tokens))
     pairs=[tokens[:2]];typ=types[index];pos=None;note='MVP' if page=='SeriesPrize' and index==0 else None
    else:
     if len(tokens)%2:raise ValueError((page,year,tokens))
     pairs=[tokens[i:i+2] for i in range(0,len(tokens),2)];typ='골든글러브' if page=='GoldenGlove' else '수비상';pos=header[index+1];note=None
    for name,team in pairs:result.append(dict(category='award',type=typ,name=name,team=team,year=year,month=None,pos=pos,note=note,source='https://www.koreabaseball.com/Player/Awards/'+page+'.aspx'))
 return result
def card_rows():
 result=[]
 for gid in ['0','1484573034']:
  with (CACHE/(SHEET+('' if gid=='0' else '-'+gid)+'.csv')).open(encoding='utf-8-sig',newline='') as f:
   cards=list(csv.DictReader(f))
  for row in cards:
   if row['등급'] not in ['ASG','MMVP']:continue
   years=json.loads(row['연도']);month=int(row['월']) if row['등급']=='MMVP' else None
   if month is not None and not 1<=month<=12:raise ValueError(row)
   for year in years:
    result.append(dict(category='award',type='올스타' if row['등급']=='ASG' else '월간 MVP',name=row['이름'],team=team_at(row['구단'],year),year=year,month=month,pos=None,note=None,card_pos=row['포지션'],source='https://docs.google.com/spreadsheets/d/'+SHEET+'/edit?gid='+gid))
 return result
def main():
 rows=award_rows()+card_rows()
 write(CACHE/'raw-careers.json',rows)
 catalog=json.loads((CACHE/'catalog.json').read_text(encoding='utf-8-sig'))['players']
 names=defaultdict(list)
 for p in catalog:
  for n in set([p['name'],p['oldname']]):
   if n:names[n].append(p)
 grouped=defaultdict(list)
 for r in rows:grouped[r['name']].append(r)
 unresolved=[]
 for n,rs in grouped.items():
  if len(names[n])!=1:unresolved.append({'name':n,'candidates':names[n],'years':sorted(set(r['year'] for r in rs)),'teams':sorted(set(r['team'] for r in rs)),'card_pos':sorted(set(r.get('card_pos','') for r in rs))})
 write(CACHE/'unresolved-careers.json',unresolved)
 identities=runpy.run_path(str(ROOT/'deploy/player-career-identities.py'))
 byid={p['player_id']:p for p in catalog};resolved=[];failures=[]
 for r in rows:
  original=r['name'];name=identities['ALIASES'].get(original,original)
  pid=identities['SCOPED'].get((name,r['team']),identities['IDS'].get(name))
  if pid is None and len(names[name])==1:pid=names[name][0]['player_id']
  if pid is None:
   failures.append({'name':original,'canonical':name,'team':r['team'],'year':r['year'],'candidates':[(p['player_id'],p['name'],p['birth']) for p in names[name]]});continue
  p=byid[pid]
  if name not in [p['name'],p['oldname']]:raise ValueError(('Invalid identity override',name,p))
  if p['birth'] and re.fullmatch(r'\d{4}-\d{2}-\d{2}',p['birth']):
   age=r['year']-int(p['birth'][:4])
   if not 16<=age<=49:
    failures.append({'name':original,'canonical':name,'team':r['team'],'year':r['year'],'error':'age mismatch','selected':p,'candidates':names[name]});continue
  resolved.append({**r,'player_id':pid,'catalog_name':p['name']})
 write(CACHE/'resolved-awards.json',resolved)
 write(CACHE/'identity-failures.json',failures)
 print('IDENTITY FAILURES',json.dumps(failures,ensure_ascii=False))
 print('RAW',len(rows),'TYPES',Counter(r['type'] for r in rows),'YEARS', {t:(min(r['year'] for r in rows if r['type']==t),max(r['year'] for r in rows if r['type']==t)) for t in set(r['type'] for r in rows)})
 print('UNRESOLVED',len(unresolved))
 native='김이박최정강조윤장임오한신서권황안송전홍유고문양손배백허남심노하곽성차주우구민진지엄채원천방공현함변염여추도소석선설마길연위표명반왕옥육인맹제모탁국어은편용기팽태봉시평사목음삼두동빈빙류라'
 candidates=[p for p in catalog if p['oldname'] and len(p['oldname'])>4 or not p['name'] or p['name'][0] not in native or len(p['name'])>4]
 write(CACHE/'foreign-candidates.json',candidates)
 print('FOREIGN CANDIDATES',len(candidates))

if __name__=='__main__':main()
