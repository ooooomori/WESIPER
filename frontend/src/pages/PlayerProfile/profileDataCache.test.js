import test from 'node:test';
import assert from 'node:assert/strict';
import { getCachedProfileData, loadProfileData } from './profileDataCache.js';

const response = data => ({ ok: true, json: async () => data });

test('tab revisits share an ongoing request and the successful response', async t => {
    let calls = 0;
    let finish;
    t.mock.method(globalThis, 'fetch', () => {
        calls++;
        return new Promise(resolve => { finish = resolve; });
    });
    const first = loadProfileData('/api/playerProfile.php?pid=cache-test&part=games');
    const revisit = loadProfileData('/api/playerProfile.php?part=games&pid=cache-test');
    assert.equal(first, revisit);
    const data = { years: [2026], games: [{ gameId: 'first' }] };
    finish(response(data));
    assert.equal(await first, data);
    assert.equal(await loadProfileData('/api/playerProfile.php?pid=cache-test&part=games'), data);
    assert.equal(getCachedProfileData('/api/playerProfile.php?part=games&pid=cache-test'), data);
    assert.equal(calls, 1);
});

test('different players, years and seasons use separate responses', async t => {
    let calls = 0;
    t.mock.method(globalThis, 'fetch', async url => { calls++; return response({ url }); });
    const urls = [
        '/api/kbocandle/get_data.php?player_id=first&year=2026&season=regular',
        '/api/kbocandle/get_data.php?player_id=second&year=2026&season=regular',
        '/api/kbocandle/get_data.php?player_id=first&year=2025&season=regular',
        '/api/kbocandle/get_data.php?player_id=first&year=2026&season=futures',
    ];
    for (const url of [...urls, ...urls]) assert.equal((await loadProfileData(url)).url, url);
    assert.equal(calls, 4);
});

test('failed or invalid responses can be retried', async t => {
    let calls = 0;
    t.mock.method(globalThis, 'fetch', async () => { calls++; return response(calls === 1 ? { error: 'failed' } : { games: [] }); });
    const url = '/api/playerProfile.php?pid=retry-test&part=games';
    const valid = data => Array.isArray(data.games);
    await assert.rejects(loadProfileData(url, valid));
    assert.equal(getCachedProfileData(url), undefined);
    assert.deepEqual(await loadProfileData(url, valid), { games: [] });
    assert.equal(calls, 2);
});
