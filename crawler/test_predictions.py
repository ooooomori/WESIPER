import unittest
from datetime import date
from fractions import Fraction
import numpy as np

from prediction_model import (BattingModel, Game, classify, aggregate, directions,
                              DELTAS, daily_ohlc, regular_bounds)


class PredictionsTest(unittest.TestCase):
    def test_project_scoring_rules(self):
        self.assertEqual(classify('타방'),9)
        self.assertTrue(np.all(DELTAS[classify('타방')]==0))
        for text in ('투희선','포희선','야선'):
            self.assertEqual(DELTAS[classify(text)].tolist(),[1,0,0,0,0,0])
        self.assertEqual(classify('투희실'),8)
        self.assertEqual(classify('중희비'),7)
        self.assertEqual(classify(''),None)
        with self.assertRaises(ValueError):
            classify('미확인')

    def test_exact_directions_match_fractions(self):
        current = np.array([123,37,15,2,3,61])
        def exact(s):
            a,h,b,hp,sf,tb = map(int,s)
            obp,slg = Fraction(h+b+hp,a+b+hp+sf),Fraction(tb,a)
            return [Fraction(h,a),obp,slg,obp+slg]
        base = exact(current)
        for event in DELTAS:
            expected = [int(n>b)-int(n<b) for n,b in zip(exact(current+event),base)]
            self.assertEqual(directions(current,event).tolist(),expected)
        self.assertEqual(directions(np.array([10,3,0,0,0,3]),np.array([10,3,0,0,0,3])).tolist(),[0,0,0,0])

    def test_aggregation_includes_recorded_zero_pa_appearance_and_excludes_nonregular(self):
        rows = [[1,'2026-03-15','a',1,1,'좌홈'],[2,'2026-03-28','b',1,1,''],
                [3,'2026-03-28','c',1,1,'타방'],[4,'2026-03-28','d',1,1,'좌홈']]
        games = aggregate(rows,{2026:('2026-03-28','2026-12-31')})
        self.assertEqual(len(games),3)
        self.assertEqual(games[0].events,[])
        self.assertEqual(games[0].stats.tolist(),[0]*6)
        self.assertEqual(games[1].stats.tolist(),[0]*6)
        self.assertEqual(games[2].stats.tolist(),[1,1,0,0,0,4])

    def test_probabilities_deterministic_and_past_only(self):
        model = BattingModel()
        total = np.zeros(6,dtype=np.int64)
        for day in range(1,7):
            g = Game(f'2026-04-{day:02}',str(day),1,[0,0,3,1])
            model.update(g)
            total += g.stats
        first = model.predict(1,'2026-04-06',total,2000)
        self.assertEqual(first,model.predict(1,'2026-04-06',total,2000))
        for metric in ('avg','obp','slg','ops'):
            probabilities = first[metric]
            self.assertAlmostEqual(sum(probabilities.values()),1)
            self.assertTrue(all(0 <= p <= 1 for p in probabilities.values()))
        self.assertGreaterEqual(first['hit'], first['home_run'])
        self.assertTrue(0 <= first['home_run'] <= 1)
        self.assertIsNone(model.predict(2,'2026-04-06',total,2000))

    def test_appearance_pa_distribution_includes_substitutes_and_observed_zero_pa(self):
        model = BattingModel()
        for day in range(1,8):
            game = Game(f'2026-04-{day:02}',str(day),1,[0,3,1,0])
            model.update(game)
        model.update(Game('2026-04-08','pinch',1,[6]))
        model.update(Game('2026-04-09','runner',1,[]))
        self.assertEqual(model.players[1].games,9)
        self.assertEqual(model.players[1].recent_pa,[4]*7+[1,0])
        event_p, sizes, probabilities = model.distributions(1,'2026-04-08')
        self.assertEqual(sizes.tolist(),[0,1,4])
        self.assertAlmostEqual(probabilities.sum(),1)
        self.assertGreater(probabilities[0],0)

    def test_hit_and_home_run_probability_from_same_simulation(self):
        model = BattingModel()
        current = np.zeros(6,dtype=np.int64)
        for day in range(1,7):
            game = Game(f'2026-04-{day:02}',str(day),1,[6,6,6,6])
            model.update(game)
            current += game.stats
        prediction = model.predict(1,'2026-04-06',current,2000)
        self.assertGreater(prediction['hit'],.5)
        self.assertGreater(prediction['home_run'],.1)
        self.assertLessEqual(prediction['home_run'],prediction['hit'])

    def test_walk_forward_labels_do_not_cross_season(self):
        from backtest_predictions import samples
        games = [Game(f'2025-04-{d:02}',str(d),1,[0,3,1,0]) for d in range(1,9)]
        games += [Game('2026-04-01','nextyear',1,[6,6,6,6])]
        result = samples(games,1000,1)
        self.assertTrue(result)
        self.assertTrue(all(r['as_of_date'] < r['target_date'] for r in result))
        self.assertTrue(all(r['as_of_date'][:4]==r['target_date'][:4] for r in result))
        earlier = samples(games[:-1],1000,1)
        self.assertEqual(result,earlier)
        self.assertTrue(all(r['event_y'] == [1,0] for r in result))
        self.assertTrue(all(len(r['event_raw']) == 2 for r in result))

    def test_calibration_bins_cover_boundaries_and_evaluation_runs(self):
        from backtest_predictions import scores, evaluate
        probs = np.tile(np.arange(11)[:,None]/10,(1,4))
        score = scores(probs,(probs>.5).astype(int))
        self.assertEqual(sum(b['n'] for b in score['avg']['calibration']),11)
        records = []
        for year in (2022,2023,2024):
            for i in range(25):
                records.append({'as_of_date':f'{year}-04-01','target_date':f'{year}-04-02',
                    'x':[i/25,i%3], 'raw':[.2+.01*i]*4,'y':[i%2]*4,
                    'event_raw':[.2+.01*i,.04+.002*i],'event_y':[i%2,int(i%5==0)]})
        result = evaluate(records)
        self.assertEqual(result[2024]['test_n'],25)
        self.assertEqual(result[2024]['event_models']['raw_records']['hit']['n'],25)


if __name__ == '__main__':
    unittest.main()
