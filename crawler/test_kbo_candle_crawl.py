import os
import sys
import types
import unittest
from pathlib import Path

for name in ('DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'):
    os.environ.setdefault(name, 'test')

sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.modules.setdefault('requests', types.ModuleType('requests'))
sys.modules.setdefault('pymysql', types.ModuleType('pymysql'))
fcntl_stub = types.ModuleType('fcntl')
fcntl_stub.LOCK_EX = 1
fcntl_stub.LOCK_NB = 2
fcntl_stub.flock = lambda *_: None
sys.modules.setdefault('fcntl', fcntl_stub)

from kbo_candle_crawl import (  # noqa: E402
    extract_baseball_data,
    extract_pitcher_data,
    parse_kbo_result,
)


def lineup(prefix, code_start):
    batters = []
    for order in range(1, 10):
        batter = {
            'name': f'{prefix}{order}',
            'playerCode': str(code_start + order),
            'batOrder': order,
            'pos': '중',
            'rbi': 0,
            'run': 0,
            'inn1': '유땅',
        }
        batters.append(batter)
    batters[0].update({'inn2': '좌안', 'rbi': 2, 'run': 1})
    batters.append({
        'name': f'{prefix}대주자',
        'playerCode': str(code_start + 10),
        'batOrder': 1,
        'pos': '교',
        'rbi': 0,
        'run': 1,
    })
    return batters


def response_fixture():
    return {
        'result': {
            'recordData': {
                'gameInfo': {'aName': '한화', 'hName': '삼성'},
                'etcRecords': [],
                'battersBoxscore': {
                    'away': lineup('원정', 10000),
                    'home': lineup('홈', 20000),
                },
                'pitchersBoxscore': {
                    'away': [
                        {'pcode': '30001', 'name': '원정선발', 'pa': 6, 'bf': 70,
                         'inn': '5', 'wls': '패', 'er': 3, 'r': 4},
                        {'pcode': '30002', 'name': '원정구원', 'pa': 4, 'bf': 20,
                         'inn': '3', 'wls': '', 'er': 0, 'r': 0},
                    ],
                    'home': [
                        {'pcode': '40001', 'name': '홈선발', 'pa': 6, 'bf': 80,
                         'inn': '6', 'wls': 'W', 'er': 1, 'r': 2},
                        {'pcode': '40002', 'name': '홈구원', 'pa': 4, 'bf': 18,
                         'inn': '3', 'wls': '세', 'er': 0, 'r': 0},
                    ],
                },
            }
        }
    }


def gap_lineup(prefix, code_start):
    innings = {
        1: {1: '1땅'},
        2: {1: '2땅', 3: '좌비'},
        3: {1: '유땅', 3: '유안'},
        4: {1: '우비', 3: '삼진'},
        5: {2: '유비'},
        6: {2: '삼진'},
        7: {2: '4구'},
        8: {3: '4구'},
        9: {3: '중안'},
    }
    return [{
        'name': f'{prefix}{order}',
        'playerCode': str(code_start + order),
        'batOrder': order,
        'rbi': 0,
        'run': 0,
        **{f'inn{inning}': result for inning, result in values.items()},
    } for order, values in innings.items()]


class KboCandleExtractionTest(unittest.TestCase):
    def test_batting_index_pitcher_mapping_and_non_pa_runner(self):
        rows = extract_baseball_data(response_fixture(), 'TESTGAME')
        away = [row for row in rows if row['team'] == '한화']
        plate_appearances = [row for row in away if row['batting_index'] is not None]
        non_pa = [row for row in away if row['batting_index'] is None]

        self.assertEqual(list(range(1, 11)), [row['batting_index'] for row in plate_appearances])
        self.assertEqual(['40001'] * 6 + ['40002'] * 4,
                         [row['pitcher_id'] for row in plate_appearances])
        self.assertEqual(1, len(non_pa))
        self.assertIsNone(non_pa[0]['pa_result'])
        self.assertIsNone(non_pa[0]['pitcher_id'])
        self.assertEqual(0, non_pa[0]['is_gs'])
        self.assertEqual(1, non_pa[0]['r'])

        first_starter_row = plate_appearances[0]
        self.assertEqual((2, 1, 1, 1), (
            first_starter_row['rbi'], first_starter_row['r'],
            first_starter_row['order'], first_starter_row['is_gs'],
        ))
        self.assertTrue(all(row['rbi'] == 0 and row['r'] == 0
                            for row in plate_appearances[1:]))

    def test_pitcher_rows_use_bf_as_pitch_count(self):
        rows = extract_pitcher_data(response_fixture(), 'TESTGAME')
        self.assertEqual(4, len(rows))
        self.assertEqual(('한화', '30001', '패', 70), (
            rows[0]['team'], rows[0]['player_id'], rows[0]['record'], rows[0]['pitched'],
        ))
        self.assertEqual(('삼성', '40001', '승', 80), (
            rows[2]['team'], rows[2]['player_id'], rows[2]['record'], rows[2]['pitched'],
        ))
        self.assertEqual('세', rows[3]['record'])
        self.assertEqual((1, 3, 4), (rows[0]['order'], rows[0]['er'], rows[0]['r']))
        self.assertEqual(2, rows[1]['order'])

    def test_leading_slash_keeps_non_pa_row_without_consuming_batting_index(self):
        fixture = response_fixture()
        away = fixture['result']['recordData']['battersBoxscore']['away']
        away[0].pop('inn2')
        away[-1].update({'inn2': '/4구', 'rbi': 1, 'run': 1})

        rows = [row for row in extract_baseball_data(fixture, 'TESTGAME')
                if row['team'] == '한화']
        plate_appearances = [row for row in rows if row['batting_index'] is not None]
        marker = [row for row in rows if row['player_name'] == '원정대주자'
                  and row['batting_index'] is None]

        self.assertEqual(list(range(1, 11)), [row['batting_index'] for row in plate_appearances])
        self.assertEqual('4구', plate_appearances[-1]['pa_result'])
        self.assertEqual(1, len(marker))
        self.assertIsNone(marker[0]['pa_result'])
        self.assertEqual((1, 1), (marker[0]['rbi'], marker[0]['r']))

    def test_missing_historical_pa_consumes_pitcher_slot_only(self):
        fixture = response_fixture()
        record = fixture['result']['recordData']
        record['battersBoxscore']['away'] = gap_lineup('원정', 10000)
        record['battersBoxscore']['home'] = gap_lineup('홈', 20000)
        record['pitchersBoxscore']['away'] = [
            {'pcode': '30001', 'name': '원정선발', 'pa': 10, 'bf': 70, 'inn': '5', 'wls': '', 'er': 1, 'r': 1},
            {'pcode': '30002', 'name': '원정구원', 'pa': 3, 'bf': 20, 'inn': '2', 'wls': '', 'er': 0, 'r': 0},
        ]
        record['pitchersBoxscore']['home'] = [
            {'pcode': '40001', 'name': '홈선발', 'pa': 10, 'bf': 75, 'inn': '5', 'wls': '', 'er': 1, 'r': 1},
            {'pcode': '40002', 'name': '홈구원', 'pa': 3, 'bf': 18, 'inn': '2', 'wls': '', 'er': 0, 'r': 0},
        ]

        rows = [row for row in extract_baseball_data(fixture, 'GAPGAME')
                if row['team'] == '한화' and row['batting_index'] is not None]
        self.assertEqual(12, len(rows))
        self.assertEqual(list(range(1, 13)), [row['batting_index'] for row in rows])
        self.assertEqual(['40001'] * 9 + ['40002'] * 3,
                         [row['pitcher_id'] for row in rows])

    def test_non_pa_result_is_not_counted(self):
        self.assertIsNone(parse_kbo_result(None))

    def test_missing_pitcher_box_can_leave_matchups_null(self):
        fixture = response_fixture()
        fixture['result']['recordData']['pitchersBoxscore'] = {'away': [], 'home': []}
        rows = extract_baseball_data(
            fixture, 'NOPITCHERS', include_pitcher_matchups=False,
        )
        plate_appearances = [row for row in rows if row['batting_index'] is not None]
        self.assertTrue(plate_appearances)
        self.assertTrue(all(row['pitcher_id'] is None and row['pitcher_name'] is None
                            for row in plate_appearances))
        self.assertEqual(list(range(1, 11)), [
            row['batting_index'] for row in plate_appearances if row['team'] == '한화'
        ])

    def test_legacy_box_without_bat_order_uses_grouped_substitute_rows(self):
        fixture = response_fixture()
        record = fixture['result']['recordData']
        for side in ('away', 'home'):
            for pitcher in record['pitchersBoxscore'][side]:
                pitcher['pCode'] = pitcher.pop('pcode')
        for side in ('away', 'home'):
            batters = record['battersBoxscore'][side]
            substitute = batters.pop()
            substitute['pos'] = '교'
            batters.insert(1, substitute)
            for batter in batters:
                batter.pop('batOrder', None)
                batter.setdefault('pos', '중')

        rows = [row for row in extract_baseball_data(fixture, 'LEGACYGAME')
                if row['team'] == '한화']
        substitute_rows = [row for row in rows if row['player_name'] == '원정대주자']
        starter_rows = [row for row in rows if row['player_name'] == '원정1']

        self.assertEqual(1, substitute_rows[0]['order'])
        self.assertEqual(0, substitute_rows[0]['is_gs'])
        self.assertTrue(all(row['order'] == 1 and row['is_gs'] == 1
                            for row in starter_rows))
        self.assertTrue(all(row['pitcher_id'] for row in rows
                            if row['batting_index'] is not None))
        self.assertEqual('30001', extract_pitcher_data(fixture, 'LEGACYGAME')[0]['player_id'])

    def test_winning_hit_marks_exact_plate_appearance_and_copies_position(self):
        fixture = response_fixture()
        record = fixture['result']['recordData']
        batter = record['battersBoxscore']['away'][0]
        batter['inn1'] = '유땅/유땅'
        batter.pop('inn2')
        record['etcRecords'] = [{
            'how': '결승타',
            'result': '원정1(1회 무사 3루서 유격수 땅볼)',
        }]

        rows = [row for row in extract_baseball_data(fixture, 'WINNINGGAME')
                if row['team'] == '한화']
        winning = [row for row in rows if row['is_gwrbi'] == 1]

        self.assertEqual(1, len(winning))
        self.assertEqual(('10001', 1, '유땅', 1, '중'), (
            winning[0]['player_id'], winning[0]['inning'], winning[0]['pa_result'],
            winning[0]['batting_index'], winning[0]['pos'],
        ))
        self.assertTrue(all(row['is_gwrbi'] == 0 for row in rows if row is not winning[0]))

    def test_run_out_marks_first_plate_appearance_in_batting_around_inning(self):
        fixture = response_fixture()
        record = fixture['result']['recordData']
        batter = record['battersBoxscore']['away'][0]
        batter['name'] = '원정일'
        batter['inn1'] = '우안/유땅'
        batter.pop('inn2')
        record['etcRecords'] = [{'how': '주루사', 'result': '원정일(1회)'}]

        rows = [row for row in extract_baseball_data(fixture, 'RUNOUTGAME')
                if row['team'] == '한화' and row['player_id'] == '10001']
        flagged = [row for row in rows if row['run_out']]

        self.assertEqual(1, len(flagged))
        self.assertEqual(('우안', 1, 1), (
            flagged[0]['pa_result'], flagged[0]['inning'], flagged[0]['run_out'],
        ))
        self.assertEqual(0, next(row['run_out'] for row in rows if row['pa_result'] == '유땅'))

    def test_pinch_runner_run_out_stays_on_non_pa_row(self):
        fixture = response_fixture()
        record = fixture['result']['recordData']
        away = record['battersBoxscore']['away']
        away[0].pop('inn2')
        away[-1].update({'inn2': '/4구'})
        record['etcRecords'] = [{'how': '주루사', 'result': '원정대주자(2회)'}]

        rows = [row for row in extract_baseball_data(fixture, 'PINCHRUNOUT')
                if row['team'] == '한화' and row['player_name'] == '원정대주자']
        flagged = [row for row in rows if row['run_out']]

        self.assertEqual(1, len(flagged))
        self.assertIsNone(flagged[0]['pa_result'])
        self.assertIsNone(flagged[0]['batting_index'])
        self.assertEqual((2, 1), (flagged[0]['inning'], flagged[0]['run_out']))


if __name__ == '__main__':
    unittest.main()
