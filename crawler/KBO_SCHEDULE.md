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

`kbo_candle_crawl.py`는 타석 기록을 위해 이미 받은 네이버 응답(`fetched_results`)을 `update_kbo_scoreboard.update_scoreboards_from_records`에 전달합니다. 추가 KBO 요청 없이 `gameInfo`, 해당 경기의 `games` 항목, `scoreBoard.rheb/inn`으로 종료 여부·경기 식별자·최종 점수와 이닝 합계를 검증합니다. 타석 적재와 예측 실행 전에 기존 `kbo_schedule` 경기를 갱신하며 TV 값은 유지합니다. 미등록 경기는 응답의 경기 코드·실제 경기일·팀·구장·점수를 검증한 뒤 `tv=NULL`로 생성합니다. 진행·취소·서스펜디드 경기는 건너뜁니다.

`away_inning_scores`, `home_inning_scores`는 JSON 배열이며 첫 값이 1회입니다. `0`은 무득점, `null`은 미진행 이닝입니다. 양 팀 모두 미진행인 뒤쪽 이닝은 제거합니다. 예: `[1,3,0,0,0,0,2,0,null]`.

전체 응답과 이닝 합계 검증 후 트랜잭션으로 저장하며, 같은 경기의 스코어보드를 다시 저장해도 중복되지 않습니다(타석 적재의 재실행 정책과는 별개입니다). 초기 컬럼 추가는 `python update_kbo_scoreboard.py --init-schema`로 수행하며 기존 데이터를 비공개 JSON 파일로 백업합니다. 수동 복구용 기존 KBO API 경로는 유지합니다. 일별 재처리는 `--date 2026-09-25`, 특정 경기 처리는 `--game-id 20260901HHKT0`를 사용하며 이 수동 명령만 KBO에 요청합니다. 과거 경기 전체 소급 수집은 자동으로 실행하지 않습니다.

월별 일정 수집 스크립트는 재실행 시 기존 이닝 컬럼을 보존합니다. `deploy/import-kbo-schedule.php`는 최초 적재용이므로 이닝 수집 이후 재실행하지 마세요.
