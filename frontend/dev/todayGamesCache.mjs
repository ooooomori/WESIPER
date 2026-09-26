export const seoulDay = (now) => new Date(now + 9 * 3600000).toISOString().slice(0, 10);
export function expiry(games, now) {
    const day = seoulDay(now);
    let expires = Math.min(now + 1800000, Date.parse(`${day}T00:00:00+09:00`) + 86400000);
    for (const game of games) {
        const state = String(game.GAME_STATE_SC ?? '');
        if (['3', '4'].includes(state)) continue;
        const start = state === '1' && /^([01]\d|2[0-3]):[0-5]\d$/.test(game.G_TM ?? '')
            ? Date.parse(`${day}T${game.G_TM}:00+09:00`) : NaN;
        expires = Math.min(expires, start > now ? start : now + 30000);
    }
    return expires;
}

export function createGamesCache(load, clock = Date.now) {
    const cache = new Map();
    const pending = new Map();
    return async (league, series) => {
        const now = clock();
        const day = seoulDay(now);
        const key = `${league}:${series}`;
        const entry = cache.get(key);
        const sameDay = entry?.day === day;
        const usable = sameDay && Array.isArray(entry.games) && now - entry.fetchedAt <= 21600000;
        if (usable && entry.expiresAt > now) return entry.games;
        if (sameDay && entry.retryAt > now) {
            if (usable) return entry.games;
            throw new Error('KBO retry temporarily delayed');
        }
        const pendingKey = `${key}:${day}`;
        if (pending.has(pendingKey)) return pending.get(pendingKey);
        const request = (async () => {
            try {
                const games = await load(league, series, day.replaceAll('-', ''));
                if (!Array.isArray(games) || games.some((game) => game?.GAME_STATE_SC == null)) throw new Error('Invalid game list');
                cache.set(key, { day, games, fetchedAt: now, expiresAt: expiry(games, now) });
                return games;
            } catch (error) {
                cache.set(key, { ...(usable ? entry : { day, games: null }), retryAt: now + 30000 });
                if (usable) return entry.games;
                throw error;
            }
        })();
        pending.set(pendingKey, request);
        try { return await request; } finally { pending.delete(pendingKey); }
    };
}
