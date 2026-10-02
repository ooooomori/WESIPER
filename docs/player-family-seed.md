# 등록한 유명 선수 가족관계

2026-10-02에 `kbo_player_family`에 25건을 등록했다. 관계는 상대 선수가 기준 선수에게 어떤 가족인지 나타낸다. 한 관계마다 한 행을 저장한다.

| 기준 선수 (ID) | 상대 선수 (ID) | 관계 | 확인 출처 |
| --- | --- | --- | --- |
| 이정후 (67341) | 이종범 (93607) | 아버지 | [출처](https://www.mlb.com/player/jung-hoo-lee-808982) |
| 고우석 (67119) | 이종범 (93607) | 장인 | [출처](https://www.mlb.com/player/jung-hoo-lee-808982) |
| 이정후 (67341) | 고우석 (67119) | 매제 | [출처](https://www.mlb.com/player/jung-hoo-lee-808982) |
| 유원상 (76757) | 유승안 (82174) | 아버지 | [출처](https://www.osen.co.kr/article/G1111361219) |
| 유민상 (62265) | 유승안 (82174) | 아버지 | [출처](https://www.osen.co.kr/article/G1111361219) |
| 유민상 (62265) | 유원상 (76757) | 형 | [출처](https://www.osen.co.kr/article/G1111361219) |
| 박세혁 (62244) | 박철우(87630) | 아버지 | [출처](https://ichannela.com/news/detail/77944377-2.do) |
| 박세진 (66047) | 박세웅 (64021) | 형 | [출처](https://www.sportsworldi.com/newsView/20230227501704) |
| 나성범 (62947) | 나성용 (61742) | 형 | [출처](https://www.osen.co.kr/article/G1110303016) |
| 조동찬 (72466) | 조동화 (71848) | 형 | [출처](https://imnews.imbc.com/replay/2010/nwtoday/article/2718431_30527.html) |
| 최항 (62884) | 최정 (75847) | 형 | [출처](https://v.daum.net/v/L1n7uQpxvv) |
| 이성곤 (64266) | 이순철 (85620) | 아버지 | [출처](https://www.sportsseoul.com/news/read/937671) |
| 정해영 (50662) | 정회열 (90660) | 아버지 | [출처](https://www.sportsseoul.com/news/read/937671) |
| 양후승 (85370) | 양승관 (82374) | 형 | [출처](https://enews.imbc.com/News/ViewAmp/136173) |
| 양준혁 (93410) | 양일환 (82703) | 사촌형 | [출처](https://lady.khan.co.kr/issue/article/201012031556531) |
| 정수성 (97350) | 정수근 (95208) | 형 | [출처](https://mcst.go.kr/servlets/eduport/front/upload/UplDownloadFile?pFileName=%EA%B5%AD%EC%96%B4+%EA%B5%90%EC%9C%A1%EC%9D%98+%EA%B4%80%EC%A0%90%EC%97%90%EC%84%9C+%EB%B3%B8+%EB%B0%A9%EC%86%A1+%EC%96%B8%EC%96%B4+%EC%97%B0%EA%B5%AC.pdf&pPath=0406000000&pRealName=04201111250002846073664.pdf) |
| 강진성 (62925) | 강광회 (90347) | 아버지 | [출처](https://www.starnewskorea.com/stview.php?no=2020122418255636929) |
| 박건우 (79215) | 장원준 (74513) | 매형 | [출처](https://www.donga.com/news/List/article/all/20170110/82305516/2) |
| 박영현 (52060) | 박정현 (50709) | 형 | [출처](https://www.yna.co.kr/view/AKR20220528037100007) |

| 구재서 (82243) | 구천서 (82273) | 형 (쌍둥이) | [출처](https://www.koreadaily.com/article/1407043) |
| 김동기 (86312) | 김상기 (83992) | 형 | [출처](https://www.koreadaily.com/article/1407043) |

| 이승민 (54806) | 이병규 (97109) | 아버지 | [출처](https://www.yna.co.kr/view/AKR20231008017900007) |
| 김건형 (51005) | 김기태 (91803) | 아버지 | [출처](https://www.yna.co.kr/view/AKR20210622140900007) |
| 김성훈 (67762) | 김민호 (93242) | 아버지 | [출처](https://www.sportschosun.com/baseball/2017-02-05/201702050100040470002782) |

| 박준현 (56318) | 박석민 (74465) | 아버지 | [출처](https://www.koreabaseball.com/MediaNews/News/KboPhoto/View.aspx?bdSe=506945) |

등록 스크립트: `deploy/seed-player-family.php`. 기본 실행은 등록 예정 내역만 조회하며, `--apply` 옵션으로 등록한다. ID·이름·생년월일을 확인하고 동일 선수 쌍의 정방향·역방향 중복과 충돌을 검사한다. 기존 행 백업과 트랜잭션 검증 후 등록하며, 재실행 시 기존 25건을 확인하고 새로 추가하지 않는다.

사용자가 추가 지정한 장원준–박건우, 정회열–정해영, 박정현–박영현, 조동화–조동찬을 모두 포함한다.
