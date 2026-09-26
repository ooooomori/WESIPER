import json
import unittest
from update_kbo_scoreboard import parse_scoreboard


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
