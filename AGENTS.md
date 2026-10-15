# WESIPER 프로젝트 작업 지침

## Computer-use 사용 제한

- 사용자의 명시적 허용 없이 사용자의 컴퓨터(데스크톱·앱·마우스·키보드)를 조작하는 `computer-use`를 사용하지 않는다. (예: `mcp__cua_repl`, `mcp__computer-use__*`)
- 일반적인 작업 요청, 서버 실행 요청, 도구의 사용 가능 여부를 computer-use 사용 허용으로 해석하지 않는다.
- 에이전트 자체의 내장 브라우저로 페이지를 열어 보는 것(로컬 개발 서버 화면 확인, 참고 링크 열람)은 이 제한에 해당하지 않으며 허용 없이 해도 된다.

## UI 문구

- 기능이나 동작 방식을 설명하는 캡션·안내 문구·각주는 최대한 달지 않는다. (예: "누적 기록 정렬은 최소 조건 없이 전체 선수", "검색을 눌러야 표에 반영됩니다")
- 화면에는 제목, 레이블, 값, 오류 메시지처럼 꼭 필요한 문구만 둔다. 설명이 꼭 필요하다고 판단되면 추가하기 전에 사용자에게 먼저 묻는다.
- 가운뎃점(·) 최대한 사용 금지

## 로컬 서버 실행 요청

사용자가 로컬호스트 또는 개발 서버를 켜 달라고 하면, 프런트엔드 실행뿐 아니라 PHP API 정상 응답과 같은 Wi-Fi의 모바일 접속까지 준비한다.

### 기본 실행 방식

- 실행 디렉터리는 `frontend`, 기본 포트는 `5173`이다.
- 모바일에서 PC의 IPv4 주소로 접속할 수 있도록 Vite를 `--host 0.0.0.0`으로 실행한다. `127.0.0.1`로 제한해서 실행하지 않는다.
- 모든 개발 서버 실행은 로컬 PHP 서버 `http://127.0.0.1:18766`를 기본으로 사용한다. 사용자 명시적 요청 없이 운영 API로 우회하지 않는다.
- `frontend/.env.local`에는 `WESIPER_TODAY_GAMES_PROXY_TARGET=http://127.0.0.1:18766`이 설정되어 있다. 해당 PHP 서버가 실행되지 않으면 `/api/todayGames.php`, `/api/teamRank.php`, `/api/playerProfile.php`에서 연결 거부 및 502 오류가 발생할 수 있다.
- 로컬 PHP 서버와 DB 터널을 먼저 실행하고 아래처럼 프록시 대상을 지정한다. 서버 실행만을 위해 `.env.local`을 변경할 필요는 없다.

먼저 프로젝트 루트의 별도 유지되는 실행 세션에서 PHP를 시작한다:

```powershell
./deploy/start-local-php.ps1
```

- PHP 런타임 및 비공개 DB/날씨 설정은 `$env:TEMP/wesiper-local-dev`에 있다. 설정 내용이나 키를 출력하거나 저장소에 넣지 않는다.
- 스크립트는 필요한 경우 로컬 `13306` 포트의 SSH DB 터널을 시작하고, `deploy/local-router.php`를 사용해 `backend/api`의 로컬 코드를 실행한다. DB는 기존 서버의 DB를 사용한다.
- 임시 폴더가 정리되어 준비 파일이 없으면 PHP 런타임과 비공개 설정을 다시 준비한다. 운영 API로 조용히 우회하지 않는다.
- `deploy/weather-preview-router.php`는 원격 프리뷰 디렉터리용이므로 로컬 실행에는 사용하지 않는다.

다른 실행 세션에서 `frontend` 디렉터리로 이동한 뒤 실행:

```powershell
$env:WESIPER_TODAY_GAMES_PROXY_TARGET = 'http://127.0.0.1:18766'
$env:VITE_API_PROXY_TARGET = 'http://127.0.0.1:18766'
node.exe node_modules/vite/bin/vite.js --host 0.0.0.0
```

### 실행 환경 및 기존 서버 확인

- 실행 전에 포트 `5173`의 기존 서버를 확인한다. 정상 서버가 있으면 재사용하고, 잘못된 설정의 서버는 해당 프로세스만 종료한 뒤 다시 실행한다.
- 서버는 응답을 마친 뒤에도 유지되는 실행 세션으로 시작한다.
- `npm.cmd`가 없더라도 설치된 Node.js로 위의 Vite 진입점을 직접 실행할 수 있다.
- `node.exe`를 찾지 못하면 `Get-Command node` 또는 Codex의 `load_workspace_dependencies`로 실제 경로를 확인한다. 런타임 경로를 고정해서 가정하지 않는다.
- `node_modules/vite/bin/vite.js`가 없으면 프로젝트의 패키지 설정과 잠금 파일에 맞춰 의존성을 준비한다.
- 기본 터미널이 `helper_unknown_error: setup refresh had errors`로 실행되지 않으면 같은 명령의 승인된 확장 실행을 시도한다. 자동 승인 검토가 거부하면 그 제한을 따른다.

### 완료 전 검증

1. Vite의 시작 로그와 네트워크 접속 주소를 확인한다.
2. 현재 Wi-Fi/LAN의 IPv4 주소를 조회한다. 이전 주소를 재사용하지 않는다. `2026-09-30`에는 `172.30.1.88`이었지만 주소는 바뀔 수 있다.
3. 다음 주소의 HTTP 정상 응답을 확인한다.
   - `http://localhost:5173/`
   - `http://<현재 PC IPv4>:5173/`
   - `http://localhost:5173/api/todayGames.php`
   - `http://localhost:5173/api/teamRank.php`
4. API는 상태 코드뿐 아니라 유효한 JSON과 오류 여부도 확인한다. 502가 나오면 Vite 로그의 프록시 대상과 연결 오류를 조사한다.
5. 사용자에게 로컬 접속 링크와 모바일용 IPv4 링크를 알려주고, 모바일은 PC와 같은 Wi-Fi에 연결해야 한다고 안내한다.

PC에서 IPv4 HTTP 응답이 정상이어도 실제 모바일 접속을 검증한 것으로 표현하지 않는다. 모바일에서 계속 접속되지 않는 경우 Windows 방화벽과 네트워크 격리 여부를 확인한다.

