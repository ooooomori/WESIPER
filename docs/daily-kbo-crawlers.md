# 일일 KBO 기록 수집

운영 경로는 `/home/bitnami/wesiper`, Python은 해당 디렉터리의 `.venv/bin/python`이다.
호스트 cron 시간대와 자식 프로세스 `TZ`는 `Asia/Seoul`이며, 매일 02:00 KST에 두 작업을 별도로 실행한다.

```cron
0 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh candle
0 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh futures
```

래퍼는 비공개 `/home/bitnami/.config/wesiper/crawler.env`를 `set -a`로 읽어
`DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, 선택적 `DB_PORT`를 전달한다.
자격 증명을 로그에 출력하지 않는다. 작업별 `locks/candle.lock`, `locks/futures.lock`에
비차단 `flock`을 적용하며, 다른 실행이 있으면 SKIP을 기록한다.
로그는 `logs/candle-YYYY-MM-DD.log`, `logs/futures-YYYY-MM-DD.log`에 쌓이고,
시작·종료 KST 시각과 종료 코드를 기록한다. 두 cron 항목은 서로의 성공을 조건으로 삼지 않는다.

1군은 KST 전일의 완료 경기를 갱신한다. `league_level=1`로 신규 타자 컬럼과 투수의
투구 수·등판 순서·실점·자책을 저장한다. 완료된 경기의 기존 기록만 트랜잭션 안에서 교체해
재실행 중복을 방지한다. 진행·서스펜디드 경기는 저장하지 않는다.
동일한 응답으로 점수·이닝별 점수·일정을 갱신하고 기존 TV 정보는 보존한다.
기록 갱신 후 리그 집계·순위 캐시·기존 예측 갱신을 실행한다.
예측 입력도 `league_level=1`로 제한해 퓨처스 기록이 1군 예측에 섞이지 않도록 한다.

퓨처스는 KST 현재 연도의 일정을 조회하고 `--daily`로 최근 7일 또는 저장된 일정에 없는
완료 경기만 선택한다. `league_level=2`로 타자·투수·일정을 저장한다.
현재 시즌 `player-daily` 캐시는 1시간 후 만료하고 과거 시즌 캐시는 유지한다.
선수 검색 캐시는 24시간 후 만료한다. 최근 7일의 박스스코어는 매 실행 새로 가져온다.
선수 식별이 모호하면 저장하지 않고 보고서를 남긴다. 검증 결과가 맞아야 커밋한다.

두 크롤러는 기록 적재 전에 누락된 `kbo_player_data`의 선수 ID를 조회한다.
공식 KBO 투수·타자 프로필에서 이름·생년월일·팀·포지션을 검증하고 같은 적재 트랜잭션에
신규 선수를 먼저 등록한다. 기존 선수 레코드는 덮어쓰지 않는다.

```bash
# DB 쓰기·집계·예측 없이 실제 원천 데이터와 선수 식별만 확인
/home/bitnami/wesiper/run_daily_kbo.sh candle --dry-run
/home/bitnami/wesiper/run_daily_kbo.sh futures --dry-run

# 일일 운영 실행(퓨처스는 현재 연도 및 최근/누락 경기)
/home/bitnami/wesiper/run_daily_kbo.sh candle
/home/bitnami/wesiper/run_daily_kbo.sh futures
```

dry-run도 원천 응답 캐시와 진단 보고서는 갱신한다. 전체 시즌 dry-run은 자동 실행하지 않는다.
이전 연도 전체 백필은 일일 래퍼 대신 크롤러를 명시적인 `--years`로 직접 실행한다.
배포는 `deploy/install-daily-kbo.sh <검증된 릴리스 디렉터리>`를 사용하며 기존 파일과 cron을
`/home/bitnami/deploy-backups/daily-kbo-*`에 저장한다. 기록 스냅샷은
`deploy/backup-daily-kbo.py`로 비공개 압축 JSON을 남긴다.
