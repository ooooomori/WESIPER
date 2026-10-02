"""Build an explicit, audited import, preserving each year/month occurrence."""
import json,re,unicodedata
from collections import Counter,defaultdict
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent;CACHE=ROOT/'.player-career';DATA=ROOT/'data/player-career'
def read(name):return json.loads((CACHE/name).read_text(encoding='utf-8-sig'))
def write(name,data):(DATA/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
# Each candidate was reviewed by name; Korean rare surnames are explicitly excluded.
NATIVE_CANDIDATES={5090,5184,5230,5232,51551,55629,61742,61795,62157,62947,63494,63692,64898,66508,67206,67454,67539,68330,69630,70121,73209,75258,76529,78629,82271,82904,86170,89172,89477,90312,91044,91066,92501,93122,93339,94230,94419,95573,95901,99730}
EXTRA_FOREIGN={5021,5138,5212,31004,31011,31017,56719,62013,62020,62022,62072,62086,62088,62091,62093,62099,62283,62601,62699,63029,63039,63049,63065,63099,63287,64003,64016,64019,64020,64025,64026,64028,64077,64154,64430,64578,64641,64682,65019,65029,65036,65038,65049,65068,65074,65090,66066,66789,66805,66825,67024,67026,67034,67038,67042,67471,67558,68013,68020,68033,68044,68289,68948,69033,69034,69050,69744,70503,70643,73229,73553,73588,73675,74125,74429,74653,75156,75443,76512,76632,76810,77883,78435,78882}
# Registered Korean/dual citizens remain native; no nationality is inferred from birth place.
NATIONALITY_REVIEW={50585,63077,98661,80057,83600,83800,84573,84999,86310,88331}
WBC_NAMES={50040:'Odrisamer Despaigne',52234:'Robert Stock',52833:'Ivan Nova',53375:'Ariel Jurado',53825:'Roenis Elias',54354:'Enmanuel De Jesus',54944:'Matt Davidson',55138:'Coen Wynne',55912:'Logan Allen',56168:'Jon Kennedy',56464:"Jack O'Loughlin",56823:'Shota Takeda',61629:'Travis Blackley',62859:'Mario Santiago',63432:'Rick Van Den Hurk',64203:'Jorge Cantu',64219:'Yunesky Maya',64737:'Andrew Albers',65060:'Kwon Ju',65856:'Merrill Kelly',66032:'Yohan Pino',66049:'Sugar Ray Marimon',67025:'Mel Rojas Jr.',67650:'Roger Bernadina',67815:'Scott Diamond',67872:'Jamie Romak',68948:'Wei-Chung Wang',69032:'William Cuevas',69209:'Jose Miguel Fernandez',69744:'Warwick Saupold',69950:'Christian Bethancourt',73559:'Robert Perez',73653:'Mike Johnson',77199:'Chris Oxspring',78595:'Karim Garcia',78726:'Brad Thomas',99606:'Seong Hoon Jeong'}
# The legacy KBO birth date differs from the MLB profile for Brad Thomas.
# Use the verified date only to identify roster entries; preserve the DB value.
WBC_BIRTH_OVERRIDES={78726:'1977-10-12'}
WBC_EXTRA={
 50040:[(2013,'https://www.mister-baseball.com/wp-content/uploads/2013/01/2013-World-Baseball-Classic-Provisional-Rosters_011713.pdf')],
 52833:[(2017,'https://img.mlbstatic.com/mlb-images/image/upload/mlb/dpgausawhinm79ikamwm.pdf')],
 51111:[(2026,'https://www.koreabaseball.com/MediaNews/News/KboPhoto/View.aspx?bdSe=516766')],
}
def norm(s):return re.sub('[^a-z]','',unicodedata.normalize('NFKD',s).encode('ascii','ignore').decode().lower())
def main():
 catalog=read('catalog.json')['players'];byid={p['player_id']:p for p in catalog}
 registrations=read('foreign-registrations.json')
 # User chose KBO foreign-player registration, including Asia quota and replacements.
 # This excludes coaches, overseas exhibition players, and Korean registered players.
 foreign={r['player_id'] for r in registrations if r['player_id'] not in NATIVE_CANDIDATES and any(k in r['profile'].get('지명순위','') for k in ['자유선발','외국인','아시아쿼터'])}
 write('foreign-players.json',[{'player_id':i,'name':byid[i]['name'],'oldname':byid[i]['oldname']} for i in sorted(foreign)])
 write('foreign-registration-sources.json',[r for r in registrations if r['player_id'] in foreign])
 awards=read('resolved-awards.json')
 assert not read('identity-failures.json')
 rows={};sources=defaultdict(set)
 def add(r):
  key=(r['player_id'],r['category'],r['type'],r['year'],r['month'],r['pos'])
  item={k:r[k] for k in ['player_id','category','type','team','year','month','pos','note']}
  if key in rows:
   assert rows[key]['team']==item['team'],(key,rows[key],item)
   if item['note']:rows[key]['note']=item['note']
  else:rows[key]=item
  if r.get('source'):sources[key].add(r['source'])
 for r in awards:add(r)
 rosters=read('wbc-rosters.json');kbo=read('wbc-kbo-rosters.json');unknown=[]
 for p in catalog:
  if p['is_WBC']!=1:continue
  pid=p['player_id'];matches=[]
  if pid in WBC_NAMES:
   birth=WBC_BIRTH_OVERRIDES.get(pid,p['birth'])
   matches=[(r['year'],r['source']) for r in rosters if norm(r['fullName'])==norm(WBC_NAMES[pid]) and (not birth or not r.get('birthDate') or r['birthDate']==birth)]
  else:
   if p['birth'] and re.fullmatch(r'\d{4}-\d{2}-\d{2}',p['birth']):
    matches=[(r['year'],r['source']) for r in rosters if r['country']=='Korea' and r.get('birthDate')==p['birth']]
   matches += [(r['year'],r['source']) for r in kbo if r['name']==p['name']]
  matches += WBC_EXTRA.get(pid,[])
  if not matches:
   unknown.append({'player_id':pid,'name':p['name']});matches=[(None,None)]
  for year,source in matches:add(dict(player_id=pid,category='national',type='WBC',team=None,year=year,month=None,pos=None,note=None,source=source))
 final=sorted(rows.values(),key=lambda r:(r['year'] or 0,r['category'],r['type'],r['player_id'],r['month'] or 0,r['pos'] or ''))
 # Supplemental rosters append after the first import, preserving existing PKs.
 supplemental=DATA/'allstar-user-2024-2026.json'
 if supplemental.exists():
  for item in json.loads(supplemental.read_text(encoding='utf-8')):
   r=dict(player_id=item['player_id'],category='award',type='올스타',team=item['team'],year=item['year'],month=None,pos=None,note=None,source='user:allstar-roster-2024-2026')
   key=(r['player_id'],r['category'],r['type'],r['year'],r['month'],r['pos'])
   exists=key in rows;add(r)
   if not exists:final.append(rows[key])
 write('careers.json',final);write('wbc-unknown-years.json',unknown)
 write('sources.json',[{'player_id':key[0],'category':key[1],'type':key[2],'year':key[3],'month':key[4],'pos':key[5],'sources':sorted(value)} for key,value in sources.items()])
 print('CAREERS',len(final),Counter(r['type'] for r in final));print('FOREIGN',len(foreign),'MOVED',sum(bool(byid[i]['oldname']) for i in foreign));print('WBC UNKNOWN',unknown)
 print('WBC players',len({r['player_id'] for r in final if r['type']=='WBC'}))
if __name__=='__main__':main()
