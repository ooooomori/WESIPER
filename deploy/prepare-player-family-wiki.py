"""Resolve linked table members to existing players and deduplicate pairs."""
import json,re
from itertools import combinations
from pathlib import Path
from urllib.parse import unquote

ROOT=Path(__file__).resolve().parents[1];CACHE=ROOT/'.family-enrichment'
SOURCE='https://namu.wiki/w/KBO%20%EB%A6%AC%EA%B7%B8/%EC%95%BC%EA%B5%AC%EC%9D%B8%202%EC%84%B8'
parents=json.loads((CACHE/'players.json').read_text(encoding='utf-8'))
existing=json.loads((CACHE/'family-before.json').read_text(encoding='utf-8'))
overrides=json.loads((CACHE/'identity-overrides.json').read_text(encoding='utf-8')) if (CACHE/'identity-overrides.json').exists() else {}


def links(cell):
    return [a for a in cell['links'] if a['href'].startswith('/w/')]


def resolve(link):
    target=unquote(link['href'][3:]);name=re.sub(r'\([^)]*\)','',target).strip()
    label=re.sub(r'\([^)]*\)','',link['text']).strip()
    candidates=[p for p in parents if p['name'] in (name,label) or any(n in re.split(r'[,/;·\s()]+',p['oldname'] or '') for n in (name,label))]
    year=re.search(r'\((\d{4})',target)
    if year:candidates=[p for p in candidates if (p['birth'] or '').startswith(year[1])]
    month=re.search(r'\(\d{4}년\s*(\d{1,2})월',target)
    if month:candidates=[p for p in candidates if (p['birth'] or '').startswith(f'{year[1]}-{int(month[1]):02d}')]
    evidence=CACHE/'people'/(target.replace('/','_')+'.txt')
    if evidence.exists():
        birth=re.search(r'(?:출생|생년월일)\s*((?:19|20)\d{2})년\s*(\d{1,2})월\s*(\d{1,2})일',evidence.read_text(encoding='utf-8'))
        if birth:
            candidates=[p for p in candidates if (p['birth'] or '').startswith(birth[1])]
            birthday=f'{birth[1]}-{int(birth[2]):02d}-{int(birth[3]):02d}'
            exact=[p for p in candidates if p['birth']==birthday]
            if exact:candidates=exact
    if target in overrides:candidates=[p for p in parents if p['player_id']==overrides[target]]
    if link.get('user_confirmed_player_id'):candidates=[p for p in parents if p['player_id']==link['user_confirmed_player_id']]
    return candidates


tables=json.loads((CACHE/'wiki-tables.json').read_text(encoding='utf-8'))
relations=[];siblings=[];unresolved=[];skipped=[]
for table in tables:
    header=[c['text'] for c in table['rows'][0]] if table['rows'] else []
    if header not in [['1대','2대','3대'],['아버지','아들'],['삼촌','조카'],['조부','손자']]:continue
    span={};grid=[]
    for row in table['rows'][1:]:
        cells={i:v[0] for i,v in span.items()};new={i:(v[0],v[1]-1) for i,v in span.items() if v[1]>1}
        col=0
        for cell in row:
            while col in cells:col+=1
            cells[col]=cell
            if cell['rowspan']>1:new[col]=(cell,cell['rowspan']-1)
            col+=1
        span=new;grid.append(cells)
    child_groups={}
    for cells in grid:
        if header==['1대','2대','3대']:edges=[(i,i+1,'아버지','아들') for i in range(2)]
        else:edges=[(0,1,{'아버지':'아버지','삼촌':'삼촌','조부':'할아버지'}[header[0]],{'아버지':'아들','삼촌':'조카','조부':'손자'}[header[0]])]
        for a,b,kind,reverse in edges:
            if a not in cells or b not in cells:raise ValueError(cells)
            old,young=links(cells[a]),links(cells[b])
            if header==['아버지','아들']:
                child_groups.setdefault(cells[a]['text'],[]).extend(young)
            if not old or not young:
                skipped.append({'table':table['table'],'older':cells[a]['text'],'younger':cells[b]['text'],'reason':'unlinked_person'});continue
            for elder in old:
                for child in young:relations.append({'older':elder,'younger':child,'relationship':kind,'reverse_relationship':reverse,'table':table['table']})
    if header==['아버지','아들']:
        for group in child_groups.values():
            group=list({a['href']:a for a in group}.values())
            for a,b in combinations(group,2):siblings.append({'older':a,'younger':b,'relationship':'sibling','reverse_relationship':'sibling','table':table['table']})

# Both are linked in the supplied uncle table. Their individual profiles
# explicitly identify them as brothers; sharing an uncle alone is insufficient.
assert '형 김윤하' in (CACHE/'people'/'김명규(2007).txt').read_text(encoding='utf-8')
siblings.append({'older':{'href':'/w/김윤하','text':'김윤하'},'younger':{'href':'/w/김명규(2007)','text':'김명규'},'relationship':'sibling','reverse_relationship':'sibling','table':'individual_profiles'})
# Explicit user exception to the unlinked-person rule (KBO ID 72131).
relations.append({'older':{'href':'/w/김호인','text':'김호인'},'younger':{'href':'/w/김용우','text':'김용우','user_confirmed_player_id':72131},'relationship':'아버지','reverse_relationship':'아들','table':'user_exception'})
relations.append({'older':{'href':'/w/김용국','text':'김용국'},'younger':{'href':'/w/김동빈','text':'김동빈','user_confirmed_player_id':60766},'relationship':'아버지','reverse_relationship':'아들','table':'user_exception'})
siblings.append({'older':{'href':'/w/김동영','text':'김동영','user_confirmed_player_id':79952},'younger':{'href':'/w/김동빈','text':'김동빈','user_confirmed_player_id':60766},'relationship':'sibling','reverse_relationship':'sibling','table':'user_exception'})
siblings.append({'older':{'href':'/w/구대진','text':'구대진','user_confirmed_player_id':90003},'younger':{'href':'/w/구대성','text':'구대성','user_confirmed_player_id':93715},'relationship':'sibling','reverse_relationship':'sibling','table':'user_exception'})

plan=[];matched=[];pairs=set()
for relation in relations+siblings:
    elder=resolve(relation['older']);child=resolve(relation['younger'])
    if relation['relationship']!='sibling' and elder and child:
        elder=[p for p in elder if re.match(r'^\d{4}-',p['birth'] or '') and any(re.match(r'^\d{4}-',q['birth'] or '') and int(q['birth'][:4])-int(p['birth'][:4])>=12 for q in child)]
        child=[q for q in child if re.match(r'^\d{4}-',q['birth'] or '') and any(int(q['birth'][:4])-int(p['birth'][:4])>=12 for p in elder)]
    if len(elder)!=1 or len(child)!=1:
        unresolved.append({'source':relation,'older_candidates':elder,'younger_candidates':child});continue
    elder,child=elder[0],child[0]
    if relation['relationship']=='삼촌':
        if elder['player_id']==62761 and child['player_id'] in (54319,56944):
            relation={**relation,'relationship':'5촌','reverse_relationship':'5촌'}
        elif (elder['player_id'],child['player_id']) in {(91514,51817),(89770,61145),(93607,63905)}:
            relation={**relation,'relationship':'외삼촌'}
    if relation['relationship']=='sibling':
        if not elder['birth'] or not child['birth'] or elder['birth']==child['birth']:
            unresolved.append({'source':relation,'older_candidates':[elder],'younger_candidates':[child]});continue
        if elder['birth']>child['birth']:elder,child=child,elder
        relation={**relation,'relationship':'형','reverse_relationship':'동생'}
    elif not elder['birth'] or not child['birth'] or int(child['birth'][:4])-int(elder['birth'][:4])<12:
        unresolved.append({'source':relation,'older_candidates':[elder],'younger_candidates':[child]});continue
    pair=tuple(sorted([elder['player_id'],child['player_id']]))
    if pair in pairs:continue
    pairs.add(pair)
    matches=[r for r in existing if tuple(sorted([r['player_id'],r['relative_player_id']]))==pair]
    item={'player_id':child['player_id'],'player_name':child['name'],'player_birth':child['birth'],'relative_player_id':elder['player_id'],'relative_name':elder['name'],'relative_birth':elder['birth'],'relationship':relation['relationship'],'reverse_relationship':relation['reverse_relationship'],'sources':[SOURCE],'evidence':relation}
    if matches:
        assert len(matches)==1,(pair,matches)
        expected=item['relationship'] if matches[0]['player_id']==item['player_id'] else item['reverse_relationship']
        accepted={expected}
        if expected=='삼촌':accepted.add('외삼촌')
        assert matches[0]['relationship'] in accepted,(item,matches)
        matched.append(item)
    else:plan.append(item)
out={'inserts':plan,'existing':matched,'unresolved':unresolved,'unlinked_skipped':skipped,'source_relation_count':len(relations),'sibling_count':len(siblings)}
(CACHE/'plan.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
(CACHE/'plan-review.txt').write_text('NEW\n'+'\n'.join(f"{r['player_name']}({r['player_id']}) {r['relationship']} {r['relative_name']}({r['relative_player_id']})" for r in plan)+'\nUNRESOLVED\n'+'\n'.join(f"{r['source']['younger']['text']} {r['source']['relationship']} {r['source']['older']['text']} | older={[(p['player_id'],p['name'],p['birth']) for p in r['older_candidates']]} younger={[(p['player_id'],p['name'],p['birth']) for p in r['younger_candidates']]}" for r in unresolved),encoding='utf-8')
print('new',len(plan),'existing',len(matched),'unresolved',len(unresolved),'unlinked skipped',len(skipped))
