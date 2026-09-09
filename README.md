# WESIPER

WESIPER의 React/Vite 프런트엔드, PHP API, Python 크롤러를 함께 관리하는 저장소입니다.

## 디렉터리

- `frontend/`: React 18 + Vite 애플리케이션 원본
- `backend/api/`: Lightsail 운영 서버에서 가져온 최신 PHP API
- `backend/config/`: 운영 외부 설정 또는 로컬 환경변수를 읽는 DB 설정 로더
- `config/wesiper-db.example.php`: 외부 PHP DB 설정 파일의 값 없는 예시
- `crawler/`: KBO 데이터 수집 및 통계 집계 Python 배치
- `deploy/apache/`: Apache SPA rewrite 설정
- `webroot/`: 운영 서버 백업 원본. 비교 및 복구용이며 Git에는 포함하지 않습니다.

## 로컬 실행

### 프런트엔드

Node.js와 npm을 설치한 후:

```powershell
cd frontend
npm ci
npm run start
```

Vite는 기본적으로 `/api` 요청을 `http://localhost:8080`으로 전달합니다. 다른 API 서버를 사용할 때는 `VITE_API_PROXY_TARGET`을 지정합니다.

### PHP API

PHP 8, `mysqli`, `pdo_mysql`, `curl`, `dom`, `mbstring` 확장이 필요합니다. 저장소 루트의 `.env.example`에 적힌 DB 환경변수를 설정하고 다음처럼 개발 서버를 실행할 수 있습니다.

```powershell
php -S localhost:8080 -t backend
```

Lightsail에서는 기존 `/opt/bitnami/apache/conf/wesiper-db.php`를 자동으로 우선 사용합니다. 다른 외부 설정 파일을 쓰려면 `WESIPER_DB_CONFIG`에 절대 경로를 지정합니다. 실제 비밀번호가 든 설정 파일은 Git에 추가하지 않습니다.

### 크롤러

```powershell
python -m venv .venv
.venv\Scripts\pip install -r crawler\requirements.txt
python crawler\kbo_candle_crawl.py
```

크롤러에는 `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` 환경변수가 필요합니다.

## 빌드와 배포

```powershell
cd frontend
npm ci
npm run build
```

생성된 `frontend/dist/`의 정적 파일, `backend/api/`, `backend/config/`, `deploy/apache/.htaccess`를 Apache 문서 루트에 배포합니다. 운영 DB 비밀번호 파일은 문서 루트 밖의 `/opt/bitnami/apache/conf/wesiper-db.php`에 유지합니다.

첫 공개 푸시 전에는 `git status`로 `webroot/`, `.env`, 인증서 및 DB 설정 파일이 포함되지 않았는지 확인하고 비밀정보 검사를 다시 실행해야 합니다.
