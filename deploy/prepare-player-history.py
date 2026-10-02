"""Resolve source winners/roster to existing KBO IDs and prepare reviewable JSON."""
import json
import re
from collections import Counter
from pathlib import Path
from decimal import Decimal

ROOT=Path(__file__).resolve().parent.parent
CACHE=ROOT/'.player-history'
DEST=ROOT/'data/player-history'
# Existing public KBO IDs; distinguish same-name players by birth/position/club.
OVERRIDES={
 '송진우':89770,'이상훈':93147,'신윤호':94126,'김광현':77829,'윤성환':74454,
 '윤석민':75620,'양현종':77637,'안우진':68341,'김진우':72641,'이승호':99137,
 '정재훈':73241,'고우석':67119,'김상수':76430,'김홍집':93311,'김상훈':84112,
 '이정훈':87721,'김기태':91803,'박종호':92906,'김동주':98218,'이병규':97109,
 '김현수':76290,'김태균':71752,'박종훈':40007,'김성한':82612,'이승엽':95436,
 '이용규':74163,'김상호':88110,'김상현':70646,'박병호':75125,'이호준':94629,
 '이해창':82873,'고영민':72214,'구자욱':62404,'김도영':52605,'김종국':96616,
 '오재원':77248,'박찬호':64646,'김재현':94107,'마크 키퍼':72653,
 '세스 후랭코프':68240,'라일리 톰슨':55903,'나르시소 엘비라':72412,
 '셰인 바워스':73322,'브랜든 나이트':79430,'아리엘 미란다':51257,
 '페르난도 에르난데스':71829,'마이클 보우덴':66226,'클리프 브룸바':73324,
 '로베르토 페타지니':78129,
}
PITCHER={'다승','평균자책점','탈삼진','세이브','홀드','승률','세이브포인트'}
normalize=lambda x:re.sub(r'\s+','',x or '')

def save(name,data):
 DEST.mkdir(parents=True,exist_ok=True)
 (DEST/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')

def main():
 catalog=json.loads((CACHE/'catalog.json').read_text(encoding='utf-8'))['players']
 byid={p['player_id']:p for p in catalog}
 wiki=json.loads((CACHE/'titles-extracted.json').read_text(encoding='utf-8'))
 official=json.loads((CACHE/'titles-official.json').read_text(encoding='utf-8'))
 def resolve(r):
  if r['name']=='전준호':return 94364 if r['type'] in PITCHER else 91511
  if r['name'] in OVERRIDES:return OVERRIDES[r['name']]
  options=[p['player_id'] for p in catalog if normalize(r['name']) in [normalize(p['name']),normalize(p['oldname'])]]
  if len(options)!=1:raise ValueError(('Unresolved winner',r,options))
  return options[0]
 for r in wiki:r['player_id']=resolve(r)
 aliases={normalize(r['name']):r['player_id'] for r in wiki}
 # Official registered foreign names are usually shorter than wiki names.
 for r in wiki:
  p=byid[r['player_id']]
  for name in [p['name'],p['oldname']]:
   if name:aliases.setdefault(normalize(name),r['player_id'])
 corrections=[]
 for r in wiki:
  group=[o for o in official if (o['year'],o['type'])==(r['year'],r['type'])]
  if not group:continue
  matched=[]
  for o in group:
   if o['name']=='전준호':pid=94364 if r['type'] in PITCHER else 91511
   elif normalize(o['name']) in [normalize(r['name']),normalize(byid[r['player_id']]['name']),normalize(byid[r['player_id']]['oldname'])]:pid=r['player_id']
   else:pid=OVERRIDES.get(o['name']) or aliases.get(normalize(o['name']))
   if pid==r['player_id']:matched.append(o)
  if len(matched)!=1:raise ValueError(('Official winner mismatch',r,group))
  o=matched[0]
  if Decimal(r['record'])!=Decimal(o['record']):corrections.append({'player_id':r['player_id'],'name':r['name'],'type':r['type'],'year':r['year'],'wiki':r['record'],'official':o['record']})
  r['record']=o['record']
  r['source']='https://www.koreabaseball.com/Record/History/Player/'+('Pitcher' if r['type'] in PITCHER else 'Hitter')+'.aspx'
  r['record']=str(Decimal(r['record']).quantize(Decimal('0.001')))
 for r in wiki:
  player=byid[r['player_id']]
  # Historical position labels can be wrong or change during a player's career.
  # Match identity by ID/name/birth; verify the official search catalog separately.
  if player['birth'] and re.fullmatch(r'\d{4}-\d{2}-\d{2}',player['birth']):
   age=r['year']-int(player['birth'][:4])
   if not 16<=age<=48:raise ValueError(('Winner age mismatch',r,player))
  r['record']=str(Decimal(r['record']).quantize(Decimal('0.001')))
 if len({(r['player_id'],r['type'],r['year']) for r in wiki})!=len(wiki):raise ValueError('Duplicate title key')
 for category,first,last in [('홀드',2000,2025),('세이브포인트',1982,2003),('승리타점',1982,1989)]+[(x,1982,2025) for x in set(r['type'] for r in wiki)-{'홀드','세이브포인트','승리타점'}]:
  years={r['year'] for r in wiki if r['type']==category}
  if years!=set(range(first,last+1)):raise ValueError(('Missing years',category,years))
 save('titleholders.json',sorted(wiki,key=lambda r:(r['year'],r['type'],r['player_id'])))
 roster=json.loads((CACHE/'roster-extracted.json').read_text(encoding='utf-8'))
 profiles=json.loads((CACHE/'roster-profiles.json').read_text(encoding='utf-8'))
 changes=[]
 for r in roster:
  matches=[p for p in profiles.values() if p['cells'][1]==r['name']]
  if len(matches)!=1:raise ValueError(('Unresolved roster',r))
  p=matches[0];fields=p['profile'];pid=p['player_id']
  m=re.fullmatch(r'(\d+)년 (\d+)월 (\d+)일',fields['생년월일'])
  birth='%04d-%02d-%02d'%tuple(map(int,m.groups()))
  hands=re.search(r'\(([^)]+)\)',fields['포지션'])[1]
  pitch='좌투' if '좌투' in hands else ('우투' if '우투' in hands or '우사' in hands else None)
  bat='양타' if '양타' in hands else ('좌타' if '좌타' in hands else '우타')
  old=byid.get(pid)
  if old and old['birth'] and old['birth']!=birth:raise ValueError(('Existing birth mismatch',r,old,p))
  if old and old['name']!=r['name'] and old['name']!='김태우':raise ValueError(('Unexpected rename',r,old))
  changes.append({'player_id':pid,'name':r['name'],'team':'울산','is_kbodle':4,'pos':r['pos'],'birth':birth,'backNo':p['cells'][0] if p['cells'][0].isdigit() else r['backNo'],'bat':bat,'throw':pitch,'body':fields.get('신장/체중','').replace('/',', '),'school_source':fields.get('경력'),'draft':fields.get('지명순위'),'military':r['military'],'new':old is None,'previous_name':old['name'] if old else None,'source':p['source']})
 save('ulsan-roster.json',changes)
 overseas=[]
 for name in ['이정후','김하성','김혜성','송성문']:
  players=[p for p in catalog if p['name']==name]
  if len(players)!=1:raise ValueError(('Overseas name ambiguous',name,players))
  overseas.append({'player_id':players[0]['player_id'],'name':name,'team':'키움','is_kbodle':3})
 save('overseas-players.json',overseas)
 save('source-notes.json',{'checked_at':'2026-09-28','titles_through':2025,'roster_source':'https://namu.wiki/w/울산 웨일즈/선수단','wiki_access':'https://m.namu.moe/w/울산 웨일즈/선수단','roster_includes_military':True,'roster_excludes_former_players':True,'corrections':corrections,'counts':dict(Counter(r['type'] for r in wiki))})
 print('Prepared:',len(wiki),'titles;',len(changes),'Ulsan;',sum(p['new'] for p in changes),'new;',len(corrections),'official corrections')

if __name__=='__main__':
 import sys
 sys.stdout.reconfigure(encoding='utf-8')
 main()
