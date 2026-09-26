# KBO 일정 수집

`collect_kbo_schedule.py`는 공식 일정 API에서 지정 연도의 3~10월 일정을 수집합니다. 취소 경기는 제외하고 예정 경기는 점수를 `NULL`로 저장합니다. 경기 코드는 공식 응답을 사용하며 임의로 생성하지 않습니다.

```sh
python crawler/collect_kbo_schedule.py --year 2026
```

기본 저장 위치: `data/kbo_schedule.sqlite3`, 테이블: `kbo_schedule`.

컬럼: `game_date`(날짜), `game_code`(기본 키), `away_team`, `home_team`, `away_score`, `home_score`, `tv`, `stadium`.

전체 수집과 검증이 성공한 뒤 해당 연도의 3~10월 데이터만 트랜잭션으로 교체합니다. 재실행해도 중복되지 않으며 이후 취소된 경기는 제거됩니다. 아직 공식 일정에 발표되지 않은 경기는 포함할 수 없습니다.

기존 MySQL에 저장하려면 `pymysql`을 설치하고 `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` 환경변수를 설정합니다. `DB_PORT`는 기본 3306입니다.

```sh
python crawler/collect_kbo_schedule.py --year 2026 --mysql
```

MySQL 옵션은 지정한 DB에 테이블을 생성하고 해당 기간의 데이터를 교체하므로 대상 DB를 확인한 뒤 실행하세요.

## 새벽 2시 스코어보드 갱신

기존 `kbo_candle_crawl.py`의 마지막 단계에서, 이미 찾은 전날 경기 ID를 `update_kbo_scoreboard.update_scoreboards`에 전달합니다. 공식 일자별 API에서 정규시즌 종료 경기임을 확인하고 `GetScoreBoardScroll`로 점수·날짜·팀·구장과 이닝별 득점을 갱신합니다. 기존 TV 값은 유지합니다.

`away_inning_scores`, `home_inning_scores`는 JSON 배열이며 첫 값이 1회입니다. `0`은 무득점, `null`은 미진행 이닝입니다. 양 팀 모두 미진행인 뒤쪽 이닝은 제거합니다. 예: `[1,3,0,0,0,0,2,0,null]`.

전체 요청과 이닝 합계 검증 후 트랜잭션으로 저장하며, 같은 경기를 다시 실행해도 중복되지 않습니다. 초기 컬럼 추가는 `python update_kbo_scoreboard.py --init-schema`로 수행하며 기존 데이터를 비공개 JSON 파일로 백업합니다. 일별 재처리는 `--date 2026-09-25`, 특정 경기 처리는 `--game-id 20260901HHKT0`를 사용합니다. 과거 경기 전체 소급 수집은 자동으로 실행하지 않습니다.

월별 일정 수집 스크립트는 재실행 시 기존 이닝 컬럼을 보존합니다. `deploy/import-kbo-schedule.php`는 최초 적재용이므로 이닝 수집 이후 재실행하지 마세요.
