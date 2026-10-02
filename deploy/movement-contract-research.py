"""Reviewed announced contract terms and primary/news evidence."""
# Overrides preserve KBO registered amounts separately from announced maxima.
OVERRIDES={
378:('4년','69억','https://www.newsis.com/view/NISX20181205_0000493942','구단 발표 총액 69억원. KBO 연감 등록 금액 70억원과 구분.'),
949:('2+1년','26억','https://view.asiae.co.kr/article/2019013021062069116','2년 보장 및 1년 연장 옵션 포함 발표 최대 총액.'),
950:('1+1년','5억','https://view.asiae.co.kr/article/2019013021062069116','1년 보장 및 1년 연장 옵션 포함 발표 최대 총액.'),
1697:('1+1년','5억','https://www.nocutnews.co.kr/news/5272808','연봉 4억원 및 옵션 1억원 포함 최대 총액.'),
1695:('2+1년','20억','https://www.chosun.com/site/data/html_dir/2020/01/20/2020012000283.html','기본 2년 14억원; 구단 1년 연장 옵션 행사 시 연봉 6억원 추가.'),
3510:('1+1년','3억','https://sports.news.nate.com/view/20230110n20169','연장 및 인센티브 포함 발표 최대 총액.'),
4155:('2년','5억','https://www.koreabaseball.com/MediaNews/News/KboPhoto/View.aspx?bdSe=428504','연봉 총액 4억원 및 옵션 1억원.'),
2150:('3+1년','27억','https://www.viva100.com/article/202105203745007','구단 발표 3+1년, 최대 27억원. KBO 연감 기간 표기 2+1년과 구분.'),
4147:('3+1년','38억','https://www.spotvnews.co.kr/news/articleView.html?idxno=810685','2024~2026 3년 후 옵트아웃 선택권, 1년 잔여 계약 포함 최대 38억원.'),
}
MISSING_EXISTING={
58:('4년','88억','https://sports.khan.co.kr/article/201711131027003/amp','황재균 해외 복귀, KT 구단 발표 총액.'),
8:('4년','115억','https://www.lg.co.kr/media/release/8600','김현수 해외 복귀, 계약금 65억원 및 연봉 50억원.'),
1730:('4년','103억','https://sports.khan.co.kr/article/202112241455003','양현종 해외 복귀, 계약금 30억원, 연봉 25억원, 옵션 48억원.'),
}
# name, club, announcement date, term, announced maximum, evidence, details
NEW=[
('문승원','SSG','2021-12-14','5년','55억','https://sports.khan.co.kr/article/202112141531003/amp','연봉 47억원, 옵션 8억원.'),
('박종훈','SSG','2021-12-14','5년','65억','https://sports.khan.co.kr/article/202112141531003/amp','연봉 56억원, 옵션 9억원.'),
('한유섬','SSG','2021-12-25','5년','60억','https://www.sportschosun.com/amp/2021-12-25/202112250100162780010143','연봉 56억원, 옵션 4억원.'),
('구자욱','삼성','2022-02-03','5년','120억','https://www.newsis.com/view/NISX20220203_0001745831','연봉 90억원, 옵션 30억원.'),
('김광현','SSG','2022-03-08','4년','151억','https://imnews.imbc.com/replay/2022/nwdesk/article/6348426_35745.html','연봉 131억원, 옵션 20억원.'),
('박세웅','롯데','2022-10-26','5년','90억','https://v.daum.net/v/20221031102328343','연봉 70억원, 옵션 20억원.'),
('구창모','NC','2022-12-17','6+1년','132억','https://www.sportschosun.com/baseball/2022-12-19/202212200100125570015636','조건부 계약: 2023년 FA 취득 시 6년 125억원, 미취득 시 7년 최대 132억원. 군복무 기간 계약 연장. 단순 7년 확정 지급액이 아님.'),
('이원석','키움','2023-06-28','2+1년','10억','https://sports.news.nate.com/view/20230628n25384','보장 연봉 7억원, 연장 옵션 3억원.'),
('김태군','KIA','2023-10-16','3년','25억','https://sports.donga.com/sports/article/all/20231016/121691958/1','연봉 20억원, 옵션 5억원.'),
('최형우','KIA','2024-01-05','1+1년','22억','https://www.mk.co.kr/news/sports/10914666','연봉 20억원, 옵션 2억원.'),
('김성현','SSG','2024-01-20','3년','6억','https://v.daum.net/v/8hjTMtkPUB','2024~2026 보장 6억원. 1월19일 체결, 1월20일 발표.'),
('고영표','KT','2024-01-25','5년','107억','https://www.seoul.co.kr/news/newsView.php?id=20240126023005','연봉 95억원, 옵션 12억원.'),
('김상수','롯데','2024-02-02','2년','6억','https://m.news.nate.com/view/20241123n00211?issue_sq=10352','연봉 4억원, 옵션 2억원. 2024~2025.'),
('류현진','한화','2024-02-22','8년','170억','https://news.tf.co.kr/read/baseball/2077856.htm','2024~2031, 옵트아웃 조항 포함; 행사 시점 비공개.'),
('최주환','키움','2024-11-05','2+1+1년','12억','https://www.chosun.com/sports/baseball/2024/11/05/RWVPQGSXQ2M3YORAEZP6J4LDJE/?outputType=amp','기본 2년 6억원, 매년 연장 옵션 포함 최대 4년 12억원.'),
('김재현','키움','2024-11-22','6년','10억','https://www.xportsnews.com/article/1932707','연봉 6억원, 옵션 4억원.'),
('김광현','SSG','2025-06-13','2년','36억','https://v.daum.net/v/VU09Pb6FkH','2026~2027 연봉 30억원, 옵션 6억원. 기존 2022~2025 계약 후 신규 계약.'),
('김재환','SSG','2025-12-05','2년','22억','https://www.newsis.com/view/NISX20251205_0003430457','계약금 6억원, 연봉 10억원, 옵션 6억원.'),
('이지영','SSG','2026-01-06','2년','5억','https://www.ssglanders.com/media/news/detail?idx=19830','연봉 4억원, 옵션 1억원.'),
('김진성','LG','2026-01-22','2+1년','16억','https://sports.news.nate.com/view/20260122n25111','연봉 13억5000만원, 옵션 2억5000만원.'),
('노시환','한화','2026-02-23','11년','307억','https://www.donga.com/news/Sports/article/all/20260224/133407275/2','2027~2037, 옵션 포함 총액 307억원. 2월22일 체결, 2월23일 발표.'),
('서건창','키움','2026-05-20','2년','6억','https://m.newspim.com/news/view/20260520000974','2027~2028 연봉 5억원, 옵션 1억원.'),
('하영민','키움','2026-07-13','8년','80억','https://www.xportsnews.com/article/2171590','2027~2034 최대 80억원.'),
('홍건희','KIA','2026-01-21','1년','7억','https://www.ajunews.com/view/20260126084056808','연봉 6억5000만원, 인센티브 5000만원. 두산 계약 옵트아웃 후 자유계약.'),
]
