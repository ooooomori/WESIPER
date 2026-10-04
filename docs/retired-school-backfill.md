# 은퇴 선수 출신학교 보충

2026-10-04 KBO 공식 은퇴 선수 프로필의 출신교를 대조해 빈 `kbo_player_data.school`을 보충했다.

- 대상: `is_kbodle=0`, 학교 값 NULL 또는 공백, player_id가 4자리인 선수 제외.
- 대상 4,376명 모두 조회, HTTP 수집 실패 0건.
- 4,161명 업데이트. 기존 학교 값 및 4자리 선수는 보존.
- 미입력 215명: 프로필/출신교가 비어 있는 169명, 국가·소속팀만 표시되어 학교를 확인할 수 없는 46명. 169명은 KBO 퓨처스 선수 프로필도 조회했으나 추가 학교 정보가 없었다.
- 학교·리틀야구단 표기를 보존하고 프로·실업팀 경력을 제외했다. 해외 학교의 괄호와 Smithfield-Selma의 하이픈을 보존했다. 동대문상·덕수상처럼 공식 페이지의 축약 표기도 그대로 사용했다.
- 선수 ID에 연결된 공식 이름을 DB 이름·이전 이름·전체 이름과 대조했고, 개명으로 이름이 다르면 생년월일로 검증했다. 학교 외에 이름과 생년월일은 수정하지 않았다.

수집·정리·반영 스크립트는 `deploy/collect-retired-schools.py`, `deploy/retry-retired-schools.py`, `deploy/prepare-retired-schools.py`, `deploy/apply-retired-schools.php`이다. 원본 HTML gzip, 출처 URL·SHA-256, 변경 전 값, 입력 명단과 미입력 명단, 검증 결과는 `output/retired-schools/`에 저장했다. SQL 변경은 트랜잭션으로 처리했고, 전체 선수의 학교·이름·은퇴 분류 스냅샷을 대조해 계획된 학교 값 이외의 변경이 없음을 확인했다.
