import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";
import { createGamesCache } from './dev/todayGamesCache.mjs';

function localTodayGamesApi(todayGamesTarget) {
    const fetchLeague = createGamesCache(async (leagueId, seriesIds, day) => {
        const body = new URLSearchParams({
            leId: leagueId,
            srId: seriesIds,
            date: day,
        });
        const response = await fetch("https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                Origin: "https://www.koreabaseball.com",
                Referer: "https://www.koreabaseball.com/",
                "X-Requested-With": "XMLHttpRequest",
            },
            body,
            signal: AbortSignal.timeout(15000),
        });
        if (!response.ok) throw new Error(`KBO API HTTP ${response.status}`);
        return (await response.json()).game;
    });

    const normalize = (game) => ({
        ...game,
        away: game.AWAY_NM || "",
        home: game.HOME_NM || "",
        away_score: game.T_SCORE_CN || "",
        home_score: game.B_SCORE_CN || "",
        stadium: game.S_NM || "",
        status: game.GAME_STATE_SC === "4"
            ? (game.CANCEL_SC_NM || "경기 취소")
            : (game.GAME_INN_NO ? `${game.GAME_INN_NO}회${game.GAME_TB_SC_NM || ""}` : (game.G_TM || "")),
        isGameFinished: game.GAME_STATE_SC === "3",
    });

    return {
        name: "local-today-games-api",
        configureServer(server) {
            server.middlewares.use('/api/teamRank.php', async (_request, response) => {
                response.setHeader('Content-Type', 'application/json; charset=UTF-8');
                response.setHeader('Cache-Control', 'no-store');
                try {
                    const upstream = await fetch('https://wesiper.xyz/api/teamRank.php', {
                        signal: AbortSignal.timeout(15000), cache: 'no-store',
                    });
                    const data = await upstream.json();
                    if (!upstream.ok || data.code !== '100' || !Array.isArray(data.rows)) throw new Error('Invalid KBO response');
                    response.end(JSON.stringify(data));
                } catch {
                    response.statusCode = 502;
                    response.end(JSON.stringify({ success: false, error: '팀 순위를 불러오지 못했습니다.' }));
                }
            });
            server.middlewares.use("/api/todayGames.php", async (_request, response, next) => {
                // Weather and its secret belong to PHP, never to the Vite process/browser.
                if (todayGamesTarget || process.env.VITE_API_PROXY_TARGET) return next();
                try {
                    const [kbo, futures] = await Promise.all([
                        fetchLeague("1", "0,1,3,4,5,7,9"),
                        fetchLeague("2", "0,1,9,10,15"),
                    ]);
                    const ssg = kbo.find((game) => game.AWAY_ID === "SK" || game.HOME_ID === "SK");
                    response.setHeader("Content-Type", "application/json; charset=UTF-8");
                    response.end(JSON.stringify({
                        success: true,
                        isGameExist: Boolean(ssg),
                        game: ssg ? normalize(ssg) : undefined,
                        kboGames: kbo.map(normalize),
                        futuresGames: futures.map(normalize),
                    }));
                } catch (error) {
                    response.statusCode = 502;
                    response.setHeader("Content-Type", "application/json; charset=UTF-8");
                    response.end(JSON.stringify({ success: false, error: error.message }));
                }
            });
        },
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), 'WESIPER_');
    const todayGamesTarget = process.env.WESIPER_TODAY_GAMES_PROXY_TARGET || env.WESIPER_TODAY_GAMES_PROXY_TARGET;
    return {
    plugins: [react(), localTodayGamesApi(todayGamesTarget)],

    server: {
        host: "0.0.0.0",
        port: 5173,
        strictPort: true,
        proxy: {
            ...(todayGamesTarget ? { '/api/todayGames.php': { target: todayGamesTarget, changeOrigin: true } } : {}),
            "/api": {
                target:
                    process.env.VITE_API_PROXY_TARGET ||
                    "https://wesiper.xyz",
                changeOrigin: true,
            },
        },
    },
};
});
