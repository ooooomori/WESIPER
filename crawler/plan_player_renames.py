"""Plan current names and previous-name chains using cached official rename events."""
import json,re
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from collect_player_movements import ROOT,atomic
from plan_player_movement_numbers import profile,team
from player_identity_corrections import official_rename_id

def run():
 parents=json.loads((ROOT/'players-before.json').read_text());parent={p[0]:p for p in parents};names=defaultdict(set)
 for p in parents:
  names[p[1]].add(p[0])
  for alias in re.split(r'[,/;·\s()]+',p[2] or ''):
   if alias:names[alias].add(p[0])
 events=json.loads((ROOT/'renames.json').read_text());byid=defaultdict(list);unresolved=[]
 for event in events:
  new=re.sub(r'\([^)]*\)$','',event['player_text']).strip();old=re.sub(r'^개명\s*전\s*[:：]\s*','',event['note']).strip();old=re.sub(r'\([^)]*적용\)','',old).strip();event.update(new_name=new,old_name=old)
  a=names.get(new,set());b=names.get(old,set());both=a&b
  candidates=both or (a if len(a)==1 else b if len(b)==1 else a|b)
  confirmed=official_rename_id(event,old,new)
  if confirmed is not None:
   p=profile(confirmed)
   if confirmed not in parent or p['name']!=new or event['team'] not in (p['draft'] or ''):raise ValueError('Official rename identity evidence changed')
   candidates={confirmed}
  if len(candidates)!=1:
   matches=[]
   for pid in candidates:
    if pid<10000:continue
    try:
     p=profile(pid)
     draft=re.match(r'(\d{2}|\d{4})\s',p['draft'] or '');debut=int(draft[1]) if draft else None
     if debut is not None and debut<100:debut+=2000 if debut<50 else 1900
     if debut is not None and debut>event['year']:continue
     if p['name']==new and (p['team']==team(event['team']) or event['team'] in (p['draft'] or '')):matches.append(pid)
    except Exception:continue
   candidates=set(matches)
  if len(candidates)==1:
   pid=next(iter(candidates));event['player_id']=pid;byid[pid].append(event);names[new].add(pid);names[old].add(pid)
  else:unresolved.append(event|{'candidate_player_ids':sorted(a|b)})
 updates=[];already=[];failures=[]
 for pid,history in byid.items():
  try:
   p=profile(pid);history.sort(key=lambda e:e['event_date']);latest=history[-1];before=parent[pid]
   chain={e[k] for e in history for k in ('old_name','new_name')}
   current=latest['new_name'] if p['name'] in chain else p['name']
   aliases=[]
   for name in [e['old_name'] for e in history]+re.split(r'[,/;·\s()]+',before[2] or '')+([before[1]] if before[1]!=current else []):
    if name and name!=current and name not in aliases:aliases.append(name)
   oldname=', '.join(aliases) or before[2]
   # Existing parent schema limits oldname to 11 characters. Preserve the
   # immediate previous official name if the complete chain does not fit.
   if oldname and len(oldname)>11:oldname=latest['old_name']
   item={'player_id':pid,'before_name':before[1],'before_oldname':before[2],'name':current,'oldname':oldname,'profile':p,'events':history}
   (already if before[1]==current and before[2]==oldname else updates).append(item)
  except Exception as e:failures.append({'player_id':pid,'error':str(e)})
 result={'updates':updates,'already_current':already,'unresolved':unresolved,'failures':failures,'official_rename_records':len(events)};atomic(ROOT/'rename-plan.json',result)
 print(json.dumps({k:len(v) if isinstance(v,list) else v for k,v in result.items()},ensure_ascii=False));print('unresolved',json.dumps(unresolved,ensure_ascii=False));print('updates',json.dumps([{k:r[k] for k in ('player_id','before_name','before_oldname','name','oldname')} for r in updates],ensure_ascii=False))
if __name__=='__main__':run()
