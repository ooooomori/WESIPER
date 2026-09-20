"""Calendar walk-forward comparison; immutable DB snapshot recommended.

Each sample is issued after a player's last game that day and targets their next
PA-containing game on a later day in the same season. All models share samples.
Sampling is deterministic by player/date, never by outcomes or future PA.
"""
import argparse
from collections import defaultdict
from itertools import groupby
import hashlib
import json
from pathlib import Path

import numpy as np

from prediction_data import load
from prediction_model import BattingModel, METRICS, VERSION, daily_ohlc, directions, ohlc_features


def samples(games, draws, every):
    model = BattingModel()
    totals, bars, pending = {}, {}, {}
    records = []
    year = None
    for day, day_iter in groupby(games, key=lambda g:g.day):
        if year != day[:4]:
            totals, bars, pending = {}, {}, {}
            year = day[:4]
        daily = defaultdict(list)
        for game in day_iter:
            daily[game.player].append(game)
            if game.player in pending:
                sample = pending.pop(game.player)
                sample['target_date'] = day
                sample['target_game'] = game.game_id
                sample['y'] = (directions(sample.pop('baseline'), game.stats) > 0).astype(int).tolist()
                counts = game.counts
                sample['event_y'] = [int(counts[3:7].sum() > 0), int(counts[6] > 0)]
                records.append(sample)
        # Finish the complete day before issuing any new prediction (nightly cadence).
        for player, played in daily.items():
            current = totals.setdefault(player,np.zeros(6,dtype=np.int64))
            bars.setdefault(player,[]).append(daily_ohlc(current, played))
            bars[player] = bars[player][-30:]
            for game in played:
                current += game.stats
        for game in sorted([g for played in daily.values() for g in played],key=lambda g:(g.game_id,g.player)):
            model.update(game)
        if year < '2019':
            continue
        for player in daily:
            choice = int.from_bytes(hashlib.sha256(f'{player}|{day}'.encode()).digest()[:4],'big')
            if choice % every:
                continue
            prob = model.predict(player,day,totals[player],draws)
            if prob is None:
                continue
            pending[player] = {'as_of_date':day,'player':player,
                'baseline':totals[player].copy(), 'x':ohlc_features(bars[player]).tolist(),
                'raw':[prob[m]['up'] for m in METRICS],
                'event_raw':[prob['hit'],prob['home_run']]}
        if day.endswith('-01'):
            print(f'{day}: {len(records)} resolved samples',flush=True)
    return sorted(records,key=lambda r:(r['as_of_date'],r['player']))


def sigmoid(x):
    return 1/(1+np.exp(-np.clip(x,-35,35)))


def fit_logistic(x, y, ridge=1.0):
    """Small deterministic ridge logistic baseline; only NumPy is needed."""
    mean, scale = x.mean(axis=0), x.std(axis=0)
    scale[scale < 1e-8] = 1
    z = np.column_stack((np.ones(len(x)),(x-mean)/scale))
    beta = np.zeros((z.shape[1],y.shape[1]))
    penalty = np.eye(z.shape[1])*ridge
    penalty[0,0] = 1e-8
    for k in range(y.shape[1]):
        for _ in range(40):
            p = sigmoid(z @ beta[:,k])
            w = np.maximum(p*(1-p),1e-6)
            step = np.linalg.solve(z.T @ (w[:,None]*z)+penalty,
                z.T @ (p-y[:,k])+penalty @ beta[:,k])
            beta[:,k] -= step
            if np.max(np.abs(step)) < 1e-7:
                break
    return lambda new: sigmoid(np.column_stack((np.ones(len(new)),(new-mean)/scale)) @ beta)


def logit(p):
    p = np.clip(p,1e-5,1-1e-5)
    return np.log(p/(1-p))


def scores(p, y, names=METRICS):
    result = {}
    for j,metric in enumerate(names):
        pred, actual = np.clip(p[:,j],1e-7,1-1e-7), y[:,j]
        bins = []
        bin_ids = np.minimum((p[:,j]*10).astype(int),9)
        for bin_id in range(10):
            lower = bin_id/10
            mask = bin_ids == bin_id
            if mask.any():
                rate = float(actual[mask].mean())
                n = int(mask.sum())
                # Wilson interval for the observed rate.
                center = (rate+1.96**2/(2*n))/(1+1.96**2/n)
                half = 1.96*np.sqrt(rate*(1-rate)/n+1.96**2/(4*n*n))/(1+1.96**2/n)
                bins.append({'range':[round(float(lower),1),round(float(lower+.1),1)],'n':n,
                    'predicted':float(pred[mask].mean()),'observed':rate,'observed_95ci':[center-half,center+half]})
        result[metric] = {'n':len(y),'brier':float(np.mean((pred-actual)**2)),
            'log_loss':float(-np.mean(actual*np.log(pred)+(1-actual)*np.log(1-pred))),
            'accuracy':float(np.mean((pred>=.5)==actual)), 'observed_up':float(actual.mean()),
            'ece':sum(b['n']*abs(b['predicted']-b['observed']) for b in bins)/len(y), 'calibration':bins}
    return result


def evaluate(records):
    x = np.array([r['x'] for r in records])
    raw = np.array([r['raw'] for r in records])
    y = np.array([r['y'] for r in records])
    event_raw = np.array([r['event_raw'] for r in records])
    event_y = np.array([r['event_y'] for r in records])
    issue = np.array([r['as_of_date'] for r in records])
    target = np.array([r['target_date'] for r in records])
    combined = np.column_stack((x,logit(raw)))
    folds = {}
    for year in (2024,2025,2026):
        start, calibration_start = f'{year}-01-01', f'{year-1}-01-01'
        train = target < calibration_start
        calibration = (issue >= calibration_start) & (target < start)
        test = (issue >= start) & (target < f'{year+1}-01-01')
        if min(train.sum(),calibration.sum(),test.sum()) < 20:
            continue
        oh = fit_logistic(x[train],y[train])
        hybrid = fit_logistic(combined[train],y[train])
        # Calibration periods are disjoint from training and final test.
        raw_cal = fit_logistic(logit(raw[calibration]),y[calibration])
        predictions = {'frequency':np.tile(y[train].mean(axis=0),(test.sum(),1)),
            'ohlc_only':oh(x[test]), 'raw_records':raw[test],
            'raw_calibrated':raw_cal(logit(raw[test])), 'raw_plus_ohlc':hybrid(combined[test])}
        event_predictions = {'frequency':np.tile(event_y[train].mean(axis=0),(test.sum(),1)),
            'ohlc_only':fit_logistic(x[train],event_y[train])(x[test]),
            'raw_records':event_raw[test],
            'raw_calibrated':fit_logistic(logit(event_raw[calibration]),event_y[calibration])(logit(event_raw[test]))}
        # Paired resampling by target date preserves same-day clustering. This is
        # descriptive uncertainty, not proof of independence across player careers.
        delta = ((predictions['raw_records']-y[test])**2-(predictions['ohlc_only']-y[test])**2).mean(axis=1)
        dates = target[test]
        groups = [delta[dates==d] for d in sorted(set(dates))]
        group_sum = np.array([g.sum() for g in groups])
        group_n = np.array([len(g) for g in groups])
        picks = np.random.default_rng(year).integers(0,len(groups),(1000,len(groups)))
        bootstrap = group_sum[picks].sum(axis=1)/group_n[picks].sum(axis=1)
        folds[year] = {'train_n':int(train.sum()),'calibration_n':int(calibration.sum()),'test_n':int(test.sum()),
            'test_target_end':max(target[test].tolist()),
            'raw_minus_ohlc_brier':{'difference':float(delta.mean()),
                'target_date_bootstrap_95ci':np.quantile(bootstrap,[.025,.975]).tolist()},
            'models':{name:scores(p,y[test]) for name,p in predictions.items()},
            'event_models':{name:scores(p,event_y[test],('hit','home_run')) for name,p in event_predictions.items()}}
    return folds


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshot',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--draws',type=int,default=2000)
    parser.add_argument('--every',type=int,default=5,help='Keep 1/N historical issue dates per player')
    parser.add_argument('--reuse-samples',action='store_true')
    opt = parser.parse_args()
    if opt.draws < 1000 or opt.every < 1:
        parser.error('draws >= 1000 and every >= 1 required')
    games, fingerprint, bounds, count = load(opt.snapshot)
    cache = opt.output.with_suffix('.samples.json')
    signature = {'version':VERSION,'data_version':fingerprint,'draws':opt.draws,'every':opt.every}
    opt.output.parent.mkdir(parents=True,exist_ok=True)
    if opt.reuse_samples and cache.exists():
        cached = json.loads(cache.read_text(encoding='utf-8'))
        if cached['signature'] != signature:
            raise ValueError('Sample cache does not match source/model/settings')
        records = cached['records']
    else:
        records = samples(games,opt.draws,opt.every)
        cache.write_text(json.dumps({'signature':signature,'records':records}),encoding='utf-8')
    result = {'version':VERSION,'data_version':fingerprint,'source_rows':count,'draws':opt.draws,
        'sample_every':opt.every,'sample_count':len(records),'folds':evaluate(records),
        'limitations':['Retrospective corrected DB, not historical ingestion snapshots',
            'Evaluated after appearance days; off-day refreshes not separately scored',
            'Same-inning PA order uses source PK; no official event sequence available',
            'No lineup/opponent inputs; conditional on next recorded appearance in same season',
            'Zero-PA appearances are only visible when a source player-game row exists',
            '2026 is a partial season; parameters are fixed, no claim of optimality']}
    opt.output.parent.mkdir(parents=True,exist_ok=True)
    opt.output.write_text(json.dumps(result,indent=2),encoding='utf-8')
    for year, fold in result['folds'].items():
        print(year,fold['test_n'],{name:round(sum(m['brier'] for m in metrics.values())/4,5)
              for name,metrics in fold['models'].items()},flush=True)


if __name__ == '__main__':
    main()
