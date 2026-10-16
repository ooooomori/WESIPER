const num = value => { const n = Number(value); return Number.isFinite(n) ? n : 0; };
// "5.1" 이닝 → 아웃 카운트 16
const outsOf = value => { const [whole, outs = '0'] = String(value ?? '0').split('.'); return (Number(whole) || 0) * 3 + (Number(outs) || 0); };

const batterKeys = ['pa', 'ab', 'h', 'doubles', 'triples', 'hr', 'rbi', 'r', 'bb', 'hbp', 'so', 'sf', 'sb', 'cs'];
export function summarizeBatter(games) {
    const sum = Object.fromEntries(batterKeys.map(key => [key, 0]));
    sum.tb = 0;
    for (const game of games) {
        for (const key of batterKeys) sum[key] += num(game[key]);
        sum.tb += num(game.h) + num(game.doubles) + 2 * num(game.triples) + 3 * num(game.hr);
    }
    const plate = sum.ab + sum.bb + sum.hbp + sum.sf;
    const obp = plate ? (sum.h + sum.bb + sum.hbp) / plate : null;
    const slg = sum.ab ? sum.tb / sum.ab : null;
    return { avg: sum.ab ? sum.h / sum.ab : null, obp, slg, ops: obp !== null && slg !== null ? obp + slg : null, played: plate > 0, sample: plate, totals: { games: games.length, ...sum } };
}

export function summarizePitcher(games) {
    const sum = { outs: 0, er: 0, r: 0, h: 0, hr: 0, bb: 0, hbp: 0, so: 0, ab: 0, doubles: 0, triples: 0, sf: 0 };
    for (const game of games) {
        sum.outs += outsOf(game.innings);
        for (const key of ['er', 'r', 'h', 'hr', 'bb', 'hbp', 'so', 'ab', 'doubles', 'triples', 'sf']) sum[key] += num(game[key]);
    }
    const count = badge => games.filter(game => game.badge === badge).length;
    // QS: 선발로 6이닝 이상 던지고 3자책 이하.
    const qs = games.filter(game => game.isStarter === true && game.er != null && outsOf(game.innings) >= 18 && num(game.er) <= 3).length;
    const innings = `${Math.floor(sum.outs / 3)}${sum.outs % 3 ? `.${sum.outs % 3}` : ''}`;
    // 상대한 타자 기준 피안타율·피OPS(타수 기록이 없는 옛 경기는 계산하지 않는다)
    const faced = sum.ab > 0;
    const tb = sum.h + sum.doubles + 2 * sum.triples + 3 * sum.hr;
    const plate = sum.ab + sum.bb + sum.hbp + sum.sf;
    const obpAgainst = faced && plate ? (sum.h + sum.bb + sum.hbp) / plate : null;
    const slgAgainst = faced ? tb / sum.ab : null;
    return { era: sum.outs ? sum.er * 27 / sum.outs : null, avg: faced ? sum.h / sum.ab : null, obp: obpAgainst, slg: slgAgainst, ops: obpAgainst !== null && slgAgainst !== null ? obpAgainst + slgAgainst : null, whip: sum.outs ? (sum.h + sum.bb) * 3 / sum.outs : null, played: sum.outs > 0, sample: sum.outs, totals: { games: games.length, ...sum, innings, wins: count('승리'), losses: count('패전'), holds: count('홀드'), saves: count('세이브'), qs } };
}

// 3월은 개막 직후 경기가 적어 4월과 합쳐 보여준다.
const monthKey = game => { const month = String(game.date || '').slice(5, 7); return month === '03' ? '04' : month; };
const groups = [
    ['month', '월별', game => monthKey(game), key => key === '04' ? '3~4월' : `${Number(key)}월`, (a, b) => Number(a.key) - Number(b.key)],
    ['opponent', '상대별', game => game.opponent || '', key => key, (a, b) => b.games - a.games || a.key.localeCompare(b.key, 'ko')],
    ['venue', '홈/원정', game => game.isAway === true ? 'away' : game.isAway === false ? 'home' : '', key => key === 'home' ? '홈' : '원정', (a, b) => (a.key === 'home' ? 0 : 1) - (b.key === 'home' ? 0 : 1)],
];

// 경기 일지를 월별·상대별·홈/원정별로 묶어 지표를 계산한다. 월별 끝에는 전반기·후반기를 덧붙인다(halfStarts: 연도 → 후반기 시작일).
export function buildSplits(games, pitcher, halfStarts = {}) {
    const summarize = pitcher ? summarizePitcher : summarizeBatter;
    const played = (games || []).filter(game => game.date);
    const bucket = (keyOf, labelOf) => {
        const buckets = new Map();
        for (const game of played) {
            const key = keyOf(game);
            if (!key) continue;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key).push(game);
        }
        return [...buckets].map(([key, list]) => ({ key, label: labelOf(key), games: list.length, list, ...summarize(list) })).filter(row => row.played);
    };
    const halfOf = game => { const start = halfStarts[String(game.date).slice(0, 4)]; return !start ? '' : game.date < start ? 'first' : 'second'; };
    const halves = bucket(halfOf, key => key === 'first' ? '전반기' : '후반기').sort((a, b) => (a.key === 'first' ? 0 : 1) - (b.key === 'first' ? 0 : 1)).map(row => ({ ...row, section: 'half' }));
    const total = summarize(played);
    return {
        total,
        groups: groups.map(([id, title, keyOf, labelOf, order]) => ({ id, title, total, rows: [...bucket(keyOf, labelOf).sort(order), ...(id === 'month' ? halves : [])] })),
    };
}

const paKeys = ['pa', 'ab', 'h', 'tb', 'doubles', 'triples', 'hr', 'bb', 'hbp', 'sf', 'so'];
const paRates = t => {
    const plate = t.ab + t.bb + t.hbp + t.sf;
    const obp = plate ? (t.h + t.bb + t.hbp) / plate : null;
    const slg = t.ab ? t.tb / t.ab : null;
    return { avg: t.ab ? t.h / t.ab : null, obp, slg, ops: obp !== null && slg !== null ? obp + slg : null, played: plate > 0, sample: plate };
};

// 서버가 이닝·타순별로 합쳐 보낸 타석 기록에서 타율과 OPS를 계산한다. 기준선은 그 타석들의 합계다.
export function buildPaRows(rows, labelOf) {
    const list = (rows || []).map(row => {
        const totals = Object.fromEntries([...paKeys, ...['sb', 'cs'].filter(key => row[key] != null)].map(key => [key, num(row[key])]));
        return { key: String(row.key), label: labelOf(Number(row.key)), games: totals.pa, unit: '타석', ...paRates(totals), totals };
    }).filter(row => row.played);
    return { rows: list, total: paRates(Object.fromEntries(paKeys.map(key => [key, list.reduce((sum, row) => sum + row.totals[key], 0)]))) };
}
