# 선수 가족관계

`kbo_player_family`에 선수 간 가족관계를 저장한다. 생성 SQL은 `deploy/create-player-family.sql`에 있다.

| 컬럼 | 의미 |
| --- | --- |
| PK | 자동 증가 기본키 |
| player_id | 기준 선수의 `kbo_player_data.player_id` |
| relative_player_id | 가족인 선수의 `kbo_player_data.player_id` |
| relationship | 상대 선수가 기준 선수에게 어떤 가족인지 |

예를 들어 이정후를 기준 선수로, 이종범을 상대 선수로 저장하면 `relationship`은 `아버지`다. 반대 방향으로 저장한다면 `아들`이다. 한 행으로 두 선수를 연결하므로 양쪽 선수에서 조회하려면 `player_id`와 `relative_player_id`를 모두 검색하고, 상대 방향에서는 관계 명칭을 반대로 해석한다. 반대 방향의 행은 자동으로 생성하지 않는다.

관계 명칭은 자유롭게 입력할 수 있다. 같은 방향의 동일 관계 중복, 자기 자신과의 관계, 빈 관계 명칭은 제약 조건으로 차단한다. 두 선수 ID는 모두 외래키이며, 관계가 등록된 선수의 삭제는 제한한다. 선수별 조회와 상대 선수별 조회에 필요한 인덱스도 포함한다.
