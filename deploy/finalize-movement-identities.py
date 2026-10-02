"""Add individually reviewed identities without changing official source text."""
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CACHE=ROOT/'.contract-enrichment'
MANUAL={
43:(65503,'Official profile: outfielder, Lotte-Hanwha career, 1996 birth'),
49:(65844,'Official profile: SK-Samsung career, 1996 birth'),
104:(62340,'https://www.koreabaseball.com/Futures/Player/HitterTotal.aspx?playerId=62340'),
201:(67559,'https://www.mt.co.kr/sports/2017/04/11/2017041115000245987'),
297:(64504,'https://sports.donga.com/sports/article/all/20151125/75021942/3'),
317:(64011,'Official source spelling typo; KT catcher Ahn Seung-han, military reserve 2016-2017; https://lgcxydabfbch3774324.cdn.ntruss.com/KBO_FILE/ebook/pdf/2021_%EA%B0%80%EC%9D%B4%EB%93%9C%EB%B6%81.pdf'),
629:(67554,'Official profile: Lotte-Nexen career, 2017 Lotte draft; later changed position'),
693:(74630,'Park Jeong-tae renamed Park Geun-hong; 1985 birth, 2004 KIA draft; https://www.chosun.com/site/data/html_dir/2012/06/22/2012062201900.html'),
1936:(51454,'https://www.mk.co.kr/news/sports/10037394'),
3081:(66145,'1997 pitcher pitched first-team 2023-10-02; 2001 namesake Kim Dan-woo had no first-team appearances; ambiguous-games.json'),
3479:(65348,'Official profile: 2015 Nexen draft, Nexen career; namesake 68097 KT-NC'),
3700:(51454,'Official note jersey 57; 1991 namesake jersey 26 played throughout injured-list period'),
3716:(51454,'Official note jersey 57; 1991 namesake jersey 26 played throughout injured-list period'),
3732:(51454,'Official note jersey 57; 1991 namesake jersey 26 played throughout injured-list period'),
4280:(60146,'Official 2026 FA table Lee Seung-hyun (91); 2002 player only debuted 2021'),
4436:(51454,'https://www.newsis.com/view/NISX20250806_0003281401'),
4659:(60146,'https://v.daum.net/v/20250505132931931'),
4820:(60146,'https://www.mt.co.kr/sports/2026/09/08/2026090814440650001'),
5089:(60146,'Official note 6.3 first-team return; https://sports.news.nate.com/view/20260604n36116'),
5112:(60146,'https://www.xportsnews.com/article/2150886'),
5141:(56709,'Official profile: 2026 Hanwha development signing; namesake 52731 is 2022 first round'),
5355:(60146,'Official 2026 FA table Lee Seung-hyun (91), 2 years 600 million KRW'),
}
def run():
    plan=json.loads((CACHE/'identity-plan.json').read_text(encoding='utf-8'))
    parents={int(p['player_id']):p for p in json.loads((CACHE/'players.json').read_text(encoding='utf-8'))}
    movements={int(m['id']):m for m in json.loads((CACHE/'movements.json').read_text(encoding='utf-8'))}
    unresolved=[]
    for item in plan['unresolved']:
        m=item['movement'];mid=int(m['id'])
        if mid not in MANUAL:
            assert m['player_text']=='신인(지명권)',m
            unresolved.append(item);continue
        pid,proof=MANUAL[mid]
        plan['updates'].append({'id':mid,'before_identity':{k:m[k] for k in ['event_date','event_type','team','player_name','player_id','player_text','note']},'player_id':pid,'basis':'individually_reviewed','player':parents[pid],'evidence':proof})
    plan['unresolved']=unresolved
    assert len(plan['updates'])==1220 and len(unresolved)==17
    overrides={movements[int(u['id'])]['source_key']:{'player_id':u['player_id'],'identity':{k:movements[int(u['id'])][k] for k in ['event_date','event_type','team','player_name']},'basis':u['basis']} for u in plan['updates']}
    (CACHE/'identity-final.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2),encoding='utf-8')
    (ROOT/'crawler/movement_identity_overrides.json').write_text(json.dumps(overrides,ensure_ascii=False,indent=2),encoding='utf-8')
    print('Verified player identities:',len(plan['updates']),'non-player draft picks:',len(unresolved))
if __name__=='__main__':run()
