# 선수 ID 외래키

다음 테이블의 `player_id`는 `kbo_player_data.player_id`를 참조합니다.

| 테이블 | 외래키 |
| --- | --- |
| kbo_player_nicknames | fk_kbo_player_nicknames_player |
| kbo_season_records | fk_kbo_season_records_player |
| kbo_season_pitch_records | fk_kbo_season_pitch_records_player |

모두 `ON UPDATE RESTRICT ON DELETE RESTRICT`를 사용합니다.
선수 ID 변경이나 선수 삭제로 경기 기록·별명이 자동 삭제되지 않습니다.
부모와 자식은 InnoDB의 부호 있는 INT를 사용합니다. INT 표시 너비 차이는 저장 형식에 영향을 주지 않습니다.

2026-09-30 검사에서 타자 기록 5개 ID(682행), 투수 기록 7개 ID(95행)의 부모가 누락되어 있었습니다.
`data/player-fk-missing-20260930.json`의 12개 선수는 각 ID의 공식 KBO 프로필을 조회해 확인했습니다.
프로필의 선수명·생년월일·포지션·투타·지명순위·등록 연도와 소속을 저장하고,
타자 기록의 기존 선수명도 대조했습니다. 기존 선수 및 경기 기록은 수정하지 않고 누락 부모만 신규 등록했습니다.
중단 후 수집 작업이 추가로 저장한 퓨처스 기록에서 미등록 ID 31개가 더 발견되어 같은 공식 프로필 조회로 보완했습니다.
최종 신규 부모 등록은 43명입니다. 김승준(52569)의 공식 프로필에는 포지션 분류가 비어 있어 기존 투수 기록을 근거로 투수로 등록했습니다.
투수 기록의 `player_id`는 범위를 확인한 뒤 unsigned INT에서 signed INT로 변경했습니다.

DB 설정과 배포 스크립트는 웹 루트 밖에 두고 실행합니다.

```sh
php deploy/import-fk-missing-players.php --check /path/to/database.php /path/to/player-fk-missing-20260930.json
php deploy/import-fk-missing-players.php --apply /path/to/database.php /path/to/player-fk-missing-20260930.json
php deploy/import-fk-missing-players.php --verify /path/to/database.php /path/to/player-fk-missing-20260930.json
php deploy/migrate-player-foreign-keys.php --check /path/to/database.php
php deploy/migrate-player-foreign-keys.php --apply /path/to/database.php
php deploy/migrate-player-foreign-keys.php --apply /path/to/database.php kbo_season_records,kbo_season_pitch_records --online
php deploy/migrate-player-foreign-keys.php --verify /path/to/database.php
php deploy/test-player-foreign-keys.php /path/to/database.php
```

마이그레이션은 부모 누락, 지원하지 않는 컬럼 타입, 정수 범위 초과를 발견하면 중단합니다.
외래키 적용 전 DDL을 스크립트 디렉터리에 `player-foreign-keys-before-*.sql`로 저장합니다.
일반 등록의 전체 복사가 반복해서 잠금 시간 초과로 실패하면 `--online`을 사용할 수 있습니다.
이 모드는 자식 테이블 WRITE와 부모 테이블 READ 잠금을 얻고 모든 기존 ID를 다시 검증합니다.
그 잠금 안에서 이 연결의 `foreign_key_checks`만 잠시 끄고 `ALGORITHM=NOCOPY`로 제약을 등록하며,
즉시 검사 설정을 복구하고 참조 누락이 없는지 재검증한 후 잠금을 해제합니다.
다른 연결이나 전역 검사 설정은 바꾸지 않습니다. 타입 변경은 참조 검사 활성 상태에서 수행합니다.
이 방식의 NOCOPY 요구사항은 [MariaDB 공식 문서](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-online-ddl/innodb-online-ddl-operations-with-the-nocopy-alter-algorithm)를 따릅니다.
경기 기록 삭제는 사용하지 않습니다.
검증 스크립트는 없는 선수 ID의 INSERT가 MariaDB 오류 1452로 거부되는지 확인하고 매번 트랜잭션을 롤백합니다.

새 경기 데이터를 수집할 때는 새 선수의 부모 레코드를 먼저 등록해야 합니다.
