# WESIPER ⚾

[![React](https://img.shields.io/badge/React-18-61DAFB?logo=react&logoColor=black)](https://react.dev/)
[![Vite](https://img.shields.io/badge/Vite-8-646CFF?logo=vite&logoColor=white)](https://vite.dev/)
[![PHP](https://img.shields.io/badge/PHP-API-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Python](https://img.shields.io/badge/Python-Crawler-3776AB?logo=python&logoColor=white)](https://www.python.org/)

KBO를 주제로 한 미니게임과 경기·선수 통계를 제공하는 웹사이트입니다.

**서비스:** [wesiper.xyz](https://wesiper.xyz)

## 주요 기능

- **KBODLE: 크보들** — 단서를 이용해 오늘의 KBO 선수를 맞히는 게임
- **KBO BINGO** — 조건에 맞는 선수를 골라 빙고판을 완성하는 게임
- **KBO CANDLE** — 선수 기록의 흐름을 캔들 차트로 확인하는 통계 도구
- **Gameday** — KBO 경기 일정과 주요 선수 정보 확인
- **오늘의 랜더스** — SSG 랜더스의 당일 경기 현황 표시

## 구성

```text
Browser
  └─ React / Vite frontend
       └─ /api 요청
            └─ PHP API
                 ├─ MySQL
                 └─ KBO·네이버 스포츠·Statiz 데이터

Python crawler ── 수집 및 집계 ──> MySQL
```

```text
wesiper-project/
├─ frontend/       React/Vite 애플리케이션 원본
├─ backend/
│  ├─ api/         Lightsail 운영본을 기준으로 한 PHP API
│  └─ config/      DB 설정 로더
├─ crawler/        KBO 데이터 수집 및 통계 집계 배치
├─ config/         외부 DB 설정 파일 예시
├─ deploy/apache/  Apache SPA rewrite 설정
└─ webroot/        운영 서버 백업 원본 (Git 제외)
```

## 빠른 시작

### 프런트엔드

Node.js와 npm이 필요합니다.

```powershell
cd frontend
npm ci
npm run start
```

`/api` 요청은 기본적으로 `http://localhost:8080`으로 전달됩니다. 다른 API 서버를 이용하려면 프록시 대상을 지정합니다.

```powershell
$env:VITE_API_PROXY_TARGET = "https://wesiper.xyz"
npm run start
```

### PHP API

PHP 8과 `mysqli`, `pdo_mysql`, `curl`, `dom`, `mbstring` 확장이 필요합니다. `.env.example`을 참고해 DB 환경변수를 설정한 뒤 실행합니다.

```powershell
php -S localhost:8080 -t backend
```

### Python 크롤러

```powershell
python -m venv .venv
.venv\Scripts\pip install -r crawler\requirements.txt
python crawler\kbo_candle_crawl.py
```

## 환경설정

| 변수 | 용도 | 기본값 |
|---|---|---|
| `DB_HOST` | MySQL 호스트 | 없음 |
| `DB_PORT` | MySQL 포트 | `3306` |
| `DB_NAME` | 데이터베이스 이름 | 없음 |
| `DB_USER` | 데이터베이스 사용자 | 없음 |
| `DB_PASSWORD` | 데이터베이스 비밀번호 | 없음 |
| `WESIPER_DB_CONFIG` | 외부 PHP DB 설정 파일의 절대 경로 | Lightsail 경로 |
| `VITE_API_PROXY_TARGET` | Vite API 프록시 대상 | `http://localhost:8080` |

Lightsail에서는 `/opt/bitnami/apache/conf/wesiper-db.php`를 자동으로 우선 사용합니다. 실제 비밀번호가 든 파일은 저장소에 추가하지 마세요.

## 빌드

```powershell
cd frontend
npm ci
npm run build
```

빌드 결과는 `frontend/dist/`에 생성되며 Git에서 제외됩니다.

## Lightsail 배포 개요

Apache 문서 루트에 다음 항목을 배포합니다.

1. `frontend/dist/`의 정적 파일
2. `backend/api/`
3. `backend/config/`
4. `deploy/apache/.htaccess`

운영 DB 설정은 문서 루트 밖의 `/opt/bitnami/apache/conf/wesiper-db.php`에 유지합니다.

## 보안 원칙

- 실제 비밀번호, API 키, 토큰 및 개인키는 커밋하지 않습니다.
- 저장소에는 예시값만 든 설정 파일을 둡니다.
- 운영 서버 백업인 `webroot/`와 ACME challenge 파일은 Git에서 제외합니다.
- 공개 저장소에 푸시하기 전 비밀정보 검사를 다시 수행합니다.
