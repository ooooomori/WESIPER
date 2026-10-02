"""Resolve missing movement IDs using dated appearances and official profiles."""
import json
import re
from collections import defaultdict, Counter
from datetime import date
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CACHE=ROOT/'.contract-enrichment'
ALIASES={'SK':'SSG','넥센':'키움','kt':'KT'}
def team(value):return ALIASES.get(value,value)

def run():
    players=json.loads((CACHE/'players.json').read_text(encoding='utf-8'))
    parents={int(p['player_id']):p for p in players}
    names=defaultdict(set)
    for p in players:
        if int(p['player_id'])<10000:continue
        for name in [p['name'],*re.split(r'[,/;·\s()]+',p['oldname'] or '')]:
            if name:names[name].add(int(p['player_id']))
    records=json.loads((CACHE/'identity-records.json').read_text(encoding='utf-8'))
    by_id=defaultdict(list)
    for r in records:
        pid=int(r['player_id'])
        if pid not in parents or pid<10000:continue
        by_id[pid].append(r)
        if r['player_name']:names[r['player_name']].add(pid)
    updates=[];unresolved=[];counts=Counter()
    for movement in json.loads((CACHE/'movements.json').read_text(encoding='utf-8')):
        if movement['player_id'] is not None:continue
        year=int(movement['year']);club=team(movement['team'])
        candidates=names[movement['player_name']]
        eligible=[]
        role=re.search(r'\((투수|포수|내야수|외야수)\)',movement['player_text'])
        for pid in candidates:
            p=parents[pid]
            draft=re.match(r'(\d{2}|\d{4})\s',p['draft'] or '')
            debut=int(draft[1]) if draft else None
            if debut is not None and debut<100:debut+=2000 if debut<50 else 1900
            if debut and debut>year:continue
            if p['birth'] and year-int(p['birth'][:4])<16:continue
            eligible.append(pid)
        pid=None;basis=None;proof=[]
        for tolerance in [0,1,2]:
            hits={p for p in eligible if any(team(r['team'])==club and abs(int(r['year'])-year)<=tolerance for r in by_id[p])}
            if len(hits)>1 and role:
                role_hits={p for p in hits if role[1] in (parents[p]['pos'] or '')}
                if len(role_hits)==1:hits=role_hits
            if len(hits)==1:
                pid=next(iter(hits));basis=f'appearance_team_year_{tolerance}';proof=[r for r in by_id[pid] if team(r['team'])==club and abs(int(r['year'])-year)<=tolerance];break
            if len(hits)>1:break
        if pid is None:
            matches=[p for p in eligible if club in (parents[p]['draft'] or '') or team(parents[p]['team'])==club]
            if len(matches)==1:pid=matches[0];basis='unique_profile_team_or_draft'
            elif len(eligible)==1:pid=eligible[0];basis='unique_official_identity'
        if pid is None:
            unresolved.append({'movement':movement,'candidates':[parents[p] for p in eligible]});continue
        updates.append({'id':movement['id'],'before_identity':{k:movement[k] for k in ['event_date','event_type','team','player_name','player_id','player_text','note']},'player_id':pid,'basis':basis,'player':parents[pid],'appearances':proof})
        counts[basis]+=1
    plan={'updates':updates,'unresolved':unresolved,'basis_counts':counts}
    (CACHE/'identity-plan.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps({'resolved':len(updates),'unresolved':len(unresolved),'basis':counts},ensure_ascii=False))
    groups=defaultdict(list)
    for r in unresolved:groups[r['movement']['player_name']].append(r)
    for name,rows in groups.items():
        print(name,len(rows),sorted({(r['movement']['team'],r['movement']['year']) for r in rows}),[(p['player_id'],p['name'],p['birth'],p['draft']) for p in rows[0]['candidates']])

if __name__=='__main__':run()
