# KBO 역대 트레이드

사용자가 지정한 나무위키 1980·1990·2000·2010·2020년대 문서에서 트레이드 표 335건을 가져왔다. 원문 HTML, 파싱 결과, ID 대조 근거와 검증 결과는 `output/namu-trades-*`, `output/trade-import-*`, `output/trade-player-wiki/`에 보존했다.

- `kbo_trades`: 거래일, 제목, 양측 교환 내용 요약, 출처 문서 및 문단, 원문 날짜와 SHA-256.
- `kbo_trade_assets`: 거래별 선수·현금·지명권·무상 항목과 출발/도착 팀. 선수의 `player_id`는 `kbo_player_data` 외래 키이며, 거래 당시 이름은 `player_name`에 보존한다.
- `linked_draftee_id`: 지명권으로 나중에 선발된 선수. 당시 트레이드된 선수의 `player_id`와 구분한다. 확인되지 않은 지명 결과는 NULL이다.
- 현금 액수가 원문에 없는 경우 `cash_amount_krw`는 NULL이다. 날짜와 조건은 해당 출처의 표를 따른다.
- 1985-12-28 OB→빙그레의 김일중은 DB 일치 선수 없음으로 `player_id=NULL`, `identity_status='unresolved'`이다. 임의로 새 선수를 만들지 않았다.
- 류명선→유명선, 김희걸→김건한, 박영복→박도현, 지성준→지시완, 윤여운→윤수강 등은 선수 문서 생일과 개명 정보를 대조했다. 일부 선수의 DB 생일과 문서 생일이 달라 입단 이력과 이름을 함께 사용한 근거를 `identity_basis`에 보존했다. 기존 선수 데이터는 수정하지 않았다.
- `kbo_player_movements`는 수정하지 않았다.

선수별 거래 조회:

```sql
SELECT t.id, t.trade_date, t.title, t.summary,
       a.player_name, a.player_id, a.from_team, a.to_team
FROM kbo_trades t
JOIN kbo_trade_assets a ON a.trade_id = t.id
WHERE a.asset_type = 'player' AND a.player_id = 75125
ORDER BY t.trade_date;
```

`deploy/import-kbo-trades.php`는 기본 읽기 검증 모드이다. `WESIPER_DB_CONFIG`를 비공개 설정 파일 경로로 지정하고 `--apply`를 사용하면 입력한다. 동일 출처 키가 존재하면 내용 일치를 확인해 재입력을 건너뛰며, 기존 입력과 다른 경우 중단한다. 테이블 정의는 `deploy/kbo-trades-schema.sql`에 있다.

검증: 335건 / 교환 항목 937건 / 선수 이동 782건 / ID 연결 781건 / 고유 선수 629명. 다섯 문서의 모든 h3 거래 문단과 표를 대조했고, 2018-12-07 삼각 트레이드는 넥센→SK 고종욱, SK→삼성 김동엽, 삼성→넥센 이지영으로 저장했다. 원문 해설과 평가는 복사하지 않고 거래 조건만 구조화했다.
