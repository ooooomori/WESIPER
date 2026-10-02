# 국가대표 출전 국가

`kbo_player_career.country`: nullable `VARCHAR(30)`, team 다음 컬럼.

- category=national은 출전 대표팀을 한국어로 저장한다. 나머지 category는 NULL.
- 외국인 등록 여부나 출생 국적을 country로 사용하지 않는다. WBC는 해당 연도의 대표팀 로스터를 확인한다.
- 국가대표 674건: 한국 616건, 해외 대표팀 58건. 해외 58건에는 외국인 등록 선수의 WBC 56건과 주권의 중국 대표팀 WBC 2건이 포함된다.
- 연도별 예외와 출처: `data/player-career/national-countries.json`.
- 이름·생년월일·연도별 MLB 공식 로스터 36개에서 56건을 재확인했다. 데스파이네(2013 쿠바)와 노바(2017 도미니카공화국)는 별도 로스터 문서로 확인했다.
- 국내 국가대표 기록은 한국으로 저장했다. 기존 경력의 PK, player_id, category, type, team, year, month, pos, note 값은 유지한다.

## 실행

로컬 DB 환경 설정을 로드한 PHP CLI에서 `deploy/migrate-player-career-country.php`를 실행하면 미리보기만 수행한다. `--apply`를 주면 컬럼을 추가하고 국가를 채운다. 외국인 국가대표 기록이 매핑 파일에 없으면 적용 전에 중단한다.

적용 전 전체 경력 스냅샷은 git에서 제외되는 `.player-career/country-before-*.json`에 보관한다. 데이터 변경은 트랜잭션으로 실행하고, 다른 경력 컬럼이 동일한지 검사한 뒤 커밋한다.

## 공식 출처 예시

- MLB 연도별 로스터 API: https://statsapi.mlb.com/api/v1/teams/784/roster?season=2026&rosterType=fullSeason&hydrate=person
- 캐나다 2026 대표팀 발표: https://baseball.ca/baseball-canada-announces-2026-world-baseball-classic-roster
- 중국 대표 주권: https://www.mlb.com/player/kwon-ju-673486
- 도미니카공화국 2017 대표팀: https://www.mlb.com/amp/news/dominican-republic-wbc-17-roster-announced-c215434216.html

## 대표팀별 WBC 성적

WBC note는 country와 year를 함께 사용한다. 한국 대표팀 성적을 다른 대표팀 기록에 적용하지 않는다. MLB 공식 대회 일정의 최종 경기로 우승·준우승·4강·8강·2라운드 탈락·1라운드 탈락을 확인한다. 기존 한국 성적은 유지했다.

- 검증 자료: `data/player-career/wbc-country-results.json` (공식 출처 URL, 최종 경기 gamePk 포함).
- 미리보기 및 적용: `deploy/migrate-wbc-country-results.php`, `--apply`.
- 적용 시 해외 대표팀 WBC 59건을 확인해 37건의 note를 수정했다. 새로 등록된 타케다의 일본 대표팀 country도 보완했다. 그 외 경력은 유지했다.
- API 경력 응답은 country를 포함하고, 상세 국가대표 경력에서 나라·성적을 함께 표시한다.
