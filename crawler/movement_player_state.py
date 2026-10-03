"""Apply recent, unambiguous movement outcomes to current player state."""
from datetime import date
import re

KBO_TEAMS={'SSG','LG','KT','KIA','NC','삼성','키움','롯데','두산','한화'}
ALIASES={'SK':'SSG','넥센':'키움','kt':'KT','울산웨일즈':'울산','울산 웨일즈':'울산','울산웨일스':'울산'}
JOIN_TYPES={'트레이드','트레이드(웨이버)','FA 계약','해외 복귀 FA 계약','비FA 다년계약','자유계약','소속선수 추가 등록','FA 보상선수','2차 드래프트','이적','해외 이적','해외 진출'}
FOREIGN_NAMES={'샌디에이고','샌프란시스코','볼티모어','피츠버그','다저스','LA다저스','로스앤젤레스다저스','양키스','뉴욕양키스','뉴욕메츠','보스턴','시카고컵스','시카고화이트삭스','토론토','텍사스','시애틀','탬파베이','필라델피아','워싱턴','애틀랜타','휴스턴','디트로이트','클리블랜드','미네소타','캔자스시티','오클랜드','애슬레틱스','LA에인절스','로스앤젤레스에인절스','신시내티','밀워키','세인트루이스','콜로라도','애리조나','마이애미','요미우리','한신','주니치','야쿠르트','히로시마','요코하마','DeNA','오릭스','소프트뱅크','지바롯데','치바롯데','라쿠텐','세이부','니혼햄'}


def destination(row):
    route=re.fullmatch(r'\s*[^→]+→\s*([^→]+?)\s*',row.get('note') or '')
    team=route[1] if route else row.get('team') or ''
    team=team.strip()
    return ALIASES.get(team,team)


def outcome(row):
    kind=row['event_type']
    if kind=='자유계약선수':
        return {'is_kbodle':0}
    if kind not in JOIN_TYPES:
        return None
    team=destination(row)
    if team in KBO_TEAMS:
        return {'team':team,'is_kbodle':1}
    if team=='울산':
        return {'team':'울산','is_kbodle':4}
    if re.match(r'^(?:美|日|미국|일본|해외|MLB|NPB|메이저리그|마이너리그)',team) or team.replace(' ','') in FOREIGN_NAMES or kind in {'해외 이적','해외 진출'}:
        return {'is_kbodle':3}
    # Military teams and unknown labels cannot establish a foreign transfer.
    return {}


def plan_state_updates(movements,parents,today,start):
    latest={};pending=[]
    for row in movements:
        pid=row.get('player_id')
        if pid not in parents or not row.get('event_date'):
            continue
        day=date.fromisoformat(str(row['event_date']))
        if day>today:
            continue
        state=outcome(row)
        if state is None:
            continue
        priority=0 if row['event_type']=='자유계약선수' else 1
        key=(day,priority)
        current=latest.get(pid)
        if current is None or key>current[0]:
            latest[pid]=(key,[(row,state)])
        elif key==current[0]:
            current[1].append((row,state))
    updates=[]
    for pid,(key,rows) in sorted(latest.items()):
        if key[0]<start:
            continue
        outcomes={tuple(sorted(state.items())) for _,state in rows}
        if len(outcomes)!=1 or not rows[0][1]:
            pending.append({'reason':'ambiguous_or_unknown_player_state','player_id':pid,'source_keys':[r['source_key'] for r,_ in rows]})
            continue
        row,state=rows[0];parent=parents[pid]
        state=dict(state)
        if state.get('is_kbodle')==1:
            # Domestic active states distinguish curated KBODLE members (1)
            # from other domestic active players (2); a transfer is not curation.
            state['is_kbodle']=parent.get('is_kbodle') if parent.get('is_kbodle') in (1,2) else 2
        changes={field:value for field,value in state.items() if parent.get(field)!=value}
        if not changes:
            continue
        updates.append({'player_id':pid,'source_key':row['source_key'],'event_date':str(row['event_date']),'event_type':row['event_type'],'before':{f:parent.get(f) for f in ('team','is_kbodle')},'changes':changes})
    return updates,pending


def apply_state_updates(connection,parents,updates,write):
    for item in updates:
        fields=list(item['changes'])
        if write:
            with connection.cursor() as c:
                guard=' AND backNo <=> %s' if 'backNo' in fields else ''
                args=tuple(item['changes'][f] for f in fields)+(item['player_id'],item['before']['team'],item['before']['is_kbodle'])
                if guard:args+=(item['before']['backNo'],)
                c.execute('UPDATE kbo_player_data SET '+','.join('`'+f+'`=%s' for f in fields)+' WHERE player_id=%s AND team <=> %s AND is_kbodle <=> %s'+guard,args)
                if c.rowcount!=1:
                    raise ValueError('Concurrent player state change: '+str(item['player_id']))
        parents[item['player_id']].update(item['changes'])


def plan_number_updates(movements,parents,today,start):
    latest={};membership={};pending=[]
    for row in movements:
        pid=row.get('player_id')
        if pid not in parents or not row.get('event_date'):
            continue
        day=date.fromisoformat(str(row['event_date']))
        if day>today:
            continue
        if row['event_type'] in JOIN_TYPES or row['event_type']=='자유계약선수':
            membership[pid]=max(day,membership.get(pid,day))
        if row['event_type']!='등번호 변경':
            continue
        current=latest.get(pid)
        if current is None or day>current[0]:
            latest[pid]=(day,[row])
        elif day==current[0]:
            current[1].append(row)
    updates=[]
    for pid,(day,rows) in sorted(latest.items()):
        parent=parents[pid]
        if day<start or parent.get('is_kbodle') not in (1,2,4) or membership.get(pid,day)>day:
            continue
        teams={ALIASES.get((r.get('team') or '').strip(),(r.get('team') or '').strip()) for r in rows}
        current_team=ALIASES.get(parent.get('team'),parent.get('team'))
        if teams!={current_team}:
            pending.append({'reason':'number_change_team_mismatch','player_id':pid,'source_keys':[r['source_key'] for r in rows]})
            continue
        edges=set();invalid=False
        for row in rows:
            old,new=row.get('old_back_no'),row.get('new_back_no')
            if old is None or new is None:
                match=re.fullmatch(r'\s*#?(\d{1,3})\s*(?:→|->)\s*#?(\d{1,3})(?:번)?\s*',row.get('note') or '')
                if match:old,new=match.groups()
            if old is not None and new is not None and re.fullmatch(r'\d{1,3}',str(old)) and re.fullmatch(r'\d{1,3}',str(new)):
                edges.add((str(old),str(new)))
            else:invalid=True
        starts={old for old,_ in edges};ends={new for _,new in edges}
        # Same-day chains are accepted only when every edge forms one path.
        finals=ends-starts
        if invalid or len(finals)!=1 or len(edges)!=len(starts):
            pending.append({'reason':'ambiguous_number_change','player_id':pid,'source_keys':[r['source_key'] for r in rows]})
            continue
        final=next(iter(finals));path={old:new for old,new in edges}
        valid=True
        for old in starts:
            seen=set();point=old
            while point in path and point not in seen:
                seen.add(point);point=path[point]
            if point!=final:valid=False
        if not valid:
            pending.append({'reason':'ambiguous_number_change','player_id':pid,'source_keys':[r['source_key'] for r in rows]})
            continue
        if str(parent.get('backNo'))==final:
            continue
        source=next(r for r in rows if str(r.get('new_back_no'))==final or re.search(r'(?:→|->)\s*#?'+re.escape(final)+r'(?:번)?\s*$',r.get('note') or ''))
        updates.append({'player_id':pid,'source_key':source['source_key'],'event_date':str(day),'event_type':'등번호 변경','before':{f:parent.get(f) for f in ('team','is_kbodle','backNo')},'changes':{'backNo':final}})
    return updates,pending
