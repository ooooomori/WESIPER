"""Recover a commit-before-receipt gap only after exact plan/database comparison."""
import argparse,json
from collections import Counter
from decimal import Decimal, InvalidOperation
from backfill_kbo_early_official import ROOT, connect, atomic, validate_plan, verify_year, BATTER_FIELDS, PITCH_FIELDS

parser=argparse.ArgumentParser()
parser.add_argument('--year',type=int,default=2003)
parser.add_argument('--game-id',default='20030523SKHD0')
arguments=parser.parse_args()
year=arguments.year
game_id=arguments.game_id
assert 2001<=year<=2007
receipt_path = ROOT / str(year) / 'write-receipt.json'
prior = json.loads(receipt_path.read_text())
assert game_id not in prior['committed_game_ids']
item = json.loads((ROOT / str(year) / 'plans' / f'{game_id}.json').read_text())
validate_plan(item)
numeric = {'league_level','player_id','inning','sb','cs','run_out','pitcher_id',
           'rbi','r','is_gwrbi','order','is_gs','batting_index','pitched','er'}
def normalize(fields, row):
    values=[]
    for field,value in zip(fields,row):
        if value is None:values.append(None)
        elif field not in numeric:values.append(str(value))
        else:
            if isinstance(value,bytes):value=int.from_bytes(value,'big')
            if isinstance(value,bool):value=int(value)
            try:values.append(Decimal(str(value)))
            except InvalidOperation:values.append(str(value))
    return tuple(values)

connection = connect()
try:
    with connection.cursor() as cursor:
        cursor.execute('SET SESSION max_statement_time=15')
        for table, fields, key in (('kbo_season_records', BATTER_FIELDS, 'batter_rows'),
                                   ('kbo_season_pitch_records', PITCH_FIELDS, 'pitcher_rows')):
            cursor.execute('SELECT '+','.join('`'+field+'`' for field in fields)+
                           ' FROM '+table+' WHERE league_level=1 AND game_id=%s AND game_date=%s',
                           (game_id,item['game']['game_date']))
            actual = Counter(normalize(fields, row) for row in cursor.fetchall())
            expected = Counter(normalize(fields, tuple(row[field] for field in fields)) for row in item[key])
            if actual != expected:
                raise ValueError('database rows differ from validated plan; receipt unchanged')
        cursor.execute('SELECT game_date,away_team,home_team,away_score,home_score,tv,stadium,is_allstar,away_inning_scores,home_inning_scores FROM kbo_schedule WHERE league_level=1 AND game_code=%s',(game_id,))
        rows = cursor.fetchall()
        assert len(rows)==1
        actual = rows[0]
        game = item['game']
        assert str(actual[0]) == game['game_date']
        assert tuple(actual[1:8]) == tuple(game[key] for key in ('away_team','home_team','away_score','home_score','tv','stadium','is_allstar'))
        assert json.loads(actual[8]) == item['away_innings'] and json.loads(actual[9]) == item['home_innings']
    report = verify_year(connection, year, [game_id])
    for key in ('games','batter_rows','pitcher_rows','run_out','schedule_games'):
        report[key] += int(prior.get(key,0))
    report['pitcher_missing_games'] = sorted(set(report['pitcher_missing_games'])|set(prior.get('pitcher_missing_games',[])))
    for key in ('discovered_games','unresolved_games'):
        report[key] = prior[key]
    report['committed_game_ids'] = sorted(set(prior['committed_game_ids'])|{game_id})
    atomic(receipt_path, report)
    atomic(ROOT/'checkpoint-reconciliation.json', {'year':year,'game_id':game_id,
          'all_batter_pitcher_schedule_fields_match_plan':True,'database_rewrites':0,
          'receipt_games':report['games']})
    print('exact committed-game comparison passed; receipt recovered; DB rewrites 0', flush=True)
finally:
    connection.close()
