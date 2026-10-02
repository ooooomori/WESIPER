# 선수 경력 메모

2026-10-02 운영 DB의 `chk_career_note` CHECK 제약조건을 제거했습니다.
`note`는 허용 목록 없이 자유 문자열 또는 NULL을 저장합니다. 기존 `VARCHAR(100)` 길이 제한과 다른 컬럼의 제약조건은 유지됩니다.

적용 도구: `deploy/remove-player-career-note-check.php --apply <DB 설정 파일>`
기존 테이블 DDL과 CHECK 정의는 운영 서버 `/home/bitnami/deploy-backups/career-note-check-before-*.json`에 비공개로 보관합니다.
운영 경력 데이터는 수정하지 않고 임시 테이블에서 `7위` 및 임의 메모 입력을 검증했습니다.
WBC 결과 마이그레이션도 이 제약조건을 다시 생성하지 않습니다.
