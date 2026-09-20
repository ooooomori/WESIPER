"""Nightly prediction batch; consumes only records already in MySQL.

Default is read-only and emits JSON. Explicit --write-db publishes atomically.
"""
import argparse
from collections import defaultdict
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path

import numpy as np

from prediction_data import connect, load
from prediction_model import BattingModel, VERSION, EVENTS, rates

SCHEMA = [
    '''CREATE TABLE IF NOT EXISTS kbo_player_game_stats (
        player_id INT NOT NULL, game_id VARCHAR(17) NOT NULL, game_date DATE NOT NULL,
        season_year INT NOT NULL, season_type VARCHAR(16) NOT NULL,
        pa INT NOT NULL, ab INT NOT NULL, h INT NOT NULL, bb INT NOT NULL,
        hbp INT NOT NULL, sf INT NOT NULL, tb INT NOT NULL,
        event_counts TEXT NOT NULL, data_version CHAR(64) NOT NULL,
        PRIMARY KEY (player_id,game_id), KEY idx_prediction_date (game_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4''',
    '''CREATE TABLE IF NOT EXISTS kbo_player_predictions (
        player_id INT NOT NULL, season_year INT NOT NULL, season_type VARCHAR(16) NOT NULL,
        as_of_date DATE NOT NULL, model_version VARCHAR(64) NOT NULL,
        data_version CHAR(64) NOT NULL, source_revision VARCHAR(64) NOT NULL,
        generated_at DATETIME NOT NULL, payload MEDIUMTEXT NOT NULL,
        PRIMARY KEY (player_id,season_year,as_of_date,model_version,data_version),
        KEY idx_prediction_lookup (player_id,season_year,generated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4''',
]


def revision():
    path = Path(os.getenv('WESIPER_CANDLE_REVISION_FILE', '/tmp/wesiper-candle-data-revision'))
    return path.read_text().strip() if path.exists() else 'initial'


def build(games, fingerprint, source_revision, draws=100000):
    model = BattingModel()
    current = defaultdict(lambda: np.zeros(6, dtype=np.int64))
    last = {}
    season_games = []
    for game in games:
        model.update(game)
        if game.day.startswith('2026-'):
            current[game.player] += game.stats
            last[game.player] = game.day
            season_games.append(game)
    if not season_games:
        raise ValueError('No 2026 regular-season records')
    as_of = max(g.day for g in season_games)
    generated = datetime.now(timezone.utc).isoformat()
    predictions = []
    for player, baseline in sorted(current.items()):
        probability = model.predict(player, as_of, baseline, draws)
        profile = model.players[player]
        predictions.append({
            'player_id': player, 'year': 2026, 'season': 'regular',
            'status': 'ready' if probability else 'insufficient_data',
            'as_of_date': as_of, 'last_player_date': last[player],
            'generated_at': generated, 'model_version': VERSION,
            'data_version': fingerprint, 'source_revision': source_revision,
            'target': 'next_recorded_appearance_same_season', 'assumed_appearance': True,
            'baseline': dict(zip(('ab','h','bb','hbp','sf','tb'), baseline.tolist())),
            'current': dict(zip(('avg','obp','slg','ops'), rates(baseline).tolist())),
            'historical_pa': profile.pa, 'historical_games': profile.games,
            'draws': draws, 'mc_max_standard_error': 0.5/np.sqrt(draws),
            'probabilities': probability,
        })
    return predictions


def persist(games, predictions, init_schema=False):
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT GET_LOCK('wesiper-candle-predictions',0)")
            if cur.fetchone()[0] != 1:
                raise RuntimeError('Another prediction publication is in progress')
            if init_schema:
                for statement in SCHEMA:
                    cur.execute(statement)
            cur.execute('SELECT MAX(as_of_date) FROM kbo_player_predictions WHERE season_year=2026')
            newest = cur.fetchone()[0]
            if newest and str(newest) > predictions[0]['as_of_date']:
                raise RuntimeError('Refusing to replace derived stats with an older snapshot')
            conn.begin()
            # Replace only derived 2026 rows; raw records and archived predictions are untouched.
            cur.execute('DELETE FROM kbo_player_game_stats WHERE season_year=2026 AND season_type=%s', ('regular',))
            rows = []
            for g in games:
                if g.day.startswith('2026-'):
                    rows.append((g.player,g.game_id,g.day,2026,'regular',len(g.events),
                        *g.stats.tolist(),json.dumps(dict(zip(EVENTS,g.counts.tolist()))),predictions[0]['data_version']))
            cur.executemany('''INSERT INTO kbo_player_game_stats
                (player_id,game_id,game_date,season_year,season_type,pa,ab,h,bb,hbp,sf,tb,event_counts,data_version)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)''', rows)
            for p in predictions:
                cur.execute('''INSERT INTO kbo_player_predictions
                    (player_id,season_year,season_type,as_of_date,model_version,data_version,source_revision,generated_at,payload)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
                    ON DUPLICATE KEY UPDATE generated_at=VALUES(generated_at),payload=VALUES(payload),source_revision=VALUES(source_revision)''',
                    (p['player_id'],2026,'regular',p['as_of_date'],VERSION,p['data_version'],p['source_revision'],
                     p['generated_at'][:19].replace('T',' '),json.dumps(p,ensure_ascii=False)))
        if revision() != predictions[0]['source_revision']:
            raise RuntimeError('Source revision changed before publication')
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        with conn.cursor() as cur:
            cur.execute("SELECT RELEASE_LOCK('wesiper-candle-predictions')")
        conn.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshot', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--draws', type=int, default=100000)
    parser.add_argument('--through', default=(datetime.now(timezone(timedelta(hours=9))).date()-timedelta(days=1)).isoformat())
    parser.add_argument('--write-db', action='store_true')
    parser.add_argument('--init-schema', action='store_true')
    opt = parser.parse_args()
    if opt.draws < 1000 or opt.draws > 1000000:
        parser.error('draws must be between 1,000 and 1,000,000')
    if opt.snapshot and opt.write_db:
        parser.error('Offline snapshots cannot publish to the production database')
    if opt.init_schema and not opt.write_db:
        parser.error('--init-schema requires --write-db')
    before = revision()
    games, fingerprint, bounds, count = load(opt.snapshot, opt.through)
    if not bounds[2026][0] <= opt.through <= bounds[2026][1]:
        parser.error('Prediction publication is restricted to the 2026 regular season')
    predictions = build(games, fingerprint, before, opt.draws)
    if revision() != before:
        raise RuntimeError('Source changed during prediction; retry on a stable snapshot')
    if opt.write_db:
        persist(games, predictions, opt.init_schema)
    result = {'model':VERSION,'source_rows':count,'player_games':len(games),'predictions':predictions}
    if opt.output:
        opt.output.parent.mkdir(parents=True,exist_ok=True)
        opt.output.write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps({'players':len(predictions),'ready':sum(p['status']=='ready' for p in predictions),
        'as_of':predictions[0]['as_of_date'],'published':opt.write_db}))


if __name__ == '__main__':
    main()
