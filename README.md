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
- **오늘의 랜더스** — SSG 랜더스의 당일 경기 현황 표시

## 구성

```text
Browser
  └─ React / Vite frontend
       └─ /api 요청
            └─ PHP API
                 ├─ MySQL
                 └─ KBO·네이버 스포츠 데이터

Python crawler ── 수집 및 집계 ──> MySQL
```

```text
wesiper-project/
├─ frontend/       React/Vite 애플리케이션 원본
├─ backend/
│  ├─ api/         PHP API
│  └─ config/      DB 설정 로더
├─ crawler/        KBO 데이터 수집 및 통계 집계 배치
├─ config/         외부 DB 설정 파일 예시
├─ deploy/apache/  Apache SPA rewrite 설정
└─ webroot/        운영 서버 백업 원본 (Git 제외)
```
