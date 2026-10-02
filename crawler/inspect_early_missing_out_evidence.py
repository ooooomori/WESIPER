"""Read official missing-PA at-bat, strikeout and inning evidence; no DB writes."""
import json
from backfill_kbo_early_official import ROOT,load_raw
from kbo_early_parser import rows

audit=json.loads((ROOT/'cached-plan-audit.json').read_text())
for issue in audit['bf_mismatches']:
    game_id=issue['game_id']
    year=game_id[:4]
    plan=json.loads((ROOT/year/'plans'/f'{game_id}.json').read_text())
    game=plan['game']
    box=load_raw(game,'GetBoxScoreScroll')
    side=0 if issue['team']==game['away_team'] else 1
    hitter=box['arrHitter'][side]
    results=rows(hitter['table2'])
    lineup=rows(hitter['table1'])
    tokens=[token.strip() for result in results for value in result for token in value.split('/') if token.strip()]
    pitchers=rows(box['arrPitcher'][1-side]['table'])
    pitching_header=json.loads(box['arrPitcher'][1-side]['table'])['headers']
    official_so=sum(int(row[13]) for row in pitchers)
    visible_so=sum('삼진' in token or '낫아웃' in token for token in tokens)
    innings={'20010627HDHT1':7,'20010825HTHD0':6,'20010918SKLG0':5,
             '20020728HTLG0':4,'20030524LGHH0':5,'20030809HDHH0':2,'20040508OBHD0':4}
    inning=innings[game_id]
    values=[{'name':name[2],'result':result[inning-1]} for name,result in zip(lineup,results)
            if len(result)>=inning and result[inning-1]]
    print(json.dumps({'game_id':game_id,'official_pitcher_strikeouts':official_so,
                      'visible_batter_strikeouts':visible_so,'strikeout_gap':official_so-visible_so,
                      'missing_inning_visible_results':values},ensure_ascii=False),flush=True)
