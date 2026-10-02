# 선수 별명

`kbo_player_nicknames`는 `PK`(자동 증가 기본키), `player_id`, `nickname`으로 구성됩니다.
`(player_id, nickname)`은 유일하며, 같은 별명은 여러 선수에게 등록할 수 있습니다.
초기 명단은 `data/player-nicknames.txt`에 있으며 102명, 116건입니다.
동명이인은 선수 ID로 지정했습니다. 김광현은 확인된 ID `77829`를 사용하고,
해즐베이커는 DB 표기와 ID `69605`를 사용합니다.

## 등록 및 확인

DB 접근 권한이 있는 서버에서 실행합니다. 배포 스크립트와 입력 파일은 웹 루트 밖에 둡니다.
설정 파일에는 기존 DB 연결 설정을 사용합니다. 비밀번호를 명령행에 넣지 않습니다.

```sh
php deploy/import-player-nicknames.php --check /path/to/database.php /path/to/player-nicknames.txt
php deploy/import-player-nicknames.php --apply /path/to/database.php /path/to/player-nicknames.txt
php deploy/import-player-nicknames.php --verify /path/to/database.php /path/to/player-nicknames.txt
php deploy/test-player-search.php /path/to/database.php
php deploy/import-player-nicknames.php --check /path/to/database.php /path/to/player-nicknames.txt > nickname-resolved.json
node deploy/test-player-nicknames.mjs nickname-resolved.json http://localhost:5173 --spacing
```

`--check`는 명단을 DB 선수와 대조하며, 모호한 이름이나 ID 불일치가 있으면 등록을 중단합니다.
`--apply`는 테이블을 생성하고 트랜잭션으로 등록합니다. 다시 실행해도 같은 선수의 같은 별명이 중복되지 않습니다.
테이블 등록 후 검색 및 프로필 API를 적용해야 합니다.

## 검색 및 프로필

`backend/lib/player-search.php`의 검색 결과는 `name > fullname > oldname > nickname` 순입니다.
이름 일치 결과 안에서는 완전 일치, 접두어 일치, 부분 일치 순으로 정렬합니다.
닉네임은 대소문자를 구분하지 않는 부분 검색을 지원하고, 여러 별명이 일치해도 선수는 한 번만 반환됩니다.
별명은 검색어와 저장된 별명의 공백을 제거해 비교합니다. `코리안 몬스터`, `코리안몬스터`, `코 리 안 몬 스 터`가 모두 일치합니다.
검색어의 `%`, `_`는 와일드카드가 아닌 문자로 검색합니다.
검색 화면은 두 글자 이상을 허용하며, 한 글자는 기존 예외인 `홀`만 허용합니다.

프로필 API의 `player.Nicknames`는 등록 순서의 문자열 배열입니다.
선수 정보(`profile-info`)의 마지막 행에서 `, `로 구분하여 표시하고, 빈 배열이면 행을 표시하지 않습니다.

2026-09-30 적용 범위: 운영 DB에 별명 테이블과 초기 명단을 등록하고,
검색·프로필 API는 `/home-preview`에 연결된 전용 PHP 프리뷰 서버에 적용했습니다.
프론트는 로컬 개발 서버에 반영했습니다.
