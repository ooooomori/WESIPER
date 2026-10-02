"""Final read-only checks, service health, and a reviewable annual report."""
import hashlib,json,subprocess
from pathlib import Path
import requests
from backfill_futures_history import ROOT,atomic
from backfill_kbo_early_official import connect
from write_futures_history import protected
con=connect();result=json.loads((ROOT/'verified-summary.json').read_text())
try:
 with con.cursor() as c:
  c.execute('SET SESSION max_statement_time=30')
  c.execute('SELECT @@innodb_buffer_pool_size,@@event_scheduler');memory,events=c.fetchone();result['runtime']={'buffer_bytes':int(memory),'event_scheduler':events}
  if int(memory)!=16*1024*1024 or events!='ON':raise ValueError('original runtime settings not restored')
  result['extra_checks']={k:0 for k in ('invalid_pitcher_order','player_on_both_teams','invalid_pitcher_record')}
  for year in sorted(result['years']):
   ids=json.loads((ROOT/year/'write-receipt.json').read_text())['committed_game_ids']
   for start in range(0,len(ids),100):
    batch=ids[start:start+100];scope='league_level=2 AND game_date>=%s AND game_date<%s AND game_id IN ('+','.join(['%s']*len(batch))+')';params=(year+'-01-01',str(int(year)+1)+'-01-01',*batch)
    queries={
     'invalid_pitcher_order':f"SELECT COUNT(*) FROM (SELECT game_id,team FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE {scope} GROUP BY game_id,team HAVING MIN(`order`)<>1 OR MAX(`order`)<>COUNT(*) OR COUNT(DISTINCT `order`)<>COUNT(*)) x",
     'player_on_both_teams':f"SELECT COUNT(*) FROM (SELECT game_id,player_id FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE {scope} GROUP BY game_id,player_id HAVING COUNT(DISTINCT team)>1) x",
     'invalid_pitcher_record':f"SELECT COUNT(*) FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE {scope} AND record IS NOT NULL AND record NOT IN ('승','패','홀','세')"}
    for label,sql in queries.items():
     c.execute(sql,params);count=int(c.fetchone()[0]);result['extra_checks'][label]+=count
     if count:
      print('additional check anomaly',year,label,count,flush=True)
      if label=='invalid_pitcher_record':
       c.execute(f"SELECT game_id,player_id,record FROM kbo_season_pitch_records FORCE INDEX (idx_pitch_league_game) WHERE {scope} AND record IS NOT NULL AND record NOT IN ('승','패','홀','세')",params);print('record values',c.fetchall(),flush=True)
      elif label=='player_on_both_teams':
       c.execute(f"SELECT game_id,player_id,GROUP_CONCAT(DISTINCT team) FROM kbo_season_records FORCE INDEX (idx_league_game_team_batting_index) WHERE {scope} GROUP BY game_id,player_id HAVING COUNT(DISTINCT team)>1",params);print('both teams',c.fetchall(),flush=True)
   print(year,'additional checks complete',flush=True)
  if any(result['extra_checks'].values()):raise ValueError('additional final validation failed')
 after=protected(con);before=json.loads((ROOT/'protected-before.json').read_text());atomic(ROOT/'protected-after-restoration.json',after)
 if after!=before:raise ValueError('outside-range counts changed after restoration')
 result['outside_counts_unchanged']=True
 config=Path('/opt/bitnami/mariadb/conf/bitnami/memory.conf');backup=config.with_name('memory.conf.wesiper-early-backfill-original')
 # Configuration is non-secret memory sizing. Record equality only, never contents.
 result['original_memory_configuration_restored']=subprocess.run(['sudo','-n','cmp','-s',str(config),str(backup)]).returncode==0
 if not result['original_memory_configuration_restored']:raise ValueError('memory configuration bytes differ')
 result['api_health']={}
 for endpoint in ('todayGames.php','teamRank.php'):
  response=requests.get('https://wesiper.xyz/api/'+endpoint,timeout=20);payload=response.json()
  error=isinstance(payload,dict) and bool(payload.get('error'))
  result['api_health'][endpoint]={'status':response.status_code,'valid_json':True,'error':error}
  if response.status_code!=200 or error:raise ValueError('API health check failed')
 totals={key:sum(int(v[key]) for v in result['years'].values()) for key in ('games','schedule_games','batter_rows','pitcher_rows','run_out')};result['totals']=totals
 result['pitcher_missing_games']=sorted({gid for year in result['years'].values() for gid in year['pitcher_missing_games']})
 unresolved=json.loads((ROOT/'unresolved-report.json').read_text());result['unresolved_count']=len(unresolved['failures']);result['unresolved_categories']=unresolved['categories']
 result['official_unlinked_schedule_rows']=len(unresolved['unlinked_schedule'])
 correction_report=ROOT/'user-correction-verification.json'
 if correction_report.exists():
  correction=json.loads(correction_report.read_text());result['user_corrected_games']=correction['games'];result['user_generic_out_rows']=correction['generic_out_rows']
 for year,stats in result['years'].items():
  plan=json.loads((ROOT/year/'dry-report.json').read_text())
  for key in ('games','batter_rows','pitcher_rows','run_out'):
   if int(stats[key])!=int(plan[key]):raise ValueError(f'{year}: final database/report mismatch {key}')
 atomic(ROOT/'verified-summary.json',result)
 pending=f"미해결 {result['unresolved_count']}경기는 저장하지 않았습니다." if result['unresolved_count'] else '경기코드가 있는 수집 대상의 미해결은 0건입니다.'
 lines=['# 퓨처스 2010~2021 운영 반영 결과','',f"{totals['games']:,}경기 반영 및 검증 완료. {pending}",'','| 연도 | 경기 수 | 타자 행 | 투수 행 | 주루사 합계 | 미해결 경기 |','|---|---:|---:|---:|---:|---:|']
 for year,s in sorted(result['years'].items()):lines.append(f"| {year} | {s['games']:,} | {s['batter_rows']:,} | {s['pitcher_rows']:,} | {s['run_out']:,} | {len(s['unresolved_games'])} |")
 if correction_report.exists():lines+=['',f"사용자 지정 보정으로 이전 미해결 {result['user_corrected_games']}경기를 추가 반영했습니다. 28경기의 빈 타석 29개는 사용자 지시에 따라 `아웃`으로 저장했으며, 공식 원문에서 세부 결과를 복원한 것은 아닙니다. 선수 ID·이름 오타·결승타 수정의 실제 저장 행을 따로 검증했습니다. 김경민은 공식 API의 김범 및 공식 일자별 기록과 대조해 ID 50013으로 확인했고 경기 행에는 당시 이름 김경민을 유지했습니다."]
 lines+=['',f"합계: 경기 {totals['games']:,}, 타자 행 {totals['batter_rows']:,}, 투수 행 {totals['pitcher_rows']:,}, 주루사 {totals['run_out']:,}.",'','투수 누락 경기: '+', '.join(result['pitcher_missing_games'])+'. 해당 경기의 타자 기록·타석 순서·일정은 저장했고 투수 테이블 및 상대 투수 컬럼만 비웠습니다.','','대상 범위 밖 1군 전체 및 퓨처스 2022~2026년 등의 연도별 행 수는 반영 전과 동일합니다. 필수 NULL 필드, 타석 순서, 중복, 투수 등판 순서, 한 선수의 양 팀 기록 검사는 모두 통과했습니다.','','DB 버퍼는 16MB, event_scheduler는 ON으로 복원했고 메모리 설정 파일은 원래 파일과 바이트 단위로 같습니다. 운영 API 2개는 HTTP 200·유효한 JSON·오류 없음입니다.','','공식 응답/HTML, 계획, 경기별 커밋 체크포인트는 서버 `/home/bitnami/wesiper/official-futures-2010-2021`에 연도별로 보관합니다. 추가 DB 임시 테이블을 만들지 않았습니다.','','전체 DB 백업은 반영 전 생성했고, 압축 전체 읽기·테이블 정의 목록·완료 표식·SHA-256 검증 및 별도 로컬 사본 검증을 마쳤습니다. 실제 복원 시험은 수행하지 않았습니다.','','미해결 상세: [목록](futures-2010-2021-unresolved.md). 공식 일정에 경기코드가 없는 행은 별도 목록에 있으며, 0-0의 완료 여부 불명 일정도 포함합니다.']
 (ROOT/'VERIFIED.md').write_text('\n'.join(lines)+'\n',encoding='utf-8');print(json.dumps({'totals':totals,'unresolved':result['unresolved_count'],'pitcher_missing_games':result['pitcher_missing_games'],'runtime':result['runtime'],'api_health':result['api_health']},ensure_ascii=False),flush=True)
finally:con.close()
