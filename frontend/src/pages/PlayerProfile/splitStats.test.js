import test from 'node:test';
import assert from 'node:assert/strict';
import { buildSplits } from './splitStats.js';

test('batter splits compute avg and OPS per month, opponent and venue', () => {
    const games = [
        { date: '2026-04-02', opponent: 'LG', isAway: false, ab: 4, h: 2, doubles: 1, hr: 0, bb: 1 },
        { date: '2026-04-03', opponent: 'LG', isAway: true, ab: 4, h: 0, bb: 0 },
        { date: '2026-03-28', opponent: 'LG', isAway: true, ab: 0, h: 0, bb: 0 },
        { date: '2026-05-01', opponent: '두산', isAway: true, ab: 3, h: 1, hr: 1, sf: 1 },
    ];
    const { total, groups } = buildSplits(games, false);
    assert.equal(total.avg, 3 / 11);
    const [month, opponent, venue] = groups;
    assert.deepEqual(month.rows.map(row => row.label), ['3~4월', '5월']);
    assert.equal(month.rows[0].avg, 2 / 8);
    // OBP (2+1)/(8+1) , SLG (2+1)/8
    assert.ok(Math.abs(month.rows[0].ops - (3 / 9 + 3 / 8)) < 1e-9);
    assert.deepEqual(opponent.rows.map(row => row.label), ['LG', '두산']);
    assert.deepEqual(venue.rows.map(row => row.label), ['홈', '원정']);
});

test('pitcher splits compute ERA and WHIP from thirds of an inning', () => {
    const games = [
        { date: '2026-04-02', opponent: 'KIA', isAway: false, innings: '6.1', er: 2, h: 5, bb: 1 },
        { date: '2026-04-09', opponent: 'KIA', isAway: true, innings: '5', er: 0, h: 3, bb: 2 },
    ];
    const { total } = buildSplits(games, true);
    assert.ok(Math.abs(total.era - 2 * 27 / 34) < 1e-9);
    assert.ok(Math.abs(total.whip - 11 * 3 / 34) < 1e-9);
});
