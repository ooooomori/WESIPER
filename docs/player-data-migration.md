# 선수 데이터 통합 및 컬럼 정리

운영 선수 데이터는 `kbo_player_data` 한 테이블에서 관리합니다. 기존 범용 테이블의 `id`는 기본 키로 유지하고, 공통 선수 식별자는 UNIQUE·NOT NULL인 `player_id`입니다. 기존 `player_data.playerId`, `kbo_playerlist_20250613.p_no`와 같은 값입니다.

## 최종 컬럼

| 이전 컬럼 | 최종 컬럼 |
| --- | --- |
| `p_no` | `player_id` |
| `p_name` + `name` | `name` |
| `p_oldname` + `oldname` | `oldname` |
| `p_pos` + `pos` | `pos` |
| `p_img` | `img` |
| `p_body` + `body` | `body` |
| `p_birth` + `birth` | `birth` |
| `p_career` + `career` | `school` |
| `player_data.id` | `kbodle_source_id` |
| `player_data.isKbodle` | `is_kbodle` |

중복 값은 양쪽의 공백을 제거한 뒤 UTF-8 문자 수를 비교합니다. 더 긴 값을 채택하고 길이가 같으면 기존 접두사 없는 값을 유지합니다. 모두 비어 있으면 NULL로 저장합니다. 최종 테이블에는 `p_` 접두사 컬럼과 `career`가 없으며 총 24개 컬럼입니다. 이름·신체·생일 컬럼 길이는 양쪽 원본 값을 수용하도록 유지했습니다.

`school`은 더 긴 원본 경력을 선택한 후 학교·유소년·아마추어 이력만 남깁니다. KBO·해외 프로팀과 상무·경찰 이력은 제거합니다. 외국 학교의 이름·학교 구분·얼리 드래프트 주석·이름 내부 하이픈은 보존합니다. 예를 들어 `일본 이케타고-SSG-두산`은 `일본 이케타고`가 됩니다. 학교 정보가 없으면 NULL이며 원본 문자열은 백업에 보존합니다. 정리 함수는 `backend/lib/player-school.php`를 사용하고, 보조 프로필 갱신 스크립트도 `school`만 갱신합니다.

`backNo`, `team`, `bat`, `throw`, `draft`, `mainPos`, `subPos`, `hs`, `hsLoc`와 수상 플래그는 유지합니다.

| `is_kbodle` | 의미 | 선수 수 |
| --- | --- | ---: |
| 0 | 은퇴 선수 | 4,876 |
| 1 | 크보들 정답 생성 대상 | 278 |
| 2 | KBO 현역이지만 정답 생성 제외 | 801 |
| 3 | 해외 리그 현역 | 4 |
| 4 | 울산 웨일즈 소속 현역 | 43 |

현재 전체 선수는 6,002명입니다. 크보들 검색·랜덤·커스텀·로스터는 1·2인 선수만 사용합니다. 캔들·프로필은 0을 은퇴로 표시합니다. 신규 선수 INSERT에는 상태를 명시해야 하며 기본값은 0, CHECK 제약으로 0~4만 허용합니다. 해외·울산 선수 추가와 역대 타이틀홀더 적용 내역은 [player-history-import.md](player-history-import.md)에 있습니다.

## API 및 이벤트

API와 크롤러의 선수 SQL은 새 테이블·컬럼을 사용합니다. 프런트엔드와의 요청·응답 계약은 유지합니다. 예를 들어 커스텀·빙고 요청의 `p_no`와 응답의 `SporkId`, `PlayerId`는 그대로이며 DB의 `player_id`로 연결합니다. 별도 테이블인 `kbobingo_pick`, `kbobingo_player_cache`, 과거 수비 기록의 `p_no` 등은 그 테이블의 컬럼명입니다.

기존 테이블명 `player_data`, `kbo_playerlist_20250613`은 새 테이블을 읽는 호환 뷰입니다. 뷰에는 이전 컬럼 별칭을 제공하지만 별도 데이터 사본을 운영하지 않습니다. `player_data` 뷰는 상태 1·2만 포함하고 경력 별칭도 정리된 `school`을 읽습니다.

이벤트 수정은 사용자가 직접 수행했습니다. 전환 스크립트는 이벤트를 수정하거나 오늘의 정답을 추가 생성하지 않습니다. 현재 활성화된 일일 `KBODLE_GEN` 본문은 다음과 같습니다.

```sql
INSERT INTO kbodle_answer (playerID, playerName)
SELECT player_id, name
FROM kbo_player_data
WHERE is_kbodle = 1
ORDER BY RAND()
LIMIT 1;
```

## 2026-09-28 최초 통합·컬럼 정리 결과

- 전체 5,981명과 상태 분류를 유지했습니다. 선수 식별자 중복은 없습니다.
- 기존 두 원본 테이블의 완전히 동일한 중복 행은 최초 통합에서 각각 1건 정리했습니다.
- 중복 컬럼 6쌍을 통합하고 모든 `p_` 컬럼을 제거했습니다.
- 296명의 경력에서 프로팀·군경팀 이력을 제거했습니다. `school`이 채워진 선수는 1,034명입니다.
- 생일 누락을 보완해 박성재(52980)·김선우(51604)의 크보들 나이가 기본값 20세에서 실제 나이로 변경되었습니다.
- PHP 문법 52개 파일, 학교 정리 14개 사례, 길이·동률 선택 4개 사례를 검증했습니다.
- API 비교 12건, 구 URL, 은퇴 필터, 프로필·잘못된 ID, 랜덤 선택 20회, 공개 빙고판, 빙고 JOIN을 사전 서버와 최종 운영 테이블에서 검증했습니다.
- 운영 API·프리뷰·크롤러를 배포하고 PHP-FPM을 정상적으로 교체했습니다. 기존 설정 파일은 서버 안에서만 사용했습니다.

## 백업과 재검증

DB 원본 백업:

- `player_data_backup_merge_20260928_053820`
- `kbo_playerlist_backup_merge_20260928_053820`
- `kbo_player_data_backup_columns_20260928_071829`: 컬럼 정리 직전 원본.
- `kbo_player_data_backup_bridge_20260928_071829`: API 전환 중 두 구조를 함께 제공한 테이블.

최초 통합 파일 백업은 `/home/bitnami/wesiper-player-migration-20260928/backups/`, 컬럼 정리 파일 백업은 `/home/bitnami/wesiper-player-columns-20260928/backups/`입니다. 각각 운영 API·프리뷰·크롤러 원본을 보관합니다.

컬럼 전환 상태·원본 DDL·뷰·이벤트 정의·백업 이름은 서버 비공개 릴리스 폴더의 `deploy/player-columns-state.json`에 있습니다. 전체 행을 원본에서 계산한 결과와 비교해 전환을 검증했습니다. API가 갱신하는 `img`는 현재 값을 이어받습니다.

```bash
cd /home/bitnami/wesiper-player-columns-20260928
sudo /opt/bitnami/php/bin/php deploy/normalize-player-columns.php --verify
python3 deploy/test-player-apis.py --phase live
```

현재 컬럼 정리는 `normalize-player-columns.php`가 관리합니다. 최초 통합용 `migrate-player-data.php`는 최종 스키마에서 재실행을 차단합니다. 재검증의 원본 비교는 이후 사용자가 이름·학교 등의 값을 수정하면 차이를 보고합니다.

## 전환 및 복구 절차

전환 순서는 원본 백업·새 컬럼 준비(`--prepare`), 사용자 이벤트 수정, 비공개 API 검증, API 배포(`apply-player-columns-release.sh`), 운영 API 검증, 호환 뷰 갱신·원자적 테이블 교체(`--cutover`), 최종 API 검증입니다. 구 컬럼을 쓰는 이벤트가 있으면 최종 전환을 중단합니다.

전체 복구 전에 현재 테이블의 후속 변경분을 먼저 보관해야 합니다. 컬럼 전환 전의 API는 물리 테이블의 `p_no` 등을 사용하므로 파일만 되돌리면 안 됩니다. DB 전환 백업과 API 파일 백업을 함께 복구하고 이벤트도 해당 식별자에 맞춰 사용자에게 확인해야 합니다. `school`에서 제거한 원본 프로팀 경력은 컬럼 정리 직전 DB 백업에 남아 있습니다.
