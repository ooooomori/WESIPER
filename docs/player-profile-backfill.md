# KBO 선수 기본 정보 보완

## 2026-10-02 적용 결과

### 투타 형식 및 선수 식별 정정

최초 수집에서 `bat`과 일반 투구 방향의 접미사를 빠뜨렸다. `deploy/normalize-player-hands.php`로 4,067명의 `bat` 4,067셀, `throw` 4,066셀을 `우타/좌타/양타`, `우투/좌투`로 정정했다. 기존 `우언`, `우사`는 유지했고 한 글자 방향 값은 0건이다. 수정 대상 전체 행의 나머지 컬럼이 동일한지 트랜잭션 안에서 검증했다. 변경 전 압축 증거는 `.identity-repairs/hands-before-20261002_010028.json.gz`이며 추가 백업 테이블을 만들지 않았다. HTML 캐시는 유지했다.

누락 보완 파서와 신규 선수 수집 파서를 같은 두 글자 형식으로 수정했다. 신규 수집 파서는 운영 서버에 적용했고 우투우타·좌투좌타·우언우타·우사우타·우투양타를 검증했다. 보완 적용 스크립트도 한 글자 투타 입력을 거부한다. 기존 보완 목록 JSON은 당시 실행 증거이므로 유지하며, 다시 적용할 때는 수정한 파서로 목록을 생성해야 한다.

아래 최초 실행 기록의 “김태욱(62349)”는 잘못된 개명 연결이었다. 공식 프로필에 따라 62349는 김병현(1979-01-19), 67768은 김태욱(1998-04-15, 이전 이름 김병현)으로 정정했다. 현재 API 반환값은 각각 `우언/우타`, `좌투/좌타`다. 상세 수정 내역은 `docs/player-identity-repair.md`에 기록했다.

추가 요청에 따라 제외했던 두 선수도 공식 페이지를 다시 조회했다. 현재 DB의 `oldname`과 KBO 선수명, 생년월일이 모두 일치해 기존 이름을 유지한 채 빈 항목 6개를 보완했다. 김태욱(62349)은 `bat=우`, `throw=우언`, `draft=07 해외진출선수 특별지명`, 배지환(65948)은 `bat=우`, `throw=좌`, `draft=15 NC 2차 9라운드 86순위`다. 언더핸드 표기를 보존하도록 수집 파서도 보완했다. 추가 백업은 `kbo_player_data_backup_profile_20261001_233932`이며 적용 값 및 기존 값 보존 검증을 통과했다. 남은 누락 선수는 2,448명이다.

누락 항목이 있던 4,896명의 공식 페이지를 조회했다. 4,172명의 빈 셀 10,755개를 반영했으며 `bat` 4,021개, `throw` 4,021개, `draft` 2,607개, `birth` 48개, `body` 58개다. 지명순위가 없는 경우 입단년도를 사용했고, 조진호(73830)의 `draft`는 `03 SK`로 반영했다.

백업은 `kbo_player_data_backup_profile_20261001_233414`다. 모든 적용 값 일치, 기존 비어 있지 않은 값·다른 컬럼 보존, 선수 수 유지를 검증했다. 작업 도중 이름이 달라진 62349(김병현→김태욱), 65948(배준빈→배지환)은 제외했다.

남은 누락은 2,450명이며 `bat` 861개, `throw` 861개, `draft` 2,284개, `birth` 1개, `body` 1,400개다. 이 중 541명은 KBO 공식 페이지에 선수명이 없는 페이지였고, 나머지는 공식 프로필에도 해당 값이 없거나 위의 이름 변경 제외에 해당한다. 조회 오류에 의한 수집 실패는 없었다.

`deploy/fetch-missing-player-profiles.py`는 `bat`, `throw`, `draft`, `birth`, `body` 중 NULL 또는 공백인 항목이 있는 선수의 공식 KBO 페이지를 조회한다. 투수와 타자 모두 `HitterDetail/Basic.aspx?playerId=<player_id>`를 사용한다.

- 포지션의 투타 방향에서 `throw`, `bat`을 추출한다.
- 생년월일은 `YYYY-MM-DD`, 신장/체중은 KBO의 `180cm/90kg` 형식을 사용한다.
- `draft`는 지명순위를 우선 사용하고, 없으면 입단년도를 사용한다. `03SK`는 `03 SK`로 정리한다.
- 기존 값은 유지한다. 공식 페이지에서 확인할 수 없는 값은 추정하지 않는다.
- 이름 변경은 기존 이름·이전 이름 또는 동일한 생년월일로 검증한다.

## 실행

기존 로컬 PHP 런타임과 DB 터널을 사용한다. 비공개 설정은 저장소에 넣지 않는다.

```powershell
$taskRuntime = Join-Path $env:TEMP 'wesiper-local-dev'
$taskPhp = Join-Path $taskRuntime 'php/php.exe'
$taskIni = Join-Path $taskRuntime 'php.ini'
$taskDb = Join-Path $taskRuntime 'local-db.php'
New-Item -ItemType Directory -Path .player-profile-backfill -Force | Out-Null
& $taskPhp -c $taskIni deploy/backfill-player-profile.php --export $taskDb .player-profile-backfill/missing.json
python deploy/fetch-missing-player-profiles.py
& $taskPhp -c $taskIni deploy/backfill-player-profile.php --apply $taskDb .player-profile-backfill/updates.json
& $taskPhp -c $taskIni deploy/backfill-player-profile.php --verify $taskDb .player-profile-backfill/updates.json <출력된_백업_테이블명>
```

적용 전 전체 선수 테이블을 별도 테이블로 백업하고, 트랜잭션 안에서 빈 셀만 갱신한다. 검증은 적용 값의 일치, 기존 값·다른 컬럼 유지, 전체 선수 수 유지를 확인한다. 수집 HTML, 원본 누락 목록, 적용 목록, 미보완 항목은 Git에서 제외된 `.player-profile-backfill/`에 보관한다. 재수집하려면 해당 작업의 HTML 캐시를 별도 보관한 후 새 작업 디렉터리를 사용한다.
