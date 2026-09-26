import test from 'node:test';
import assert from 'node:assert/strict';
import { expiry, createGamesCache } from './todayGamesCache.mjs';
const start = Date.parse('2026-09-27T16:50:00+09:00');
test('TTL and kickoff boundaries', () => {
    for (const [games, seconds] of [[[],1800],[[{GAME_STATE_SC:'1',G_TM:'18:00'}],1800],[[{GAME_STATE_SC:'1',G_TM:'17:00'}],600],[[{GAME_STATE_SC:'2'}],30],[[{GAME_STATE_SC:'3'}],1800],[[{GAME_STATE_SC:'1',G_TM:'16:30'}],30]]) {
        assert.equal(expiry(games,start),start+seconds*1000);
    }
    const late = Date.parse('2026-09-27T23:55:00+09:00');
    assert.equal(expiry([],late),late+300000);
});
test('deduplication, league isolation, stale fallback and rollover', async () => {
    let now = start, calls = 0, failing = false;
    const load = createGamesCache(async () => { calls++; if(failing) throw new Error('offline'); return []; }, () => now);
    await Promise.all([load('1','0'),load('1','0')]);
    assert.equal(calls,1);
    await load('2','0'); assert.equal(calls,2);
    now += 1800000; failing = true;
    assert.deepEqual(await load('1','0'),[]); assert.equal(calls,3);
    await load('1','0'); assert.equal(calls,3);
    now += 86400000;
    await assert.rejects(load('1','0')); assert.equal(calls,4);
    await assert.rejects(load('1','0')); assert.equal(calls,4);
});
