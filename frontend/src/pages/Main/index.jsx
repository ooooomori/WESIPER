import { useEffect, useRef, useState } from "react";
import { addRecentPlayer, readRecentPlayers, removeRecentPlayer } from '../../lib/recentPlayers';
import { Link, useLocation, useNavigate } from "react-router-dom";
import { Spinner } from "flowbite-react";
import "./main.css";
import PlayerSilhouette from '../../components/PlayerSilhouette';
import { teamCapByName } from '../../lib/teamAssets';
import { gameParticipants } from '../../lib/gameParticipants';
import GameWeather from './GameWeather';
import ulsanLogo from '../../assets/images/logos/ulsan-logo.png';

// 오늘의 경기는 일정·결과만 보여주고 서버도 KBO에 하루 몇 번만 묻는다. 화면은 5분마다 확인하면 충분하다.
const REFRESH_INTERVAL = 300_000;
const teamStyles = {
    SSG: ["ssg", "#ce0e2d"], 두산: ["doo", "#131d40"],
    삼성: ["sam", "#074ca1"], 롯데: ["lot", "#041e42"],
    LG: ["lg", "#c30452"], KT: ["kt", "#202020"],
    한화: ["han", "#f37321"], KIA: ["kia", "#ea0029"],
    NC: ["nc", "#315288"], 키움: ["kiw", "#820024"],
    고양: ["goy", "#820024"], 상무: ["sm", "#47643c"],
    울산: ["ulsan", "#c90000"], '울산 웨일즈': ["ulsan", "#c90000"],
};
const teamLogos = import.meta.glob("../../assets/images/logos/*-logo.svg", { eager: true, query: "?url", import: "default" });
const smallTeamLogos = import.meta.glob("../../assets/images/s-logos/*-small-logo.{svg,png}", { eager: true, query: "?url", import: "default" });
function getSmallTeamLogo(name) {
    const [code] = teamStyles[name.toUpperCase()] || [null];
    if (!code) return null;
    const base = `../../assets/images/s-logos/${code}-small-logo`;
    // 작은 로고가 없는 팀(고양·상무 등)은 기본 로고를 쓴다.
    return smallTeamLogos[`${base}.svg`] || smallTeamLogos[`${base}.png`] || getTeamStyle(name).logo;
}
function getTeamStyle(name) {
    const [code, color] = teamStyles[name.toUpperCase()] || [null, "#60758c"];
    return { color, logo: code === 'ulsan' ? ulsanLogo : teamLogos[`../../assets/images/logos/${code}-logo.svg`] };
}

function TeamLogo({ name, logo }) {
    return logo ? <img className="main-home-team-logo" src={logo} alt="" />
        : <span className="main-home-team-logo main-home-team-fallback" aria-hidden="true">{name.slice(0, 1)}</span>;
}

function GameParticipant({ text, home = false }) {
    if (!text) return null;
    const separator = text.indexOf(' ');
    const role = text.slice(0, separator);
    const name = text.slice(separator + 1);
    return <span className={`main-home-game-player${home ? ' is-home' : ''}`}>
        <span className={`main-home-player-role ${role === '승' ? 'is-win' : role === '패' ? 'is-loss' : role === '선발' ? 'is-starter' : ''}`}>{role}</span>
        <span className="main-home-player-divider" aria-hidden="true" />
        <span className="main-home-player-name" title={name}>{name}</span>
    </span>;
}

function SectionTitle({ title, action }) {
    return (
        <div className="main-home-section-heading">
            <h2>{title}</h2>
            {action}
        </div>
    );
}
function SearchPlayerPhoto({ player }) {
    const [photoIndex, setPhotoIndex] = useState(0);
    useEffect(() => setPhotoIndex(0), [player.PlayerId]);
    const missing = photoIndex >= 2;
    // 사진이 없는 선수: 팀 색이 옅게 도는 바탕에 모자를 쓴 선수 실루엣
    // 모자는 소속팀 색, 은퇴 선수는 마지막 소속팀 색으로 칠한다(로고는 작아서 넣지 않는다).
    if (missing) {
        const capTeam = String((player.IsActive !== false && player.Team !== '은퇴' ? player.Team : player.NumberRetiredTeam || player.FormerTeam) || '').trim();
        return <PlayerSilhouette className="main-home-search-photo-placeholder" cap={teamCapByName(capTeam, player.Retire || player.LastRecordYear)} />;
    }
    return <img src={`/assets/images/player/kbo/${encodeURIComponent(player.PlayerId)}.${photoIndex === 0 ? 'jpg' : 'png'}`} alt="" onError={() => setPhotoIndex(index => index + 1)} />;
}
// 조회수 집계가 부족할 때 쓰는 기본 추천 목록
const POPULAR_PLAYER_NAMES = ['김도영', '안현민', '문동주', '노시환', '구자욱', '원태인', '양의지', '김광현', '최정', '김택연'];
// 조회수 집계가 이 인원보다 적으면(서비스 초기 등) 아래 기본 추천 목록을 보여준다.
const POPULAR_MIN_RANKED = 5;
function getPlayerTeamInfo(player) {
    const active = player.IsActive !== false && player.Team !== '은퇴';
    const numberRetired = Number(player.IsNumberRetired) === 1;
    const badgeTeam = numberRetired ? player.NumberRetiredTeam || player.FormerTeam : player.Team;
    const teamLabel = numberRetired ? badgeTeam : active ? player.Team : player.FormerTeam ? `前 ${player.FormerTeam}` : null;
    const style = active || numberRetired ? getTeamStyle(badgeTeam || '') : { color: '#778391', logo: null };
    return { active, numberRetired, teamLabel, color: style.color, logo: active || numberRetired ? getSmallTeamLogo(badgeTeam || '') : null, fullLogo: active || numberRetired ? style.logo || null : null };
}
function getPlayerPosition(player) {
    const rawPosition = player.MainPos?.trim() || player.Pos || '';
    return player.MainPos?.trim() && player.Pos?.includes('투수') && !rawPosition.endsWith('투수') ? `${rawPosition}투수` : rawPosition;
}
function SearchPlayerRow({ player, keyword = '', rank }) {
    const { active, numberRetired, teamLabel, color, logo, fullLogo } = getPlayerTeamInfo(player);
    const position = getPlayerPosition(player);
    const draftYear = player.Draft?.trim().match(/^(\d{4}|\d{2})/);
    const shortYear = draftYear ? Number(draftYear[1]) : null;
    const currentYear = Number(new Intl.DateTimeFormat('en', { timeZone: 'Asia/Seoul', year: 'numeric' }).format(new Date()));
    const draftDebutYear = shortYear == null ? null : draftYear[1].length === 4 ? shortYear : shortYear <= currentYear % 100 ? 2000 + shortYear : 1900 + shortYear;
    // 데뷔 연도: 실제 첫 출전 기록(경기 기록·연도별 통산 기록)이 있으면 우선, 없으면 드래프트 연도
    const debutYear = Number(player.FirstRecordYear) || draftDebutYear;
    const retirementYear = player.Retire || player.LastRecordYear;
    const careerYears = !active && (debutYear || retirementYear) ? `${debutYear || '?'}–${retirementYear || '?'}` : null;
    // 현역과 영구결번 선수는 등번호를 보여준다.
    const backNo = (active || numberRetired) && player.BackNo != null && String(player.BackNo).trim() ? `#${player.BackNo}` : null;
    const query = keyword.trim().toLocaleLowerCase();
    const name = player.Name || '';
    const fragments = [];
    let start = 0, match;
    while (query && (match = name.toLocaleLowerCase().indexOf(query,start)) !== -1) {
        fragments.push(name.slice(start,match),<mark key={match}>{name.slice(match,match+query.length)}</mark>);start=match+query.length;
    }
    fragments.push(name.slice(start));
    // 팀 색 배경은 현역·영구결번 모두, 오른쪽의 큰 팀 로고는 영구결번 선수만
    return <div className={`main-home-search-player${active || numberRetired ? '' : ' is-retired'}${fullLogo ? ' has-team-logo' : ''}`} style={{'--search-team-color':color, ...(fullLogo && numberRetired ? { '--search-team-logo': `url("${fullLogo}")` } : {})}}>
        {rank != null && <span className={`main-home-search-rank${rank <= 3 ? ' is-top' : ''}`}>{rank}</span>}
        <div className="main-home-search-photo"><SearchPlayerPhoto player={player} />{logo && <img className="main-home-search-photo-logo" src={logo} alt="" />}</div>
        <div className="main-home-search-identity">
            <div className="main-home-search-name"><strong>{fragments}</strong>{backNo && <span className="main-home-search-backno">{backNo}</span>}</div>
            <div className="main-home-search-position">{teamLabel && <span className="main-home-search-team">{teamLabel}</span>}{position && <span className="main-home-search-role">{position}</span>}{careerYears && <span className="main-home-search-years">{careerYears}</span>}</div>
        </div>
        <svg className="main-home-search-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>
    </div>;
}
function pickPopularPlayer(list, name) {
    const candidates = (Array.isArray(list) ? list : []).filter((player) => player.Name === name);
    const score = (player) => (player.IsActive !== false && player.Team !== '은퇴' ? 1e6 : 0) + Number(player.FirstTeamGames || 0);
    return candidates.sort((a, b) => score(b) - score(a))[0] || null;
}

// 다음 출전 경기에서 안타·홈런을 칠 확률 순위. 예측이 없거나 오래됐으면(시즌 종료 등) 영역을 숨긴다.
// season: 이름 옆에 두는 시즌 기록, form: 이름 아래에 두는 최근 흐름
const PREDICTION_TABS = [
    { key: 'hit', label: '안타',
        season: (row) => row.avg != null ? `타율 ${row.avg.toFixed(3).replace(/^0/, '')}` : null,
        form: (row) => row.hitStreak >= 2 ? `${row.hitStreak}경기 연속 안타 🔥` : row.hitStreak === 1 ? '직전 경기 안타' : row.hitGamesAgo ? `마지막 안타 ${row.hitGamesAgo}경기 전` : null },
    { key: 'homeRun', label: '홈런',
        season: (row) => `${row.homeRuns ?? 0}홈런`,
        form: (row) => row.homeRunStreak >= 2 ? `${row.homeRunStreak}경기 연속 홈런 🔥` : row.homeRunStreak === 1 ? '직전 경기 홈런' : row.homeRunGamesAgo ? `마지막 홈런 ${row.homeRunGamesAgo}경기 전` : null },
];
// 순위에서 빠진 선수: 확률이 있으면 값과 함께 작은 설명을, 없으면 이유만 보여준다.
const PREDICTION_EXCLUDED = {
    inactive: (days) => ({ caption: `최근 ${days}일 출전 없음 · 순위 제외`, label: `최근 ${days}일 출전 없음` }),
    insufficient: () => ({ caption: '표본 부족 · 리그 평균으로 보정한 값 · 순위 제외', label: '예측 표본 부족' }),
};
function PredictionRow({ row, rank, probability, top, season, form, excluded }) {
    const team = row.Team || '';
    const logo = team ? getSmallTeamLogo(team) : null;
    const to = `/?pid=${encodeURIComponent(row.PlayerId)}`;
    // 사진과 이름만 프로필로 이동한다. 나머지 영역은 눌러도 이동하지 않는다.
    return <li style={{ '--c': getTeamStyle(team).color }}>
        <div className="main-home-predict-row">
            <span className={`main-home-predict-rank${rank != null && rank <= 3 ? ' is-top' : ''}`}>{rank ?? '–'}</span>
            <Link className="main-home-predict-photo" to={to} aria-label={`${row.Name} 프로필`} tabIndex={-1}><SearchPlayerPhoto player={{ PlayerId: row.PlayerId, Team: team }} />{logo && <img className="main-home-predict-logo" src={logo} alt="" />}</Link>
            <span className="main-home-predict-name"><span className="main-home-predict-title"><Link to={to}><strong>{row.Name}</strong></Link>{season && <em>{season}</em>}</span>{form && <small className="main-home-predict-stats">{form}</small>}{excluded && probability != null && <small className="main-home-predict-caveat">{excluded.caption}</small>}</span>
            {probability != null
                ? <span className="main-home-predict-value"><b>{(probability * 100).toFixed(1)}<i>%</i></b><span className="main-home-predict-bar" aria-hidden="true"><span style={{ width: `${Math.max(4, Math.min(100, probability / top * 100))}%` }} /></span></span>
                : <span className="main-home-predict-excluded">{excluded?.label}</span>}
        </div>
    </li>;
}
function PredictionRanking() {
    const [data, setData] = useState(null);
    const [tab, setTab] = useState(0);
    const [expanded, setExpanded] = useState(false);
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState({ state: 'idle', matches: [] });
    useEffect(() => {
        const controller = new AbortController();
        fetch('/api/predictionRanking.php?limit=10', { signal: controller.signal })
            .then((response) => response.ok ? response.json() : null)
            .then((result) => { if (result?.available) setData(result); })
            .catch(() => {});
        return () => controller.abort();
    }, []);
    const keyword = query.trim();
    useEffect(() => {
        // 두 글자부터 검색한다. 입력이 멈춘 뒤에 한 번만 요청한다.
        if (keyword.length < 2) { setSearch({ state: 'idle', matches: [] }); return undefined; }
        const controller = new AbortController();
        setSearch((previous) => ({ ...previous, state: 'loading' }));
        const timer = setTimeout(() => {
            fetch(`/api/predictionRanking.php?q=${encodeURIComponent(keyword)}`, { signal: controller.signal })
                .then((response) => response.ok ? response.json() : Promise.reject(new Error('search failed')))
                .then((result) => setSearch({ state: 'ready', matches: Array.isArray(result?.matches) ? result.matches : [] }))
                .catch((error) => { if (error.name !== 'AbortError') setSearch({ state: 'error', matches: [] }); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [keyword]);
    const { key, label, season, form } = PREDICTION_TABS[tab];
    const rows = data?.rankings?.[key] || [];
    if (!data || !rows.length) return null;
    const [, month, day] = String(data.asOf).split('-');
    const top = rows[0].probability || 1;
    return (
        <section className="main-home-section main-home-predict-section" aria-labelledby="preview-predict-title">
            <SectionTitle title={<><span id="preview-predict-title">다음 경기 예측</span><small className="main-home-section-date">{Number(month)}.{Number(day)} 기준</small></>} action={
                <div className="main-home-league-switch main-home-ranking-switch" style={{ '--tab-index': tab }} role="group" aria-label="예측 종류 선택">
                    {PREDICTION_TABS.map((item, index) => <button key={item.key} type="button" className={tab === index ? 'active' : ''} aria-pressed={tab === index} onClick={() => setTab(index)}>{item.label}</button>)}
                </div>
            } />
            <ol className="main-home-predict-list" aria-label={`다음 경기 ${label} 확률 순위`}>
                {(expanded ? rows : rows.slice(0, 5)).map((row) => <PredictionRow key={row.PlayerId} row={row} rank={row.rank} probability={row.probability} top={top} season={season(row)} form={form(row)} />)}
            </ol>
            {rows.length > 5 && <button type="button" className="main-home-predict-more" aria-expanded={expanded} onClick={() => setExpanded(value => !value)}>{expanded ? '접기' : `${rows.length}위까지 보기`}</button>}
            <div className="main-home-predict-search">
                <label className="main-home-predict-search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.8-3.8" /></svg>
                    <span className="sr-only">예측 확률을 볼 선수 이름</span>
                    <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="다른 선수의 확률이 궁금하다면?" maxLength={20} autoComplete="off" enterKeyHint="search" />
                    {query && <button type="button" aria-label="검색어 지우기" onClick={() => setQuery('')}>×</button>}
                </label>
                {keyword.length >= 2 && <div aria-live="polite">
                    {search.matches.length > 0 && <ol className="main-home-predict-list" aria-label={`${keyword} 검색 결과의 ${label} 확률`}>
                        {search.matches.map((row) => <PredictionRow key={row.PlayerId} row={row} rank={row[key]?.rank} probability={row[key]?.probability} top={top}
                            season={season(row)} form={form(row)} excluded={row.status === 'ready' ? null : (PREDICTION_EXCLUDED[row.status] || PREDICTION_EXCLUDED.insufficient)(data.activeDays)} />)}
                    </ol>}
                    {search.state === 'ready' && search.matches.length === 0 && <p className="main-home-predict-empty">올해 1군 타석 기록이 있는 선수 중에서 찾지 못했습니다.</p>}
                    {search.state === 'error' && <p className="main-home-predict-empty">검색 결과를 불러오지 못했습니다.</p>}
                </div>}
            </div>
            <p className="main-home-predict-note">다음 출전 경기에서 {label}{key === 'hit' ? '를' : '을'} 1개 이상 기록할 확률을 통계 모델로 추정한 값입니다.<br />순위에는 최근 {data.activeDays}일 안에 출전한 선수만 포함합니다.</p>
        </section>
    );
}

export default function Main() {
    const location = useLocation();
    const navigate = useNavigate();
    const [league, setLeague] = useState("kbo");
    const [rankingTab, setRankingTab] = useState(0);
    const [ranking, setRanking] = useState({ state: "loading", rows: [], title: "" });
    // 오늘 경기가 새로 끝나면 순위를 다시 불러온다.
    const [rankingReload, setRankingReload] = useState(0);
    const finishedGameCount = useRef(null);
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
    }, [rankingReload]);
    const [search, setSearch] = useState("");
    const searchDialog = useRef(null);
    const searchInput = useRef(null);
    const [searchOpen, setSearchOpen] = useState(false);
    const [players, setPlayers] = useState([]);
    const [playerSearchState, setPlayerSearchState] = useState("idle");
    const [popularPlayers, setPopularPlayers] = useState({ state: "idle", list: [], source: null });
    const [recentPlayers, setRecentPlayers] = useState(readRecentPlayers);
    // PC에서 최근 본 선수 줄을 마우스 휠과 끌기로 좌우로 넘길 수 있게 한다(터치는 원래 넘겨진다).
    const [recentList, setRecentList] = useState(null);
    useEffect(() => {
        if (!recentList) return undefined;
        let drag = null, moved = false;
        const wheel = (event) => {
            const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
            const max = recentList.scrollWidth - recentList.clientWidth;
            // 넘길 것이 없거나 이미 끝이면 페이지 스크롤을 막지 않는다.
            if (max <= 0 || (delta < 0 && recentList.scrollLeft <= 0) || (delta > 0 && recentList.scrollLeft >= max - 1)) return;
            event.preventDefault();
            recentList.scrollLeft += delta;
        };
        const down = (event) => { if (event.pointerType === 'mouse' && event.button === 0) { drag = { x: event.clientX, left: recentList.scrollLeft }; moved = false; } };
        const move = (event) => {
            if (!drag) return;
            const distance = event.clientX - drag.x;
            if (Math.abs(distance) > 4) moved = true;
            if (moved) recentList.scrollLeft = drag.left - distance;
        };
        const up = () => { drag = null; };
        // 끌어서 넘긴 직후의 클릭이 선수 선택으로 이어지지 않게 막는다.
        const click = (event) => { if (moved) { event.preventDefault(); event.stopPropagation(); moved = false; } };
        recentList.addEventListener('wheel', wheel, { passive: false });
        recentList.addEventListener('pointerdown', down);
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        recentList.addEventListener('click', click, true);
        // 링크·사진의 기본 끌기(다른 창으로 끌어다 놓기)가 시작되면 좌우 넘기기가 끊긴다.
        const noNativeDrag = (event) => event.preventDefault();
        recentList.addEventListener('dragstart', noNativeDrag);
        return () => {
            recentList.removeEventListener('dragstart', noNativeDrag);
            recentList.removeEventListener('wheel', wheel);
            recentList.removeEventListener('pointerdown', down);
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            recentList.removeEventListener('click', click, true);
        };
    }, [recentList]);
    const rememberPlayer = (player) => setRecentPlayers((previous) => addRecentPlayer(player, previous));
    const forgetPlayer = (playerId) => setRecentPlayers((previous) => removeRecentPlayer(playerId, previous));
    const openSearch = () => {
        if (!searchDialog.current.open) searchDialog.current.showModal();
        searchInput.current.focus();
        setSearchOpen(true);
    };
    useEffect(() => {
        if (new URLSearchParams(location.search).get('search') === '1') {
            if (!searchDialog.current.open) searchDialog.current.showModal();
            searchInput.current.focus();
            setSearchOpen(true);
        }
    }, [location.search]);
    useEffect(() => {
        const keyword = search.trim();
        setPlayers([]);
        if (!searchOpen || (keyword.length < 2 && !["홀", "필", "얀"].includes(keyword))) {
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
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [search, searchOpen]);
    useEffect(() => {
        if (!searchOpen || popularPlayers.state !== "idle") return;
        setPopularPlayers({ state: "loading", list: [], source: null });
        const loadFallback = () => Promise.all(POPULAR_PLAYER_NAMES.map(async (name) => {
            try {
                const response = await fetch("/api/kbocandle/get_player_list.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ name }),
                });
                if (!response.ok) return null;
                const result = await response.json();
                return pickPopularPlayer(result.list || result, name);
            } catch { return null; }
        })).then((list) => list.filter(Boolean));
        (async () => {
            // 실제 조회수 순위를 먼저 쓰고, 집계가 아직 쌓이지 않았으면 기본 추천 목록으로 채운다.
            let ranked = [];
            try {
                const response = await fetch(`/api/popularPlayers.php?limit=${POPULAR_PLAYER_NAMES.length}`);
                const result = await response.json();
                if (response.ok && Array.isArray(result.list)) ranked = result.list;
            } catch { /* 기본 추천 목록 사용 */ }
            if (ranked.length >= POPULAR_MIN_RANKED) {
                setPopularPlayers({ state: "ready", list: ranked, source: "views" });
                return;
            }
            const fallback = await loadFallback();
            setPopularPlayers({ state: fallback.length ? "ready" : "error", list: fallback, source: "curated" });
        })();
    }, [searchOpen, popularPlayers.state]);
    useEffect(() => {
        if (!searchOpen) return;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        return () => { document.body.style.overflow = previousOverflow; };
    }, [searchOpen]);
    const [gameLists, setGameLists] = useState({ kbo: [], futures: [] });
    const [gameState, setGameState] = useState("loading");
    const [gameReload, setGameReload] = useState(0);
    const [heroPopular, setHeroPopular] = useState({ ranked: false, list: [] });
    useEffect(() => {
        // 히어로 영역 바로가기: 실제 조회수 순위가 있으면 쓰고, 없으면 기본 추천 이름으로 검색을 연다.
        const controller = new AbortController();
        fetch('/api/popularPlayers.php?limit=5', { signal: controller.signal })
            .then((response) => response.ok ? response.json() : null)
            .then((result) => {
                const list = Array.isArray(result?.list) ? result.list : [];
                setHeroPopular(list.length >= POPULAR_MIN_RANKED ? { ranked: true, list: list.slice(0, 5) } : { ranked: false, list: POPULAR_PLAYER_NAMES.slice(0, 5) });
            })
            .catch((error) => { if (error.name !== 'AbortError') setHeroPopular({ ranked: false, list: POPULAR_PLAYER_NAMES.slice(0, 5) }); });
        return () => controller.abort();
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const loadGames = async () => {
            try {
                const response = await fetch("/api/todayGames.php", { signal: controller.signal, cache: "no-store" });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.error || "경기 정보를 불러오지 못했습니다.");

                const kboGames = Array.isArray(result.kboGames) ? result.kboGames : [];
                const futuresGames = Array.isArray(result.futuresGames) ? result.futuresGames : [];
                setGameLists({ kbo: kboGames, futures: futuresGames });
                setGameState("ready");
                const finished = kboGames.filter((game) => game.isGameFinished).length;
                if (finishedGameCount.current !== null && finished > finishedGameCount.current) setRankingReload((count) => count + 1);
                finishedGameCount.current = finished;
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
    }, [gameReload]);

    const games = gameLists[league];
    const todayLabel = new Intl.DateTimeFormat('ko-KR', { timeZone: 'Asia/Seoul', month: 'numeric', day: 'numeric', weekday: 'short' }).format(new Date());
    const retryGames = () => { setGameState("loading"); setGameReload((value) => value + 1); };
    const openSearchWith = (name) => { setSearch(name); openSearch(); };
    const isActiveKboPlayer = (player) => player.IsActive ?? (player.Team !== '은퇴');
    const playerGroups = [
        { id: "active", title: "현역 선수", list: players.filter(isActiveKboPlayer) },
        { id: "other", title: "은퇴", list: players.filter((player) => !isActiveKboPlayer(player)) },
    ];

    return (
        <main className="main-home font-family-NaSqNe">
            <dialog ref={searchDialog} className="main-home-search-screen" aria-label="선수 검색" onClose={() => setSearchOpen(false)}>
                <div className="main-home-search-screen-inner">
                    <header className="main-home-search-header">
                        <button type="button" className="main-home-search-back" aria-label="검색 닫기" onClick={() => searchDialog.current.close()}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7" /></svg>
                        </button>
                        <form className="main-home-search" role="search" onSubmit={(event) => {
                                event.preventDefault();
                                // 검색 결과가 한 명뿐이면 엔터로 바로 그 선수 페이지로 간다.
                                if (playerSearchState !== "ready" || players.length !== 1) return;
                                rememberPlayer(players[0]);
                                navigate(`/?pid=${encodeURIComponent(players[0].PlayerId)}`, { state: { player: players[0] } });
                            }}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8" /><path d="m16 16 5 5" /></svg>
                            <input ref={searchInput} type="search" aria-label="선수 이름" placeholder="선수 이름을 검색해 보세요!" value={search} onChange={(event) => setSearch(event.target.value)} autoComplete="off" enterKeyHint="search" />
                            {search && <button type="button" className="main-home-search-clear" aria-label="검색어 지우기" onClick={() => { setSearch(""); searchInput.current.focus(); }}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg></button>}
                        </form>
                    </header>
                    <div className="main-home-player-results" aria-busy={playerSearchState === "loading"}>
                        {playerSearchState === "idle" && <div className="main-home-search-idle">
                            {recentPlayers.length > 0 && <section aria-labelledby="search-recent-title">
                                <div className="main-home-search-section-head">
                                    <h2 id="search-recent-title">최근 본 선수</h2>
                                    <button type="button" onClick={() => forgetPlayer(null)}>전체 삭제</button>
                                </div>
                                <ul className="main-home-recent-list" ref={setRecentList}>
                                    {recentPlayers.map((player) => {
                                        const { color } = getPlayerTeamInfo(player);
                                        return <li key={player.PlayerId} className="main-home-recent-chip" style={{'--search-team-color':color}}>
                                            <Link to={`/?pid=${encodeURIComponent(player.PlayerId)}`} state={{ player }} onClick={() => rememberPlayer(player)}>
                                                <span className="main-home-recent-photo"><SearchPlayerPhoto player={player} /></span>
                                                <span>{player.Name}</span>
                                            </Link>
                                            <button type="button" aria-label={`${player.Name} 최근 기록 삭제`} onClick={() => forgetPlayer(player.PlayerId)}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg></button>
                                        </li>;
                                    })}
                                </ul>
                            </section>}
                            <section aria-labelledby="search-popular-title">
                                <div className="main-home-search-section-head">
                                    <h2 id="search-popular-title"><span aria-hidden="true">🔥</span> 인기 선수</h2>
                                </div>
                                {popularPlayers.state === "loading" && <ul className="main-home-popular-list" aria-label="인기 선수 불러오는 중">
                                    {POPULAR_PLAYER_NAMES.slice(0, 6).map((name) => <li key={name}><div className="main-home-search-skeleton" /></li>)}
                                </ul>}
                                {popularPlayers.state === "error" && <p className="main-home-search-hint">인기 선수 정보를 불러오지 못했습니다.</p>}
                                {popularPlayers.state === "ready" && <ol className="main-home-popular-list">
                                    {popularPlayers.list.map((player, index) => <li key={player.PlayerId}><Link className="main-home-player-link" to={`/?pid=${encodeURIComponent(player.PlayerId)}`} state={{ player }} onClick={() => rememberPlayer(player)}><SearchPlayerRow player={player} rank={index + 1} /></Link></li>)}
                                </ol>}
                            </section>
                        </div>}
                        <div role="status">
                            {playerSearchState === "loading" && <div className="main-home-player-loading"><Spinner size="lg" className="fill-blue-600" aria-label="선수 검색 중" /></div>}
                            {playerSearchState === "error" && <p>검색 정보를 불러오지 못했습니다. 잠시 후 다시 입력해 주세요.</p>}
                            {playerSearchState === "ready" && players.length === 0 && <div className="main-home-player-empty">
                                <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="2.5" aria-hidden="true"><circle cx="24" cy="24" r="19" /><path d="M24 13v14" strokeLinecap="round" /><circle cx="24" cy="34" r="1.5" fill="currentColor" stroke="none" /></svg>
                                <p>검색 결과가 없습니다.</p>
                            </div>}
                        </div>
                        {playerSearchState === "ready" && players.length > 0 && playerGroups.filter((group) => group.list.length > 0).map((group) => (
                            <section className="main-home-player-group" key={group.id} aria-labelledby={`player-group-${group.id}`}>
                                <h2 id={`player-group-${group.id}`}>{group.title} <span>{group.list.length}</span></h2>
                                <ul aria-label={group.title}>
                                    {group.list.map((player) => <li key={player.PlayerId}><Link className="main-home-player-link" to={`/?pid=${encodeURIComponent(player.PlayerId)}`} state={{ player }} onClick={() => rememberPlayer(player)}><SearchPlayerRow player={player} keyword={search} /></Link></li>)}
                                </ul>
                            </section>
                        ))}
                    </div>
                </div>
            </dialog>
            <div className="main-home-inner">
                <section className="main-home-search-hero" aria-labelledby="preview-search-title">
                    <div className="main-home-hero-banner">
                        <div className="main-home-hero-title">
                            <img className="main-home-hero-favicon" src="/wesiper-favicon.png" alt="" />
                            <h1 id="preview-search-title"><span>KBO</span> 선수 도감</h1>
                        </div>
                    </div>
                    <button type="button" className="main-home-search main-home-hero-search" onClick={openSearch} aria-haspopup="dialog" aria-label="선수 검색 화면 열기">
                        <span className="main-home-hero-search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round"><circle cx="10.8" cy="10.8" r="6.3" /><path d="m15.6 15.6 4.4 4.4" /></svg></span>
                        <span className="main-home-hero-search-text">{search || "선수 이름을 검색해 보세요!"}</span>
                        <span className="main-home-hero-search-cta" aria-hidden="true">검색</span>
                    </button>
                    {heroPopular.list.length > 0 && <div className="main-home-hero-popular">
                        <span className="main-home-hero-chips-label" role="img" aria-label="인기 급상승"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m3 17 6-6 4 4 8-8" /><path d="M15 7h6v6" /></svg></span>
                        <div className="main-home-hero-chips" aria-label="인기 선수 바로가기">
                        {heroPopular.ranked
                            ? heroPopular.list.map((player) => <Link key={player.PlayerId} className="main-home-hero-chip" to={`/?pid=${encodeURIComponent(player.PlayerId)}`} state={{ player }} onClick={() => rememberPlayer(player)}><span className="main-home-hero-chip-photo"><SearchPlayerPhoto player={player} /></span>{player.Name}</Link>)
                            : heroPopular.list.map((name) => <button key={name} type="button" className="main-home-hero-chip" onClick={() => openSearchWith(name)}>{name}</button>)}
                        </div>
                    </div>}
                </section>
                <section className="main-home-section main-home-match-section" aria-labelledby="preview-games-title">
                    <SectionTitle title={<><span id="preview-games-title">오늘의 KBO</span><small className="main-home-section-date">{todayLabel}</small></>} action={
                        <div className={`main-home-league-switch ${league === "futures" ? "is-futures" : ""}`} role="group" aria-label="리그 선택">
                            <button type="button" className={league === "kbo" ? "active" : ""} aria-pressed={league === "kbo"} onClick={() => setLeague("kbo")}>KBO 1군</button>
                            <button type="button" className={league === "futures" ? "active" : ""} aria-pressed={league === "futures"} onClick={() => setLeague("futures")}>퓨처스리그</button>
                        </div>
                    } />
                    <div className="main-home-game-grid">
                        {gameState === "loading" && <div className="main-home-game-skeletons" role="status" aria-label="오늘의 경기 정보를 불러오는 중">{Array.from({ length: 3 }, (_, i) => <div key={i} className="main-home-game-skeleton" />)}</div>}
                        {gameState === "error" && <div className="main-home-game-message main-home-game-error" role="status">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M12 8v5M12 16.5v.01" /><circle cx="12" cy="12" r="9" /></svg>
                            <p>경기 정보를 불러오지 못했습니다.</p>
                            <button type="button" onClick={retryGames}>다시 시도</button>
                        </div>}
                        {gameState === "ready" && games.length === 0 && <div className="main-home-game-message" role="status">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M5.5 6.5c2.5 2 2.5 9 0 11M18.5 6.5c-2.5 2-2.5 9 0 11" /></svg>
                            <p>오늘 예정된 {league === "futures" ? "퓨처스리그" : "KBO"} 경기가 없습니다.</p>
                        </div>}
                        {gameState === "ready" && games.map((game, index) => {
                            const away = getTeamStyle(game.away);
                            const home = getTeamStyle(game.home);
                            const participants = gameParticipants(game);
                            // 경기는 예정(시작 시각)·종료(최종 점수)·취소 세 가지로만 보여준다.
                            const cancelled = game.GAME_STATE_SC === "4" || /취소/.test(game.status || "");
                            const hasScore = game.isGameFinished && !cancelled && (game.away_score !== "" || game.home_score !== "");
                            return (
                                <article className="main-home-game-card" key={`${game.away}-${game.home}-${index}`} style={{ "--away-color": away.color, "--home-color": home.color }}>
                                    <TeamLogo name={game.away} logo={away.logo} />
                                    <div className="main-home-team-info"><strong className="main-home-team-name">{game.away}</strong><GameParticipant text={participants.awayPlayer} /></div>
                                    <div className="main-home-game-center">
                                        {hasScore ? <strong className="main-home-score"><b className={Number(game.away_score) > Number(game.home_score) ? "is-leading" : ""}>{game.away_score || 0}</b><span>:</span><b className={Number(game.home_score) > Number(game.away_score) ? "is-leading" : ""}>{game.home_score || 0}</b></strong> : <strong className="main-home-game-time">{game.status || "경기 예정"}</strong>}
                                        <span className="main-home-game-status">{hasScore && <b className="main-home-game-final">종료</b>}{game.stadium}{!hasScore && !cancelled && <GameWeather weather={game.weather} />}</span>
                                    </div>
                                    <div className="main-home-team-info main-home-team-home"><strong className="main-home-team-name">{game.home}</strong><GameParticipant text={participants.homePlayer} home /></div>
                                    <TeamLogo name={game.home} logo={home.logo} />
                                </article>
                            );
                        })}
                    </div>
                </section>

                <section className="main-home-section main-home-records-section" aria-labelledby="preview-records-title">
                    <SectionTitle title={<span id="preview-records-title">KBO 순위</span>} action={
                        <div className="main-home-league-switch main-home-ranking-switch" style={{ '--tab-index': rankingTab }} role="group" aria-label="순위 선택">
                            {['팀 순위', '가을야구'].map((label, index) => <button key={label} type="button" className={rankingTab === index ? 'active' : ''} aria-pressed={rankingTab === index} onClick={() => setRankingTab(index)}>{label}</button>)}
                        </div>
                    } />
                    <>
                        {ranking.state === 'error' && <p role="status">팀 순위를 불러오지 못했습니다.</p>}
                        {(ranking.state === 'loading' || ranking.state === 'ready') && (ranking.state === 'loading' || ranking.rows.length ? <div className="main-home-ranking-table-wrap"><table className={`main-home-ranking-table ${rankingTab === 1 ? 'is-autumn' : ''}`} aria-busy={ranking.state === 'loading'}>
                            <caption className="sr-only">KBO {rankingTab === 0 ? '팀 순위' : '가을야구'} {ranking.title}</caption>
                            <colgroup><col style={{ width: 36 }} /><col style={{ width: 68 }} />{(rankingTab === 0 ? [null, null, null, null, null, 46, 44, 117] : [null, null, null]).map((width, index) => <col key={index} style={width ? { width } : undefined} />)}</colgroup>
                            <thead><tr>{(rankingTab === 0 ? ['순위', '팀', '경기', '승', '패', '무', '승률', '게임차', '연속', '최근 5경기'] : ['순위', '팀', '남은\n경기', '1위\n매직넘버', '가을야구\n매직 · 트래직 넘버']).map((label) => <th scope="col" key={label}>{label}</th>)}</tr></thead>
                            <tbody>{ranking.state === 'loading' ? Array.from({ length: 10 }, (_, rowIndex) => <tr key={rowIndex} aria-hidden="true">
                                <td>{rowIndex + 1}</td>
                                <th scope="row"><span className="main-home-ranking-placeholder is-team" /></th>
                                <td colSpan={rankingTab === 0 ? 8 : 3}><span className="main-home-ranking-placeholder is-row" /></td>
                            </tr>) : ranking.rows.map((row) => <tr key={row[1]} className={rankingTab === 0 && Number(row[0]) <= 5 ? 'is-postseason' : undefined}>
                                <td><span className="main-home-rank-num">{row[0]}</span></td><th scope="row"><div><TeamLogo name={row[1]} logo={getTeamStyle(row[1]).logo} />{row[1]}</div></th>
                                {rankingTab === 0 ? row.slice(2, 10).map((value, index) => <td key={index}>{index === 7 ? <span className="main-home-recent-results" aria-label={`최근 5경기 ${value}`}>
                                    {[...value].map((result, i) => <span key={i} className={result === '승' ? 'is-win' : result === '패' ? 'is-loss' : 'is-draw'}>{result}</span>)}
                                </span> : value}</td>) : <>
                                    <td>{row[10]}</td>
                                    {row[14] === '가을야구 불가' ? <td colSpan={2}><span className="main-home-rank-remark is-out">가을야구 탈락</span></td> : <>
                                        <td>{row[11] === 'X' && row[14]?.endsWith('위 불가') ? <span className="main-home-rank-remark is-unreachable">{row[14]}</span> : row[11] === 'X' ? '-' : `${row[11]}승`}</td>
                                        <td>{row[14]?.endsWith('확보') ? <span className="main-home-rank-remark is-secured">{row[14]}</span>
                                            : [row[12] !== 'X' ? `${row[12]}승` : null, row[13] !== 'X' ? `${row[13]}패` : null].filter(Boolean).join(' · ') || '-'}</td>
                                    </>}
                                </>}
                            </tr>)}</tbody>
                        </table></div> : <p>표시할 팀 순위가 없습니다.</p>)}
                    </>
                </section>

                <PredictionRanking />

                <section className="main-home-section main-home-games-section" aria-labelledby="preview-mini-title">
                    <SectionTitle title={<span id="preview-mini-title">미니게임</span>} />
                    <div className="main-home-mini-grid">
                        <Link className="main-home-mini-card main-home-kbodle-card" to="/kbodle">
                            <div className="main-home-mini-art" aria-hidden="true">
                                <div className="main-home-kbodle-tiles">{Array.from({ length: 7 }, (_, i) => <span key={i} className={i < 2 ? 'is-match' : i === 4 || i === 5 ? 'is-close' : ''}>?</span>)}</div>
                            </div>
                            <strong className="font-family-kbo">KBODLE: 크보들</strong><small>단서를 조합해 오늘의 선수를 맞혀보세요</small><span className="main-home-mini-go" aria-hidden="true">↗</span>
                        </Link>
                        <Link className="main-home-mini-card main-home-bingo-card" to="/bingo">
                            <div className="main-home-mini-art" aria-hidden="true">
                                <div className="main-home-bingo-board">{Array.from({ length: 9 }, (_, i) => <span key={i} className={i % 4 === 0 ? 'is-filled' : ''}>{i % 4 === 0 ? '✓' : ''}</span>)}</div>
                            </div>
                            <strong className="font-family-kbo">KBO BINGO</strong><small>야구 지식으로 아홉 칸을 채워보세요</small><span className="main-home-mini-go" aria-hidden="true">↗</span>
                        </Link>
                    </div>
                </section>
            </div>
        </main>
    );
}
