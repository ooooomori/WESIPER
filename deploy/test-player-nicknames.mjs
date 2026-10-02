import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

// Input is the JSON emitted by import-player-nicknames.php --check.
const resolved = JSON.parse((await readFile(process.argv[2] || '.player-career/nickname-resolved.json', 'utf8')).replace(/^\uFEFF/, ''));
const base = process.argv[3] || 'http://localhost:5173';
const checkSpacing = process.argv.includes('--spacing');
const skip = Number(process.argv.find(arg => arg.startsWith('--skip='))?.slice(7) || 0);
const aliases = new Map();
const players = new Map();
for (const row of resolved.rows) {
    if (!aliases.has(row.nickname)) aliases.set(row.nickname, []);
    aliases.get(row.nickname).push(row.player_id);
    if (!players.has(row.player_id)) players.set(row.player_id, []);
    players.get(row.player_id).push(row.nickname);
}
async function getJson(path, options) {
    const response = await fetch(`${base}${path}`, { ...options, signal: AbortSignal.timeout(60000) });
    assert.equal(response.status, 200, `${path}: HTTP ${response.status}`);
    return response.json();
}
let checked = 0;
for (const [nickname, expectedIds] of aliases) {
    const compact = nickname.replace(/\s+/gu, '');
    const variants = checkSpacing ? [...new Set([nickname, compact, [...compact].join(' ')])] : [nickname];
    for (const query of variants) {
        if (checked < skip) { checked++; continue; }
        const result = await getJson('/api/kbocandle/get_player_list.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: query }),
        });
        assert.ok(Array.isArray(result), `Invalid search response for ${nickname}`);
        const ids = result.map(player => Number(player.PlayerId));
        for (const id of expectedIds) assert.ok(ids.includes(id), `Missing ${query} for ${id}`);
        assert.equal(ids.length, new Set(ids).size, `Duplicate player result for ${nickname}`);
        if (++checked % 50 === 0) console.log(`Checked ${checked} nickname queries`);
    }
}
for (const [id, expected] of players) {
    const result = await getJson(`/api/playerProfile.php?pid=${id}&part=profile`);
    assert.equal(Number(result.player.PlayerId), id);
    assert.ok(Array.isArray(result.player.Nicknames), `Invalid nickname list for ${id}`);
    assert.deepEqual(result.player.Nicknames.filter(nickname => expected.includes(nickname)), expected, `Nickname list/order mismatch for ${id}`);
}
console.log(`PASS: ${resolved.rows.length} nickname mappings (${checked} queries), ${players.size} profile nickname lists, no duplicate search results`);
