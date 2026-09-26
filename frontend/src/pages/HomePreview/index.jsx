import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import PlayerList from "../Kbocandle/PlayerList";
import { Spinner } from "flowbite-react";
import "./home-preview.css";
import { gameParticipants } from '../../lib/gameParticipants';
import GameWeather from './GameWeather';

const REFRESH_INTERVAL = 60_000;
const teamStyles = {
    SSG: ["ssg", "#ce0e2d"], 두산: ["doo", "#131d40"],
    삼성: ["sam", "#074ca1"], 롯데: ["lot", "#041e42"],
    LG: ["lg", "#c30452"], KT: ["kt", "#202020"],
    한화: ["han", "#f37321"], KIA: ["kia", "#ea0029"],
    NC: ["nc", "#315288"], 키움: ["kiw", "#820024"],
    고양: ["goy", "#820024"], 상무: ["sm", "#47643c"],
};
const teamLogos = import.meta.glob("../../assets/images/logos/*-logo.svg", { eager: true, query: "?url", import: "default" });
function getTeamStyle(name) {
    const [code, color] = teamStyles[name.toUpperCase()] || [null, "#60758c"];
    return { color, logo: teamLogos[`../../assets/images/logos/${code}-logo.svg`] };
}

function TeamLogo({ name, logo }) {
    return logo ? <img className="home-preview-team-logo" src={logo} alt="" />
        : <span className="home-preview-team-logo home-preview-team-fallback" aria-hidden="true">{name.slice(0, 1)}</span>;
}

function GameParticipant({ text, home = false }) {
    if (!text) return null;
    const separator = text.indexOf(' ');
    const role = text.slice(0, separator);
    const name = text.slice(separator + 1);
    return <span className={`home-preview-game-player${home ? ' is-home' : ''}`}>
        <span className={`home-preview-player-role ${role === '승' ? 'is-win' : role === '패' ? 'is-loss' : role === '선발' ? 'is-starter' : ''}`}>{role}</span>
        <span className="home-preview-player-divider" aria-hidden="true" />
        <span className="home-preview-player-name" title={name}>{name}</span>
    </span>;
}

function normalizeKboGame(game) {
    const inning = game.GAME_INN_NO;
    let status = inning ? `${inning}회${game.GAME_TB_SC_NM || ""}` : (game.G_TM || "");
    if (game.GAME_STATE_SC === "4") status = game.CANCEL_SC_NM || "경기 취소";
    return {
        ...game,
        away: game.AWAY_NM || "",
        home: game.HOME_NM || "",
        away_score: game.T_SCORE_CN || "",
        home_score: game.B_SCORE_CN || "",
        stadium: game.S_NM || "",
        status,
        isGameFinished: game.GAME_STATE_SC === "3",
    };
}

function SectionTitle({ title, action }) {
    return (
        <div className="home-preview-section-heading">
            <h2>{title}</h2>
            {action}
        </div>
    );
}

export default function HomePreview() {
    const [league, setLeague] = useState("kbo");
    const [rankingTab, setRankingTab] = useState(0);
    const [ranking, setRanking] = useState({ state: "loading", rows: [], title: "" });
    useEffect(() => {
        const controller = new AbortController();
        const loadRanking = async () => {
            try {
                const response = await fetch('/api/teamRank.php', { signal: controller.signal, cache: 'no-store' });
                const data = await response.json();
                if (!response.ok || data.code !== '100' || !Array.isArray(data.rows)) throw new Error('Invalid ranking');
                const rows = data.rows.map(({ row }) => row.map((cell) => {
                    const doc = new DOMParser().parseFromString(String(cell.Text ?? ''), 'text/html');
                    return doc.body.textContent.trim();
                }));
                if (!controller.signal.aborted) setRanking({ state: 'ready', rows, title: data.title || '' });
            } catch {
                if (!controller.signal.aborted) setRanking({ state: 'error', rows: [], title: '' });
            }
        };
        loadRanking();
        const timer = window.setInterval(loadRanking, 300000);
        return () => { controller.abort(); window.clearInterval(timer); };
    }, []);
    const [search, setSearch] = useState("");
    const searchDialog = useRef(null);
    const searchInput = useRef(null);
    const [searchOpen, setSearchOpen] = useState(false);
    const [players, setPlayers] = useState([]);
    const [playerSearchState, setPlayerSearchState] = useState("idle");
    const openSearch = () => {
        searchDialog.current.showModal();
        searchInput.current.focus();
        setSearchOpen(true);
    };
    useEffect(() => {
        const keyword = search.trim();
        setPlayers([]);
        if (!searchOpen || (keyword.length < 2 && keyword !== "홀")) {
            setPlayerSearchState("idle");
            return;
        }
        const controller = new AbortController();
        setPlayerSearchState("loading");
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch("/api/kbocandle/get_player_list.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ name: keyword }),
                    signal: controller.signal,
                });
                const result = await response.json();
                if (!response.ok || result.success === false) throw new Error("검색 실패");
                let list = result.list || result;
                if (!Array.isArray(list)) throw new Error("잘못된 검색 응답");
                // 기존 운영 API를 프록시하는 개발 환경에서도 등번호를 제공한다.
                if (list.length && list.every((player) => !("BackNo" in player))) {
                    try {
                        const profileResponse = await fetch("/api/kbodle/get_player_list.php", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ keyword }),
                            signal: controller.signal,
                        });
                        if (profileResponse.ok) {
                            const profiles = await profileResponse.json();
                            const numbers = new Map((profiles.list || []).map((player) => [String(player.SporkId), player.BackNo]));
                            list = list.map((player) => ({ ...player, BackNo: numbers.get(String(player.PlayerId)) }));
                        }
                    } catch { /* 등번호 조회 실패 시 기본 검색 결과는 표시한다. */ }
                }
                if (controller.signal.aborted) return;
                setPlayers(list);
                setPlayerSearchState("ready");
            } catch (error) {
                if (!controller.signal.aborted) setPlayerSearchState("error");
            }
        }, 500);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [search, searchOpen]);
    useEffect(() => {
        if (!searchOpen) return;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        return () => { document.body.style.overflow = previousOverflow; };
    }, [searchOpen]);
    const [gameLists, setGameLists] = useState({ kbo: [], futures: [] });
    const [gameState, setGameState] = useState("loading");

    useEffect(() => {
        const controller = new AbortController();
        const loadGames = async () => {
            try {
                const response = await fetch("/api/todayGames.php", { signal: controller.signal, cache: "no-store" });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.error || "경기 정보를 불러오지 못했습니다.");

                let kboGames = result.kboGames;
                let futuresGames = result.futuresGames;
                // 배포 전 운영 API에서도 미리보기가 가능하도록 기존 일정 API를 폴백으로 사용한다.
                if (!Array.isArray(kboGames) || !Array.isArray(futuresGames)) {
                    const scheduleResponse = await fetch("/api/gameday/get_game_list.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            date: new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Seoul" }).format(new Date()),
                        }),
                        signal: controller.signal,
                        cache: "no-store",
                    });
                    const schedule = await scheduleResponse.json();
                    if (!scheduleResponse.ok || !schedule.success) throw new Error("경기 일정 정보를 불러오지 못했습니다.");
                    kboGames = (schedule.kbo?.game || []).map(normalizeKboGame);
                    futuresGames = (schedule.futures?.game || []).map(normalizeKboGame);
                }

                setGameLists({ kbo: kboGames, futures: futuresGames });
                setGameState("ready");
            } catch (error) {
                if (error.name !== "AbortError") {
                    console.error("오늘의 경기 조회 오류:", error);
                    setGameState("error");
                }
            }
        };
        loadGames();
        const refreshTimer = window.setInterval(loadGames, REFRESH_INTERVAL);
        return () => {
            controller.abort();
            window.clearInterval(refreshTimer);
        };
    }, []);

    const games = gameLists[league];
    const isActiveKboPlayer = (player) => /^(SSG|두산|삼성|롯데|LG|KT|한화|KIA|NC|키움|고양|상무)(?:\s|$)/i.test((player.Team || "").trim());
    const playerGroups = [
        { id: "active", title: "현역 선수", list: players.filter(isActiveKboPlayer) },
        { id: "other", title: "은퇴 · 타 리그", list: players.filter((player) => !isActiveKboPlayer(player)) },
    ];

    return (
        <main className="home-preview font-family-NaSqNe">
            <dialog ref={searchDialog} className="home-preview-search-screen" aria-label="선수 검색" onClose={() => setSearchOpen(false)}>
                <div className="home-preview-search-screen-inner">
                    <header className="home-preview-search-header">
                        <button type="button" className="home-preview-search-back" aria-label="검색 닫기" onClick={() => searchDialog.current.close()}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="m14 5-7 7 7 7M7 12h14" /></svg>
                        </button>
                        <form className="home-preview-search" role="search" onSubmit={(event) => event.preventDefault()}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8" /><path d="m16 16 5 5" /></svg>
                            <input ref={searchInput} type="search" aria-label="선수 이름" placeholder="선수 이름을 검색해 보세요" value={search} onChange={(event) => setSearch(event.target.value)} autoComplete="off" enterKeyHint="search" />
                            {search && <button type="button" className="home-preview-search-clear" aria-label="검색어 지우기" onClick={() => setSearch("")}>×</button>}
                        </form>
                    </header>
                    <div className="home-preview-player-results" aria-busy={playerSearchState === "loading"}>
                        <div role="status">
                            {playerSearchState === "loading" && <div className="home-preview-player-loading"><Spinner size="lg" className="fill-blue-600" aria-label="선수 검색 중" /></div>}
                            {playerSearchState === "error" && <p>검색 정보를 불러오지 못했습니다. 잠시 후 다시 입력해 주세요.</p>}
                            {playerSearchState === "ready" && players.length === 0 && <div className="home-preview-player-empty">
                                <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="2.5" aria-hidden="true"><circle cx="24" cy="24" r="19" /><path d="M24 13v14" strokeLinecap="round" /><circle cx="24" cy="34" r="1.5" fill="currentColor" stroke="none" /></svg>
                                <p>검색 결과가 없습니다.</p>
                            </div>}
                        </div>
                        {playerSearchState === "ready" && players.length > 0 && playerGroups.map((group) => (
                            <section className="home-preview-player-group" key={group.id} aria-labelledby={`player-group-${group.id}`}>
                                <h2 id={`player-group-${group.id}`}>{group.title} ({group.list.length})</h2>
                                <ul aria-label={group.title}>
                                    {group.list.map((player) => <li key={player.PlayerId}><PlayerList player={player} showBackNo highlight={search} /></li>)}
                                </ul>
                            </section>
                        ))}
                    </div>
                </div>
            </dialog>
            <div className="home-preview-inner">
                <section className="home-preview-search-hero" aria-labelledby="preview-search-title">
                    <div className="home-preview-hero-banner">
                        <div className="home-preview-hero-title">
                            <img className="home-preview-hero-favicon" src="/wesiper-favicon.png" alt="" />
                            <h1 id="preview-search-title"><span>KBO</span> 선수 도감</h1>
                        </div>
                    </div>
                    <button type="button" className="home-preview-search" onClick={openSearch} aria-haspopup="dialog" aria-label="선수 검색 화면 열기">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8" /><path d="m16 16 5 5" /></svg>
                        <span>{search || "선수 이름을 검색해 보세요"}</span>
                    </button>
                </section>
                <section className="home-preview-section home-preview-match-section" aria-labelledby="preview-games-title">
                    <SectionTitle title={<span id="preview-games-title">오늘의 KBO</span>} action={
                        <div className={`home-preview-league-switch ${league === "futures" ? "is-futures" : ""}`} role="group" aria-label="리그 선택">
                            <button type="button" className={league === "kbo" ? "active" : ""} aria-pressed={league === "kbo"} onClick={() => setLeague("kbo")}>KBO 1군</button>
                            <button type="button" className={league === "futures" ? "active" : ""} aria-pressed={league === "futures"} onClick={() => setLeague("futures")}>퓨처스리그</button>
                        </div>
                    } />
                    <div className="home-preview-game-grid">
                        {gameState === "loading" && <p className="home-preview-game-message">오늘의 경기 정보를 불러오는 중입니다.</p>}
                        {gameState === "error" && <p className="home-preview-game-message home-preview-game-error">경기 정보를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</p>}
                        {gameState === "ready" && games.length === 0 && <p className="home-preview-game-message">오늘 예정된 경기가 없습니다.</p>}
                        {gameState === "ready" && games.map((game, index) => {
                            const away = getTeamStyle(game.away);
                            const home = getTeamStyle(game.home);
                            const participants = gameParticipants(game);
                            const scheduled = /^\d{1,2}:\d{2}$/.test(game.status);
                            const cancelled = /취소/.test(game.status);
                            const hasScore = !scheduled && !cancelled && (game.away_score !== "" || game.home_score !== "");
                            return (
                                <article className="home-preview-game-card" key={`${game.away}-${game.home}-${index}`} style={{ "--away-color": away.color, "--home-color": home.color }}>
                                    <TeamLogo name={game.away} logo={away.logo} />
                                    <div className="home-preview-team-info"><strong className="home-preview-team-name">{game.away}</strong><GameParticipant text={participants.awayPlayer} /></div>
                                    <div className="home-preview-game-center">
                                        {hasScore ? <strong className="home-preview-score"><b className={Number(game.away_score) > Number(game.home_score) ? "is-leading" : ""}>{game.away_score || 0}</b><span>:</span><b className={Number(game.home_score) > Number(game.away_score) ? "is-leading" : ""}>{game.home_score || 0}</b></strong> : <strong className="home-preview-game-time">{game.status || "경기 예정"}</strong>}
                                        <span className="home-preview-game-status">{hasScore ? `${game.isGameFinished ? "경기 종료" : game.status} · ` : ""}{game.stadium}{scheduled && !hasScore && !cancelled && <GameWeather weather={game.weather} />}</span>
                                    </div>
                                    <div className="home-preview-team-info home-preview-team-home"><strong className="home-preview-team-name">{game.home}</strong><GameParticipant text={participants.homePlayer} home /></div>
                                    <TeamLogo name={game.home} logo={home.logo} />
                                </article>
                            );
                        })}
                    </div>
                </section>

                <section className="home-preview-section home-preview-records-section" aria-labelledby="preview-records-title">
                    <SectionTitle title={<span id="preview-records-title">KBO 순위</span>} action={
                        <div className="home-preview-league-switch home-preview-ranking-switch" style={{ '--tab-index': rankingTab }} role="group" aria-label="순위 선택">
                            {['팀 순위', '가을야구'].map((label, index) => <button key={label} type="button" className={rankingTab === index ? 'active' : ''} aria-pressed={rankingTab === index} onClick={() => setRankingTab(index)}>{label}</button>)}
                        </div>
                    } />
                    <>
                        {ranking.state === 'loading' && <div className="home-preview-player-loading"><Spinner aria-label="팀 순위 불러오는 중" /></div>}
                        {ranking.state === 'error' && <p role="status">팀 순위를 불러오지 못했습니다.</p>}
                        {ranking.state === 'ready' && (ranking.rows.length ? <div className="home-preview-ranking-table-wrap"><table className={`home-preview-ranking-table ${rankingTab === 1 ? 'is-autumn' : ''}`}>
                            <caption className="sr-only">KBO {rankingTab === 0 ? '팀 순위' : '가을야구'} {ranking.title}</caption>
                            <colgroup><col style={{ width: 36 }} /><col style={{ width: 68 }} />{(rankingTab === 0 ? [null, null, null, null, null, 46, 44, 117] : [null, null, null]).map((width, index) => <col key={index} style={width ? { width } : undefined} />)}</colgroup>
                            <thead><tr>{(rankingTab === 0 ? ['순위', '팀', '경기', '승', '패', '무', '승률', '게임차', '연속', '최근 5경기'] : ['순위', '팀', '남은\n경기', '1위\n매직넘버', '가을야구\n매직 · 트래직 넘버']).map((label) => <th scope="col" key={label}>{label}</th>)}</tr></thead>
                            <tbody>{ranking.rows.map((row) => <tr key={row[1]}>
                                <td>{row[0]}</td><th scope="row"><div><TeamLogo name={row[1]} logo={getTeamStyle(row[1]).logo} />{row[1]}</div></th>
                                {rankingTab === 0 ? row.slice(2, 10).map((value, index) => <td key={index}>{index === 7 ? <span className="home-preview-recent-results" aria-label={`최근 5경기 ${value}`}>
                                    {[...value].map((result, i) => <span key={i} className={result === '승' ? 'is-win' : result === '패' ? 'is-loss' : 'is-draw'}>{result}</span>)}
                                </span> : value}</td>) : <>
                                    <td>{row[10]}</td>
                                    {row[14] === '가을야구 불가' ? <td colSpan={2}><span className="home-preview-rank-remark is-out">가을야구 탈락</span></td> : <>
                                        <td>{row[11] === 'X' && row[14]?.endsWith('위 불가') ? <span className="home-preview-rank-remark is-unreachable">{row[14]}</span> : row[11] === 'X' ? '-' : `${row[11]}승`}</td>
                                        <td>{row[14]?.endsWith('확보') ? <span className="home-preview-rank-remark is-secured">{row[14]}</span>
                                            : [row[12] !== 'X' ? `${row[12]}승` : null, row[13] !== 'X' ? `${row[13]}패` : null].filter(Boolean).join(' · ') || '-'}</td>
                                    </>}
                                </>}
                            </tr>)}</tbody>
                        </table></div> : <p>표시할 팀 순위가 없습니다.</p>)}
                    </>
                    <p className="home-preview-ranking-caption">매일 오전 2시에 업데이트됩니다.</p>
                </section>

                <section className="home-preview-section home-preview-games-section" aria-labelledby="preview-mini-title">
                    <SectionTitle title={<span id="preview-mini-title">미니게임</span>} />
                    <div className="home-preview-mini-grid">
                        <Link className="home-preview-mini-card home-preview-kbodle-card" to="/kbodle">
                            <div className="home-preview-mini-art" aria-hidden="true">
                                <div className="home-preview-kbodle-tiles">{Array.from({ length: 7 }, (_, i) => <span key={i} className={i < 2 ? 'is-match' : i === 4 || i === 5 ? 'is-close' : ''}>?</span>)}</div>
                            </div>
                            <strong className="font-family-kbo">KBODLE</strong><small>단서를 조합해 오늘의 선수를 맞혀보세요</small><span className="home-preview-mini-go" aria-hidden="true">↗</span>
                        </Link>
                        <Link className="home-preview-mini-card home-preview-bingo-card" to="/bingo">
                            <div className="home-preview-mini-art" aria-hidden="true">
                                <div className="home-preview-bingo-board">{Array.from({ length: 9 }, (_, i) => <span key={i} className={i % 4 === 0 ? 'is-filled' : ''}>{i % 4 === 0 ? '✓' : ''}</span>)}</div>
                            </div>
                            <strong className="font-family-kbo">KBO BINGO</strong><small>야구 지식으로 아홉 칸을 채워보세요</small><span className="home-preview-mini-go" aria-hidden="true">↗</span>
                        </Link>
                    </div>
                </section>
            </div>
        </main>
    );
}
