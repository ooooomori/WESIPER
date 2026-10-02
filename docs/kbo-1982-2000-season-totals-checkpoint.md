# KBO 1982~2000 시즌 합계 체크포인트

상태: 2026-10-01 운영 저장 및 최종 검증 완료. 미해결 수집/파싱 실패 0건.

서버 작업 디렉터리: `/home/bitnami/wesiper`

서버 캐시 루트: `/home/bitnami/wesiper/official-season-totals-1982-2000`

운영 비공개 환경변수 파일은 기존 `/home/bitnami/.config/wesiper/crawler.env`를 사용한다. 내용을 출력하거나 저장소에 복사하지 않는다.

완료 순서:

1. `collect_official_old_seasons.py --start-year 1982 --end-year 2000 --team-splits`: 1,158개 조회 그룹 완료. 연도/역할/시리즈/팀별 HTML gzip과 manifest를 저장하고 완료 그룹은 재요청하지 않는다.
2. `validate_official_old_seasons.py`: 타자 합계 5,014건, 투수 합계 2,768건. 팀 필터 결과도 각각 같은 건수이며 조회 합계 불일치 0건. 원문에 없는 기록은 NULL.
3. `import_official_old_seasons.py --write`: 1,158개 계획 커밋 완료. 타자 새 테이블 10,028행, 투수 새 테이블 5,536행. 공식 부모 ID 20001 우용득 1명 신규 등록.
4. `verify_official_old_seasons.py`: 저장된 모든 컬럼 15,564행 대조 및 공식 페이지 795개 SHA-256 대조 통과. 기존 경기 3개 테이블의 전체 리그·연도별 행 수 동일. 운영 API 정상.

기록:

- `collection-summary.json`: 완료/실패 목록.
- `validation-report.json`: 연도별 커버리지, 타입 검사, 검증 계획 경로.
- `write-receipt.json`: 각 커밋 그룹의 계획 SHA-256. 재개 시 동일 계획은 건너뛰고 변경된 계획은 덮어쓰지 않는다.
- `write-summary.json`: 운영 연도·시리즈·조회 범위별 저장 건수.
- `protected-game-tables-before.json`, `protected-game-tables-after.json`: 기존 경기 3개 테이블의 행 수 보호 검증.
- `parent-missing-position.json`: 공식 현재 프로필의 포지션이 없는 우용득 처리 근거. 부모 포지션은 빈 문자열, 현역 표시 0.
- `final-verification.json`: DB 값, 원문 해시, 운영 API, DB 실행 상태 검증.

현재 DB 버퍼 16MB, event_scheduler=ON. 이번 작업에서 설정 변경·재시작·기존 경기 수정·임시 테이블 생성은 없었다. 일일 크롤러 동작을 바꾸지 않았다.

통산 정규시즌 집계는 `league_level=1 AND row_scope='total' AND series_id=0`을 적용한다. 팀 필터 조회 행까지 더하면 중복 집계되므로 합산하지 않는다. 2001년 이후 경기 데이터와 연결하는 화면/API 구현은 이번 수집 작업에 포함하지 않았다.

연도별 상세 결과는 [결과 보고서](kbo-1982-2000-season-totals-results.md)에 있다.
