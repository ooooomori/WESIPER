"""Past-only batting model. No network, database writes, or displayed-rate rounding."""
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date
import hashlib
import re

import numpy as np

VERSION = 'batting-distribution-v2-events'
METRICS = ('avg', 'obp', 'slg', 'ops')
EVENTS = ('AB_NO_HIT', 'BB', 'HBP', '1B', '2B', '3B', 'HR', 'SF', 'SH', 'CI')
# AB, H, BB, HBP, SF, TB. SH and CI count as PA but change none of these.
DELTAS = np.array([[1,0,0,0,0,0], [0,0,1,0,0,0], [0,0,0,1,0,0],
                   [1,1,0,0,0,1], [1,1,0,0,0,2], [1,1,0,0,0,3],
                   [1,1,0,0,0,4], [0,0,0,0,1,0], [0,0,0,0,0,0],
                   [0,0,0,0,0,0]], dtype=np.int64)


def classify(text):
    text = (text or '').strip()
    if not text:
        return None
    if text in ('4구', '볼넷', '고4') or '볼넷' in text:
        return 1
    if text == '사구':
        return 2
    if '타방' in text:
        return 9
    if text == '야선' or text.endswith('희선'):
        return 0  # Explicit project rule: hitless AB.
    if '희비' in text or '희플' in text:
        return 7
    if any(s in text for s in ('희번', '희타', '희실')):
        return 8
    for suffix, event in [('안',3), ('2',4), ('3',5), ('홈',6)]:
        if text.endswith(suffix):
            return event
    if text in ('삼진', '스낫') or any(s in text for s in ('땅','비','파','직','병','실','번','삼중')):
        return 0
    raise ValueError(f'Unknown PA result: {text!r}; review before publishing')


def regular_bounds(php):
    """Reuse the application's season boundaries rather than a second calendar."""
    return {int(y): (start, end) for y, start, end in re.findall(
        r"'(\d{4})'\s*=>\s*\[.*?'regular'\s*=>\s*\['([^']+)',\s*'([^']+)'\]", php)}


@dataclass
class Game:
    day: str
    game_id: str
    player: int
    events: list = field(default_factory=list)

    @property
    def counts(self):
        return np.bincount(self.events, minlength=len(EVENTS))

    @property
    def stats(self):
        return self.counts @ DELTAS


def aggregate(rows, bounds):
    """Rows ordered by date/game/player/inning/PK; PK breaks same-inning ties.

    Never deduplicate identical PA strings: two strikeouts can be legitimate.
    Empty runner-only rows can represent recorded appearances with zero PA.
    """
    games = {}
    previous = None
    for pk, day, gid, player, inning, text in rows:
        day = str(day)
        if not gid or not player:
            raise ValueError('Missing game/player identity')
        order = (day, gid, int(player), int(inning or 0), int(pk))
        if previous is not None and order < previous:
            raise ValueError('Source records must be ordered chronologically')
        previous = order
        start, end = bounds.get(int(day[:4]), ('9999', '0000'))
        if not start <= day <= end:
            continue
        key = (day, gid, int(player))
        game = games.setdefault(key, Game(day, gid, int(player)))
        event = classify(text)
        if event is None:
            continue
        game.events.append(event)
    return list(games.values())


def rates(stats):
    ab, h, bb, hbp, sf, tb = np.asarray(stats, dtype=float).T
    den = ab + bb + hbp + sf
    avg = np.divide(h, ab, out=np.zeros_like(ab), where=ab > 0)
    obp = np.divide(h + bb + hbp, den, out=np.zeros_like(den), where=den > 0)
    slg = np.divide(tb, ab, out=np.zeros_like(ab), where=ab > 0)
    return np.stack((avg, obp, slg, obp + slg), axis=-1)


def directions(current, additions):
    """Exact integer cross-products preserve ties, including OPS cancellation."""
    a, h, b, hp, sf, tb = np.asarray(current, dtype=np.int64)
    if a <= 0:
        raise ValueError('Undefined baseline AVG/SLG')
    nxt = np.asarray(additions, dtype=np.int64) + current
    na, nh, nb, nhp, nsf, ntb = nxt.T
    d, u = a + b + hp + sf, h + b + hp
    nd, nu = na + nb + nhp + nsf, nh + nb + nhp
    return np.sign(np.stack((nh*a-h*na, nu*d-u*nd, ntb*a-tb*na,
        (nu*na+ntb*nd)*d*a-(u*a+tb*d)*nd*na), axis=-1))


@dataclass
class Profile:
    counts: np.ndarray = field(default_factory=lambda: np.zeros(len(EVENTS)))
    last_ordinal: int = 0
    recent_pa: list = field(default_factory=list)
    games: int = 0
    pa: int = 0

    def weighted(self, ordinal):
        return self.counts * 0.5 ** ((ordinal-self.last_ordinal)/365.0) if self.last_ordinal else self.counts

    def update(self, game):
        ordinal = date.fromisoformat(game.day).toordinal()
        self.counts = self.weighted(ordinal) + game.counts
        self.last_ordinal = ordinal
        self.recent_pa = (self.recent_pa + [len(game.events)])[-20:]
        self.games += 1
        self.pa += len(game.events)


class BattingModel:
    def __init__(self):
        self.players = defaultdict(Profile)
        self.league = Profile()
        self.pa_counts = defaultdict(float)

    def update(self, game):
        self.players[game.player].update(game)
        self.league.update(game)
        self.pa_counts[len(game.events)] += 1

    def distributions(self, player, day):
        p = self.players[player]
        ordinal = date.fromisoformat(day).toordinal()
        league = self.league.weighted(ordinal)
        if league.sum() == 0:
            raise ValueError('No historical league data')
        # Empirical-Bayes shrinkage, parameters fixed before evaluation.
        counts = p.weighted(ordinal) + 100 * league / league.sum()
        event_p = counts / counts.sum()
        sizes = np.array(sorted(self.pa_counts), dtype=int)
        league_pa = np.array([self.pa_counts[n] for n in sizes])
        n_weights = np.array([p.recent_pa.count(n) for n in sizes], dtype=float)
        n_weights += 5 * league_pa / league_pa.sum()
        return event_p, sizes, n_weights/n_weights.sum()

    def predict(self, player, day, current, draws=100000):
        p = self.players[player]
        if p.pa < 20 or p.games < 5 or current[0] <= 0:
            return None
        event_p, sizes, n_p = self.distributions(player, day)
        seed = int.from_bytes(hashlib.sha256(f'{VERSION}|{player}|{day}'.encode()).digest()[:8], 'big')
        rng = np.random.default_rng(seed)
        allocations = rng.multinomial(draws, n_p)
        totals = np.zeros((len(METRICS), 3), dtype=np.int64)
        event_totals = np.zeros(2, dtype=np.int64)
        for n, count in zip(sizes, allocations):
            if not count:
                continue
            outcomes = rng.multinomial(int(n), event_p, size=int(count))
            additions = outcomes @ DELTAS
            signs = directions(current, additions)
            totals[:, 0] += (signs > 0).sum(axis=0)
            totals[:, 1] += (signs < 0).sum(axis=0)
            totals[:, 2] += (signs == 0).sum(axis=0)
            event_totals[0] += np.count_nonzero(outcomes[:, 3:7].sum(axis=1))
            event_totals[1] += np.count_nonzero(outcomes[:, 6])
        result = {metric: dict(zip(('up','down','flat'), (totals[i]/draws).tolist()))
                  for i, metric in enumerate(METRICS)}
        result['hit'] = float(event_totals[0] / draws)
        result['home_run'] = float(event_totals[1] / draws)
        return result


def daily_ohlc(current, games):
    """Same daily aggregation/rounding as the chart, for OHLC-only comparison."""
    values = [rates(current)]
    state = current.copy()
    for game in games:
        for event in game.events:
            state += DELTAS[event]
            values.append(rates(state))
    values = np.array(values)
    return np.round(np.stack((values[0], values.max(axis=0), values.min(axis=0), values[-1])), 3)


def ohlc_features(bars):
    last = bars[-1]
    closes = np.array([b[3] for b in bars[-30:]])
    # Only OHLC values, without PA/AB/player identity or future information.
    return np.concatenate((last.ravel(), (last[3]-last[0]),
        (last[1]-last[2]), closes.mean(axis=0), closes.std(axis=0),
        closes[-1]-closes[0]))
