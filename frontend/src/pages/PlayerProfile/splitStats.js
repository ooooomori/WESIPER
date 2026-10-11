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
    return { avg: sum.ab ? sum.h / sum.ab : null, obp, slg, ops: obp !== null && slg !== null ? obp + slg : null, played: plate > 0, totals: { games: games.length, ...sum } };
}

export function summarizePitcher(games) {
    const sum = { outs: 0, er: 0, r: 0, h: 0, hr: 0, bb: 0, hbp: 0, so: 0 };
    for (const game of games) {
        sum.outs += outsOf(game.innings);
        for (const key of ['er', 'r', 'h', 'hr', 'bb', 'hbp', 'so']) sum[key] += num(game[key]);
    }
    const count = badge => games.filter(game => game.badge === badge).length;
    // QS: 선발로 6이닝 이상 던지고 3자책 이하.
    const qs = games.filter(game => game.isStarter === true && game.er != null && outsOf(game.innings) >= 18 && num(game.er) <= 3).length;
    const innings = `${Math.floor(sum.outs / 3)}${sum.outs % 3 ? `.${sum.outs % 3}` : ''}`;
    return { era: sum.outs ? sum.er * 27 / sum.outs : null, whip: sum.outs ? (sum.h + sum.bb) * 3 / sum.outs : null, played: sum.outs > 0, totals: { games: games.length, ...sum, innings, wins: count('승리'), losses: count('패전'), holds: count('홀드'), saves: count('세이브'), qs } };
}

// 3월은 개막 직후 경기가 적어 4월과 합쳐 보여준다.
const monthKey = game => { const month = String(game.date || '').slice(5, 7); return month === '03' ? '04' : month; };
const groups = [
    ['month', '월별', game => monthKey(game), key => key === '04' ? '3~4월' : `${Number(key)}월`, (a, b) => Number(a.key) - Number(b.key)],
    ['opponent', '상대별', game => game.opponent || '', key => key, (a, b) => b.games - a.games || a.key.localeCompare(b.key, 'ko')],
    ['venue', '홈/원정', game => game.isAway === true ? 'away' : game.isAway === false ? 'home' : '', key => key === 'home' ? '홈' : '원정', (a, b) => (a.key === 'home' ? 0 : 1) - (b.key === 'home' ? 0 : 1)],
];

// 경기 일지를 월별·상대별·홈/원정별로 묶어 지표를 계산한다.
export function buildSplits(games, pitcher) {
    const summarize = pitcher ? summarizePitcher : summarizeBatter;
    const played = (games || []).filter(game => game.date);
    return {
        total: summarize(played),
        groups: groups.map(([id, title, keyOf, labelOf, order]) => {
            const buckets = new Map();
            for (const game of played) {
                const key = keyOf(game);
                if (!key) continue;
                if (!buckets.has(key)) buckets.set(key, []);
                buckets.get(key).push(game);
            }
            const rows = [...buckets].map(([key, list]) => ({ key, label: labelOf(key), games: list.length, list, ...summarize(list) }))
                .filter(row => row.played).sort(order);
            return { id, title, rows };
        }),
    };
}

// 서버가 이닝별로 합쳐 보낸 타석 기록(ab·h·tb·bb·hbp·sf)에서 타율과 OPS를 계산한다. 10은 연장.
export function buildInningRows(rows) {
    return (rows || []).map(row => {
        const ab = num(row.ab), h = num(row.h), bb = num(row.bb), hbp = num(row.hbp), sf = num(row.sf);
        const plate = ab + bb + hbp + sf;
        const obp = plate ? (h + bb + hbp) / plate : null;
        const slg = ab ? num(row.tb) / ab : null;
        const totals = { pa: num(row.pa), ab, h, doubles: num(row.doubles), triples: num(row.triples), hr: num(row.hr), rbi: num(row.rbi), bb, hbp, so: num(row.so) };
        return { key: String(row.inning), label: Number(row.inning) >= 10 ? '연장' : `${row.inning}회`, games: num(row.pa), unit: '타석', avg: ab ? h / ab : null, obp, slg, ops: obp !== null && slg !== null ? obp + slg : null, played: plate > 0, totals };
    }).filter(row => row.played);
}
