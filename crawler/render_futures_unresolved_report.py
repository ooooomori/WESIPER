import json
from backfill_futures_history import ROOT
data=json.loads((ROOT/'unresolved-report.json').read_text());questions=json.loads((ROOT/'allstar-player-identity-questions.json').read_text())
lines=['# 퓨처스 2010~2021 미해결 공식 기록','',f"경기코드가 있는 경기의 미해결은 {len(data['failures'])}건입니다. 사용자 지정 보정은 별도 기록으로 보관합니다.",'', '## 경기별 미해결 사유','', '| 연도 | 경기코드 | 구분 | 사유 |','|---|---|---|---|']
for f in data['failures']:lines.append(f"| {f['year']} | {f['game_id']} | {f['category']} | {f['error'].replace('|','/')} |")
lines+=['','## 올스타전 선수 ID','']
if questions:
 lines+=['공식 일자 기록에는 올스타전 일자 기록이 없어 아래 동명이인을 확정하지 못했습니다.','', '| 경기코드 | 연도 | 팀 | 이름 | 역할 | 후보 player_id |','|---|---|---|---|---|---|']
else:
 lines+=['2017·2018년 올스타전 선수 ID는 사용자 제공 소속 명단과 공식 경기 기록을 대조해 확정했습니다. LG 김태형은 공식 2018년 출전 자격(2014년 이후 입단)과 두 후보의 공식 프로필 입단년도(67122: 18LG, 62918: 12NC)를 대조했습니다. 경기 당시 이름 박주홍과 현재 이름 박성웅은 동일 ID 68703으로 확인했습니다. 두 올스타전의 단건 검증과 운영 반영을 완료했습니다.']
for q in questions:
 if 'candidates' in q:lines.append(f"| {q['game_id']} | {q['year']} | {q['team']} | {q['name']} | {q['role']} | {', '.join(map(str,q['candidates']))} |")
lines+=['','## 공식 일정에 경기코드가 없는 행','', '0-0이며 완료 여부가 불명확한 일정도 포함되어 있습니다. 경기코드를 임의로 만들지 않았습니다.','', '| 날짜 | 원정 | 홈 | 점수 |','|---|---|---|---|']
for u in data['unlinked_schedule']:lines.append(f"| {u['game_date']} | {u['away_team']} | {u['home_team']} | {u['away_score']}-{u['home_score']} |")
(ROOT/'UNRESOLVED.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print('unresolved report rendered',len(data['failures']),len(questions))
