import json
import unittest
import os
from pathlib import Path
from unittest.mock import patch
from update_kbo_scoreboard import parse_scoreboard, parse_naver_scoreboard, update_scoreboards_from_records


class NaverScoreboardTests(unittest.TestCase):
    @unittest.skipUnless(os.getenv('NAVER_RECORD_FIXTURE'), 'Optional supplied response fixture')
    def test_supplied_response(self):
        data = json.loads(Path(os.environ['NAVER_RECORD_FIXTURE']).read_text(encoding='utf-8-sig'))
        row = parse_naver_scoreboard(data, '20260926HHNC0')
        self.assertEqual(row[:7], ('20260926HHNC0', '2026-09-26', '한화', 'NC', 2, 3, '창원'))
        self.assertEqual(json.loads(row[-1]), [0,0,1,1,0,0,0,1,None])

    def payload(self):
        info = dict(gdate=20260926, aCode='HH', hCode='NC', aName='한화', hName='NC',
                    stadium='창원', statusCode='4', cancelFlag='N')
        game = dict(info, gmkey='20260926HHNC0', dheader='0', score=dict(aScore=2, hScore=3))
        return dict(code=200, success=True, result=dict(recordData=dict(gameInfo=info, games=[game],
                    scoreBoard=dict(rheb=dict(away=dict(r=2), home=dict(r=3)),
                                    inn=dict(away=[0,0,0,0,0,0,0,0,2], home=[0,0,1,1,0,0,0,1])))))

    def test_actual_shape_and_long_id(self):
        for game_id in ('20260926HHNC0', '20260926HHNC02026'):
            row = parse_naver_scoreboard(self.payload(), game_id)
            self.assertEqual(row[:7], ('20260926HHNC0', '2026-09-26', '한화', 'NC', 2, 3, '창원'))
            self.assertEqual(json.loads(row[-1]), [0,0,1,1,0,0,0,1,None])

    def test_live_cancelled_skipped(self):
        for field, value in [('statusCode','2'), ('cancelFlag','Y')]:
            data = self.payload()
            data['result']['recordData']['gameInfo'][field] = value
            self.assertIsNone(parse_naver_scoreboard(data, '20260926HHNC0'))

    def test_empty_finished_scoreboard_skipped(self):
        data = self.payload()
        data['result']['recordData']['scoreBoard'] = {
            'rheb': {'away': {}, 'home': {}},
            'inn': {'away': [], 'home': []},
        }
        self.assertIsNone(parse_naver_scoreboard(data, '20260926HHNC0'))

    def test_bad_score_and_identity(self):
        data = self.payload()
        data['result']['recordData']['scoreBoard']['inn']['away'][0] = 1
        with self.assertRaises(ValueError): parse_naver_scoreboard(data, '20260926HHNC0')
        with self.assertRaises(ValueError): parse_naver_scoreboard(self.payload(), '20260926LGHT0')

    def test_tie_and_extra_innings(self):
        data = self.payload()
        record = data['result']['recordData']
        record['scoreBoard']['inn'] = dict(away=[0]*11+[2], home=[0]*11+[2])
        record['scoreBoard']['rheb']['home']['r'] = 2
        record['games'][0]['score']['hScore'] = 2
        row = parse_naver_scoreboard(data, '20260926HHNC0')
        self.assertEqual(len(json.loads(row[-1])), 12)
        self.assertEqual(row[4:6], (2,2))

    def test_legacy_doubleheader_uses_two_as_series_marker(self):
        data = self.payload()
        record = data['result']['recordData']
        info = record['gameInfo']
        info.update(gdate=20090517, aCode='HH', hCode='LT', aName='한화', hName='롯데')
        first = record['games'][0]
        first.update(gdate=20090517, aCode='HH', hCode='LT', aName='한화', hName='롯데',
                     gmkey='20090517HHLT1', dheader='2')
        second = dict(first, gmkey='20090517HHLT2')
        record['games'].append(second)

        row = parse_naver_scoreboard(data, '20090517HHLT1')
        self.assertEqual(row[:4], ('20090517HHLT1', '2009-05-17', '한화', '롯데'))

    def test_legacy_game_list_uses_game_id_and_omits_optional_flags(self):
        data = self.payload()
        game = data['result']['recordData']['games'][0]
        game['gameId'] = game.pop('gmkey')
        game.pop('dheader')
        game.pop('cancelFlag')

        row = parse_naver_scoreboard(data, '20260926HHNC0')
        self.assertEqual(row[0], '20260926HHNC0')

    def test_called_game_and_postseason_pseudo_date(self):
        data = self.payload()
        record = data['result']['recordData']
        record['gameInfo']['gdate'] = 20261019
        record['gameInfo']['gameFlag'] = '3'
        record['games'][0].update(gdate=20261019, gmkey='33331019HHNC0')
        record['scoreBoard']['inn'] = dict(away=[1, 1, 0, 0, 0, 0], home=[0, 2, 0, 0, 0])
        record['scoreBoard']['rheb']['home']['r'] = 2
        record['games'][0]['score']['hScore'] = 2
        row = parse_naver_scoreboard(data, '33331019HHNC02026')
        self.assertEqual(row[:2], ('33331019HHNC02026', '2026-10-19'))
        self.assertEqual(json.loads(row[-1]), [0, 2, 0, 0, 0, None])

    @patch('update_kbo_scoreboard.requests.post', side_effect=AssertionError('No KBO HTTP allowed'))
    @patch('update_kbo_scoreboard.pymysql.connect')
    def test_reuse_no_http_and_preserve_tv(self, connect, post):
        cursor = connect.return_value.cursor.return_value.__enter__.return_value
        cursor.fetchall.return_value = [('20260926HHNC0',)]
        matches = [dict(game_id='20260926HHNC0', raw_data=self.payload())]
        self.assertEqual(update_scoreboards_from_records(matches, {}), 1)
        connect.return_value.commit.assert_called_once()
        sql = cursor.executemany.call_args.args[0]
        self.assertNotIn('tv=', sql.split('ON DUPLICATE KEY UPDATE')[1])
        post.assert_not_called()

    @patch('update_kbo_scoreboard.pymysql.connect')
    def test_missing_schedule_is_inserted_with_null_tv(self, connect):
        cursor = connect.return_value.cursor.return_value.__enter__.return_value
        self.assertEqual(update_scoreboards_from_records(
            [dict(game_id='20260926HHNC0', raw_data=self.payload())], {}), 1)
        sql = cursor.executemany.call_args.args[0]
        self.assertIn('NULL', sql)
        connect.return_value.commit.assert_called_once()


class ScoreboardTests(unittest.TestCase):
    def payload(self):
        return dict(code='100', LE_ID=1, SR_ID=0, G_ID='20260901HHKT0', G_DT='2026-09-01',
                    AWAY_NM='한화', HOME_NM='KT', S_NM='수원', T_SCORE_CN=1, B_SCORE_CN=6,
                    table2=json.dumps(dict(headers=[dict(row=[dict(Text=str(i)) for i in range(1, 13)])],
                        rows=[dict(row=[dict(Text=str(n)) for n in values]) for values in
                              [[0,0,0,0,0,0,0,0,1,'-','-','-'], [1,3,0,0,0,0,2,0,'-','-','-','-']]])))

    def test_innings(self):
        record = parse_scoreboard(self.payload(), '20260901HHKT0')
        self.assertEqual(json.loads(record[-2]), [0,0,0,0,0,0,0,0,1])
        self.assertEqual(json.loads(record[-1]), [1,3,0,0,0,0,2,0,None])

    def test_bad_total(self):
        data = self.payload()
        data['T_SCORE_CN'] = 2
        with self.assertRaises(ValueError): parse_scoreboard(data, '20260901HHKT0')

    def test_wrong_game(self):
        with self.assertRaises(ValueError): parse_scoreboard(self.payload(), '20260902HHKT0')

    def test_non_regular(self):
        data = self.payload()
        data['SR_ID'] = 9
        with self.assertRaises(ValueError): parse_scoreboard(data, '20260901HHKT0')


if __name__ == '__main__':
    unittest.main()
