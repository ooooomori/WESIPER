# 외국인 선수와 수상·대표팀 경력 통합

2026-09-28 운영 DB와 운영 API에 적용했습니다. 선수는 기존 6,002명을 유지하며, 외국인 등록 선수 559명과 경력 2,904건을 반영했습니다. 최초 경력 2,744건에 사용자가 제공한 올스타 160건을 추가했습니다. `search_test.php`는 프로젝트와 운영 서버에서 삭제했습니다.

## 선수 컬럼

- `is_foreign`: KBO 외국인 선수 등록 기준에 해당하면 `1`, 나머지는 `NULL`입니다. 아시아쿼터·대체 외국인·울산 웨일즈 등록 외국인을 포함합니다. 국적만으로 분류하지 않아 김일융·황목치승 등은 `NULL`입니다. 선수로 등록하지 않은 외국인 감독·코치와 해외팀 교류 경기 선수도 제외했습니다.
- `fullname`: 외국인 선수의 기존 비어 있지 않은 `oldname` 337건을 그대로 옮겼으며 해당 `oldname`을 `NULL`로 설정했습니다. 국내 선수의 개명 이력은 `oldname`에 유지했습니다.
- `is_WBC`, `is_GG`, `is_AS`: `kbo_player_data`의 실제 컬럼에서 제거했습니다. 기존 응답에 필요한 정보는 `kbo_player_career`를 조회합니다.

외국인 판정 근거는 KBO 공식 프로필의 지명순위·등록 정보를 대조한 `data/player-career/foreign-registration-sources.json`에 있습니다. 이름 형태는 조사 후보를 찾는 데만 사용했습니다.

## 경력 테이블

`kbo_player_career`의 컬럼은 `(PK, player_id, category, type, team, year, month, pos, note)`입니다. PK는 1부터 증가하는 기본 키이고 `player_id`는 선수 테이블을 참조하는 외래 키입니다.

| 종류 | 건수 | 반영 범위 |
| --- | ---: | --- |
| MVP | 44 | 1982~2025 |
| 신인왕 | 43 | 1983~2025 |
| 골든글러브 | 439 | 1982~2025 |
| 수비상 | 28 | 2023~2025 |
| 올스타 | 1,986 | 제공 시트의 1982~2023 참가 이력 + 사용자 제공 2024~2026 명단 + 공식 올스타 MVP 명단 |
| 월간 MVP | 95 | 제공 시트의 2005~2023 수상 이력 |
| 한국시리즈 MVP | 43 | 1982~2025, 한국시리즈가 없었던 1985년 제외 |
| WBC | 226 | 기존 `is_WBC=1` 159명, 2006·2009·2013·2017·2023·2026 |

올스타의 2024~2026년은 사용자 명단을 추가했습니다. 2024년 52건, 2025년 59건, 2026년 49건입니다. 기존 공식 MVP 수상자 3명의 행도 유지했습니다. 2025년 김호령의 중복 기재는 한 행으로 저장했고, `성양탁`은 공식 프로필의 성영탁(54610)으로 연결했습니다. 키움 유토는 카나쿠보 유토(56348)이며 울산 유토(31004)와 구분했습니다. **2024년 이후 월간 MVP 전체 명단은 이번 반영 범위에 포함하지 않았습니다.**

같은 선수가 여러 연도에 선정되면 각 연도를 별도 행으로 저장했습니다. 월간 MVP는 같은 연도라도 월별로 별도 행입니다. 동일한 선정 이력이 여러 카드에 반복되는 경우만 중복을 제거했습니다. 올스타 MVP는 해당 올스타 행의 `note='MVP'`로 합쳤으며 총 44건입니다.

`category`는 `award` 또는 `national`입니다. 국가대표는 `type='WBC'`, `team=NULL`입니다. 수상 팀은 당시 팀 이름을 보존합니다. `pos`는 골든글러브·수비상에만, `month`는 월간 MVP에만 값이 있습니다. 나머지는 `NULL`입니다. `note`는 올스타 MVP를 제외하면 `NULL`입니다.

WBC 조사 대상은 원래 플래그가 1이었던 159명으로 한정했습니다. 선수 이름·생일과 공식 대표팀 명단을 대조했고 외국인은 영문 이름도 연결했습니다. 연도 미상 행은 없으며 `wbc-unknown-years.json`은 빈 배열입니다. 토마스의 기존 DB 생일과 MLB 프로필 생일 차이는 명단 식별에만 보정하고 원래 DB 값은 변경하지 않았습니다.

## 출처와 선수 연결

- [KBO MVP·신인왕](https://www.koreabaseball.com/Player/Awards/PlayerPrize.aspx)
- [KBO 골든글러브](https://www.koreabaseball.com/Player/Awards/GoldenGlove.aspx)
- [KBO 수비상](https://www.koreabaseball.com/Player/Awards/DefensePrize.aspx)
- [KBO 올스타·한국시리즈 MVP](https://www.koreabaseball.com/Player/Awards/SeriesPrize.aspx)
- [사용자가 제공한 실제 링크의 올스타·월간 MVP 시트](https://docs.google.com/spreadsheets/d/1kz0EGnWSeUljsjTD9WxLfDm4dg4_5lLOcYjIxu4squQ/edit): 타자·투수 탭 모두 사용했습니다. B열 `ASG`와 `MMVP`를 구분하고 월 정보를 읽었습니다.
- [MLB 공식 Stats API](https://statsapi.mlb.com/api/v1/teams?sportId=51&season=2026): 대회 연도별 팀·선수 명단과 생일. 한국 대표팀은 KBO 대회 페이지를 함께 대조했습니다.

각 경력의 원본 URL은 `data/player-career/sources.json`에 저장했습니다. 원본 선수 이름 매핑은 `deploy/player-career-identities.py`에 있습니다. 사용자가 알려준 가명을 반영했으며 최종 정정은 다음과 같습니다.

- 장젝프 → 장재중
- 이인스 → 이진(89220)
- 김터로 → 김정수(83147)
- 조에컬 → 조현(95103), 송렉블 → 송경섭, 김프로 → 김경남(95777)
- 김젝컬 → 김대우(78536), 김트업 → 김재현(82303), 김터컬 → 김태균(94415)

연결 실패 항목은 없습니다. 동명이인은 팀·연도·학력과 지정 ID를 대조했습니다.

## API와 호환성

- 빙고 `search.php`는 경력 테이블을 한 번에 조회해 WBC·골든글러브·올스타 조건을 기존 응답 형식으로 제공합니다.
- 크보들·캔들·빙고 선수 검색에 `fullname`을 추가했습니다.
- `playerProfile.php`는 기존 필드에 `FullName`, `IsForeign`, `career` 배열을 추가했습니다. `career`에는 각 선정 이력이 별도 항목으로 반환됩니다. 운영 미리보기 프로필도 동일하게 수정했습니다.
- 투수 기록 보완 크롤러의 이름 검색도 `fullname`을 확인합니다.
- 기존 `kbo_playerlist_20250613` 호환 뷰는 경력 테이블에서 플래그를 계산합니다. 기존 `player_data` 호환 뷰의 이름 조회는 `oldname`과 `fullname`을 함께 확인합니다.
- `search_test.php`를 호출하는 프런트엔드 코드가 없음을 확인했습니다. 삭제 주소는 현재 Apache의 SPA 기본 경로 규칙에 따라 HTML 페이지로 응답하며 선수 검색 API로 실행되지 않습니다.

## 검증과 백업

운영 DB의 2,904개 경력 전체를 준비 JSON과 비교했습니다. PK·선수 ID·수상 팀·연도·월·포지션·비고가 모두 일치합니다. 올스타 추가 시 원래 2,744건의 PK와 값을 유지하고 새 PK 2745~2904를 사용했습니다. 외국인 559명과 이름 이동 337건도 원본 백업과 대조했습니다. 기존 WBC 대상 159명, 선수 수 6,002명, 타이틀홀더 660건, 해외·울산 선수 상태를 유지했습니다. 사용자가 수정한 `KBODLE_GEN` 정의는 변경하지 않았습니다.

PHP 구문 검사와 기존 API 12개 비교, 14명 프로필의 경력 전체 비교, 외국인 전체 이름 검색, 크보들 정답·로스터·검색, 잘못된 ID 응답 검증을 통과했습니다. 컬럼 제거·경력 필드 규칙·호환 뷰도 확인했습니다. 이번 작업의 임시 PHP 검증 서버 두 개는 종료했습니다.

올스타 보완 후에는 요청한 160개 이력을 선수 121명의 공개 프로필 API에서 모두 확인하고 경력 전체를 비교했습니다. 빙고의 올스타 팀 조건도 통과했습니다. 최초 백업 이후 선수 76231(이승엽)의 `school` 1건이 달라져 최초 이관의 엄격한 `--verify`는 이 차이를 보고합니다. 이번 보완 스크립트는 선수 테이블을 수정하지 않으며 현재 학교 값을 유지했습니다. 올스타 보완의 `--verify`와 스키마 검증은 별도로 사용할 수 있습니다.

DB 원본 백업은 `kbo_player_data_backup_career_20260928_090034`입니다. 올스타 추가 전 경력 백업은 `kbo_player_career_backup_allstar_20260928_095813`입니다. 서버 비공개 릴리스는 `/home/bitnami/wesiper-player-career-20260928/`이며 상태는 `deploy/player-career-state.json`의 `phase='complete'`입니다. 이 상태 파일에는 원본 DDL·뷰·이벤트 정의도 보관했습니다. API·크롤러 파일 백업은 릴리스의 `backups/`에 있습니다. 올스타 원문·연결 결과는 `data/player-career/allstar-user-2024-2026.txt`와 `.json`에 있습니다. 이 문서는 로컬에만 보관합니다.

재검증:

```bash
cd /home/bitnami/wesiper-player-career-20260928
sudo /opt/bitnami/php/bin/php deploy/import-player-career.php --verify
sudo /opt/bitnami/php/bin/php deploy/verify-player-career-schema.php
python3 deploy/test-player-career.py
sudo /opt/bitnami/php/bin/php deploy/add-player-allstars.php --verify
python3 deploy/test-user-allstars.py
```

검증은 적용 당시 JSON·백업을 기준으로 하므로 이후 선수를 추가하거나 수정하면 차이를 보고합니다. 복구할 때는 후속 변경을 먼저 보관하고 DB 스키마·선수 데이터·호환 뷰·API를 함께 복구해야 합니다. 이전 선수 통합과 타이틀홀더 반영 내역은 `player-data-migration.md`, `player-history-import.md`에 있습니다.
