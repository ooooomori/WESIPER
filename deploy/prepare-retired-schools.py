"""Prepare school-only updates from official profiles without changing existing values."""
import collections,csv,hashlib,json,re,sys
from pathlib import Path
sys.stdout.reconfigure(encoding='utf-8')
ROOT=Path(__file__).resolve().parent.parent;OUT=ROOT/'output/retired-schools'
targets=json.loads((ROOT/'output/retired-school-targets.json').read_text(encoding='utf-8'))
players={p['player_id']:p for p in targets}
results=json.loads((OUT/'collected.json').read_text(encoding='utf-8')) if (OUT/'collected.json').exists() else [json.loads(p.read_text(encoding='utf-8')) for p in OUT.glob('[0-9]*.json')]
TEAMS={'삼성','해태','KIA','OB','두산','MBC','LG','롯데','삼미','청보','태평양','현대','빙그레','한화','쌍방울','SK','SSG','우리','서울','넥센','히어로즈','키움','NC','KT','kt','울산','상무','경찰','경찰청','한국전력','농협','한일은행','제일은행','기업은행','한국화장품','포스코','포항제철','국군체육부대','삼성(프로)','빙그레(프로)','롯데(프로)'}
COUNTRIES={'미국','일본','도미니카','도미니카공화국','베네수엘라','쿠바','캐나다','멕시코','대만','푸에르토리코','호주','네덜란드','대한민국','한국'}
def norm(s):return re.sub(r'\s+','',s or '')
def school_token(s):
 return s in {'동대문상','덕수상','베네수엘라 V.E.M Sucre','베네수엘라 Agua Linda Academy','미국 CSU Fresno'} or bool(re.search(r'(초|중|고|대|대학|대학교|학교|학원|리틀|전문|중퇴)(\([^)]*\))?$',s) or re.search(r'(초|중|고|대|대학교|리틀)\)+$',s))
updates=[];skipped=[];unknown=collections.Counter();identity_notes=[]
for r in results:
 p=players[r['player_id']]
 if r['status']!='ok':skipped.append({**r,'db_name':p['name'],'reason':r['status']});continue
 dates=re.findall(r'\d+',r['birth'] or '')
 birth='-'.join([dates[0],dates[1].zfill(2),dates[2].zfill(2)]) if len(dates)==3 else None
 names=[p['name'],p['oldname'],p['fullname']]
 name_match=norm(r['name']) in [norm(n) for n in names if n]
 birth_match=birth is not None and p['birth']==birth
 if not name_match and not birth_match:
  skipped.append({**r,'db_name':p['name'],'db_birth':p['birth'],'reason':'identity_mismatch'});continue
 if not name_match or (birth and p['birth']!=birth):identity_notes.append({'player_id':p['player_id'],'db_name':p['name'],'source_name':r['name'],'db_birth':p['birth'],'source_birth':birth,'matched_by':'name' if name_match else 'birth'})
 protected=r['career'].replace('Smithfield-Selma','Smithfield\u2011Selma')
 tokens=[s.strip().replace('\u2011','-') for s in protected.split('-') if s.strip()];schools=[]
 for token in tokens:
  if token in TEAMS or token in COUNTRIES:continue
  if school_token(token):schools.append(token)
  else:unknown[token]+=1
 if not schools:skipped.append({**r,'db_name':p['name'],'reason':'no_school_tokens'});continue
 source_cache=r.get('source_cache',f"{p['player_id']}.html.gz")
 updates.append({'player_id':p['player_id'],'name':p['name'],'old_school':p['school'],'school':'-'.join(schools),'source_name':r['name'],'source_birth':birth,'source_career':r['career'],'source_url':r['source_url'],'source_cache':source_cache,'source_sha256':hashlib.sha256((OUT/source_cache).read_bytes()).hexdigest()})
plan={'targets':len(targets),'collected':len(results),'updates':updates,'skipped':skipped,'unknown_tokens':dict(unknown),'identity_notes':identity_notes}
(OUT/'plan.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2),encoding='utf-8')
with (OUT/'school-updates.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=['player_id','name','school','source_career','source_url']);w.writeheader();w.writerows({k:r[k] for k in w.fieldnames} for r in updates)
print(json.dumps({'targets':len(targets),'collected':len(results),'updates':len(updates),'skipped':collections.Counter(r['reason'] for r in skipped),'unknown_tokens':dict(unknown),'identity_notes':identity_notes},ensure_ascii=False,indent=2))
