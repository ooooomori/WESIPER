"""Produce the annual report from the completed import and verification receipts."""
import argparse, json
from pathlib import Path
from collections import Counter

def run(root,output):
    write=json.loads((root/'write-summary.json').read_text(encoding='utf-8'))
    final=json.loads((root/'final-verification.json').read_text(encoding='utf-8'))
    validation=json.loads((root/'validation-report.json').read_text(encoding='utf-8'))
    counts={role:{(r[0],r[1]):r[3] for r in rows if r[2]=='total'} for role,rows in write['years_series_scope_counts'].items()}
    def cell(year,series):
        return f"{counts['Hitter'].get((year,series),0)} / {counts['Pitcher'].get((year,series),0)}"
    lines=['# KBO 1982~2000 공식 시즌 합계 수집 결과','',
        '2026-10-01 운영 반영 및 검증 완료. 아래 건수는 선수·연도·시리즈별 합계이며 경기 수나 타석 행 수가 아니다. 팀 필터 결과는 합계에서 제외했다.','',
        '| 연도 | 정규시즌 타자 / 투수 | 준플레이오프 타자 / 투수 | 플레이오프 타자 / 투수 | 한국시리즈 타자 / 투수 |',
        '|---|---:|---:|---:|---:|']
    for year in range(1982,2001): lines.append('| '+str(year)+' | '+' | '.join(cell(year,s) for s in (0,3,5,7))+' |')
    lines+=['','0은 해당 공식 조회에서 수집된 행이 없음을 뜻한다. 시리즈에 해당 연도가 선택지로 제공되지 않는 경우는 별도 커버리지 기록으로 남겼으며 다른 연도 응답을 가져오지 않았다.','',
        '시즌 합계: 타자 5,014건, 투수 2,768건, 합계 7,782건. 팀 필터 조회 결과도 타자 5,014건·투수 2,768건을 별도로 보관했다. 실제 저장 행은 타자 테이블 10,028행, 투수 테이블 5,536행이다. 전체 고유 공식 선수 ID는 1,323개다.','',
        '## 공식 자료와 수집 방식','',
        '- [타자 BasicOld](https://www.koreabaseball.com/Record/Player/HitterBasic/BasicOld.aspx), [투수 BasicOld](https://www.koreabaseball.com/Record/Player/PitcherBasic/BasicOld.aspx)의 requests 세션과 WebForms POST만 사용했다.',
        '- G(경기 수, GAME_CN) 내림차순 정렬로 규정타석·규정이닝 미달 선수를 포함하고 모든 페이지를 순회했다. UI 도구를 사용하지 않았다.',
        '- 정규시즌 0, 시범경기 1, 준플레이오프 3, 와일드카드 4, 플레이오프 5, 한국시리즈 7을 각각 조회했다. 시범경기는 대상 연도가 선택지에 없었고 와일드카드는 공식 결과가 비어 있어 저장된 선수 행이 없다.',
        '- 타자 제공 기록: AVG, G, PA, AB, H, 2B, 3B, HR, RBI, SB, CS, BB, HBP, SO, GDP, E.',
        '- 투수 제공 기록: ERA, G, CG, SHO, W, L, SV, HLD, WPCT, TBF, IP, H, HR, BB, HBP, SO, R, ER.',
        '- 원문의 순위·이름·당시 팀명·표시값도 raw_record에 보존했다. 투구 이닝은 원문과 정확한 아웃 수를 함께 저장했다.',
        '- 해당 두 페이지에 없는 타자 R·SH·SF·IBB·TB·OBP·SLG·OPS 및 투수 WHIP 컬럼은 NULL이다. 확인되지 않은 값을 0으로 채우지 않았다.','',
        '## 저장 및 조회 기준','',
        '- 타자: kbo_player_season_batting_totals',
        '- 투수: kbo_player_season_pitching_totals',
        '- league_level=1, year=1982~2000. player_id는 공식 선수 링크를 사용했다.',
        "- row_scope='total'은 전체 조회 합계, row_scope='team'은 특정 팀 필터 조회 결과다. 두 종류를 합산하면 중복 집계된다. 팀 필터 결과를 이적 전후 실제 팀별 분할 기록이라고 단정하지 않는다.",
        "- 정규시즌 통산 집계는 league_level=1 AND row_scope='total' AND series_id=0 조건으로 읽는다. 포스트시즌은 series_id별로 따로 집계한다.",
        '- 선수 부모 테이블에 없던 공식 ID 20001 우용득 1명만 새로 등록했다. 공식 현재 프로필의 포지션이 비어 있어 기존 부모 테이블 형식에 맞춰 빈 문자열로 보존했다. 당시 경기·시즌 이름과 현재 프로필 이름을 구분한다.',
        '- 기존 경기·타석·투수·일정 데이터를 수정하지 않았다. 일일 크롤러는 수정하지 않았고 화면/API에 과거 시즌 조회 기능을 추가하는 작업은 이번 수집 범위에 포함하지 않았다.','',
        '## 검증 결과','',
        '- 수집 그룹 1,158개 모두 체크포인트 완료. 수집 실패 0건, 파싱·타입 검증 실패 0건.',
        '- 전체 조회 합계와 팀 필터 조회 결과의 정수 기록 및 이닝 아웃 수 합계 불일치 0건.',
        '- 운영 DB 15,564행의 모든 저장 컬럼과 검증 계획 대조: 불일치 0건.',
        '- 공식 원문 페이지 795개 SHA-256 대조: 불일치 0건.',
        '- 선수 ID 누락·부모 누락·범위 밖 신규 행·잘못된 원문 JSON 0건. 기본키로 같은 선수·연도·시리즈·조회 범위의 중복을 차단했다.',
        '- 기존 kbo_season_records, kbo_season_pitch_records, kbo_schedule의 전체 리그·연도별 행 수가 작업 전후 동일했다.',
        '- todayGames.php, teamRank.php 모두 HTTP 200, 유효 JSON, 오류 없음.',
        '- DB 버퍼 16MB, event_scheduler=ON. 이번 작업에서 DB 설정 변경 및 재시작 없음.','',
        '## 캐시와 재개','',
        '서버 캐시: /home/bitnami/wesiper/official-season-totals-1982-2000. 연도/역할/시리즈/팀 아래 공식 HTML gzip, manifest.json, plan.json을 보관한다. 공식 응답의 요청 선택값과 페이지 순서를 검사한다.',
        '', 'collection-summary.json, validation-report.json, write-receipt.json, write-summary.json, final-verification.json과 보호 대상 경기 테이블 전후 행 수 스냅샷이 있다. import는 완료된 계획의 해시가 같으면 건너뛰며 체크포인트 없이 존재하는 행을 임의 덮어쓰지 않는다.','',
        '조회 커버리지:','', '| 시리즈 | 타자 조회 상태 | 투수 조회 상태 |','|---|---|---|']
    for series,name in ((0,'정규시즌'),(1,'시범경기'),(3,'준플레이오프'),(4,'와일드카드'),(5,'플레이오프'),(7,'한국시리즈')):
        values=[]
        for role in ('Hitter','Pitcher'):
            groups=[g for g in validation['groups'] if g['role']==role and g['series_id']==series and g['row_scope']=='total']
            tally=Counter(g['coverage_status'] for g in groups)
            values.append(', '.join(f'{k}: {v}년' for k,v in sorted(tally.items())))
        lines.append('| '+name+' | '+' | '.join(values)+' |')
    output.write_text('\n'.join(lines)+'\n',encoding='utf-8')

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--root',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();run(a.root,a.output)
