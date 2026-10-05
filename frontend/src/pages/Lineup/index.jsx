import { Fragment, useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import axios from "axios";
import { TwitterShareButton, XIcon } from "react-share";
import RealisticModule from "react-canvas-confetti/dist/presets/realistic";
import { teamFullName } from "../../lib/teamFullName";
import { teamCodeByName, teamLogoByCode, teamSmallLogoByCode } from "../../lib/teamAssets";
import "./lineup.css";

// 크보들과 같은 색종이 효과. 묶는 방식에 따라 default로 한 번 더 싸여 올 수 있다.
const Realistic = RealisticModule.default ?? RealisticModule;
// 오늘의 라인업을 맞힌 뒤, 색종이가 한 번 터지고 결과 창이 뜨기까지의 시간
const CELEBRATION_MS = 1800;
const TOAST_MS = 2200;

const FIRST_YEAR = 2001;
// 고를 수 있는 마지막 시즌. 서버(getKBOSchedule)에 새 시즌이 추가되면 함께 올린다.
const LAST_YEAR = 2026;
const YEARS = Array.from({ length: LAST_YEAR - FIRST_YEAR + 1 }, (_, i) => LAST_YEAR - i);
// 구단: [id, 연도를 정하지 않았을 때의 이름, 시기별 팀명 [첫 해, 마지막 해, 이름]]. backend/lib/lineup-game.php의 LINEUP_TEAMS와 맞춘다.
const TEAMS = [
    ["kia", "KIA", [[2001, LAST_YEAR, "KIA"]]],
    ["sam", "삼성", [[2001, LAST_YEAR, "삼성"]]],
    ["lg", "LG", [[2001, LAST_YEAR, "LG"]]],
    ["doo", "두산", [[2001, LAST_YEAR, "두산"]]],
    ["lot", "롯데", [[2001, LAST_YEAR, "롯데"]]],
    ["han", "한화", [[2001, LAST_YEAR, "한화"]]],
    ["ssg", "SSG (SK)", [[2001, 2020, "SK"], [2021, LAST_YEAR, "SSG"]]],
    ["kiw", "키움 (넥센·히어로즈)", [[2008, 2009, "히어로즈"], [2010, 2018, "넥센"], [2019, LAST_YEAR, "키움"]]],
    ["nc", "NC", [[2013, LAST_YEAR, "NC"]]],
    ["kt", "KT", [[2015, LAST_YEAR, "KT"]]],
    ["hyd", "현대", [[2001, 2007, "현대"]]],
];
const DIFFICULTIES = [
    // [id, 이름, 시작 화면에 보여주는 설명]
    ["easy", "쉬움", "선발로 나온 9명만 후보로 나옵니다."],
    ["normal", "보통", "선발 9명에 교체로 나온 선수까지 후보로 나옵니다."],
    ["hard", "어려움", "후보 없이 선수 이름을 직접 입력합니다."],
    ["extreme", "익스트림", "이름을 직접 입력하고, 포지션과 타자 기록도 알려주지 않습니다."],
];
const SETTINGS_KEY = "lineup-settings";
const STATS_KEY = "lineup-stats";
const OPTIONS_KEY = "lineup-options";
const PROGRESS_KEY = "lineup-progress";
// 오늘의 라인업은 자유 플레이를 하고 와도 이어지도록 진행 상황을 따로 보관한다.
const DAILY_PROGRESS_KEY = "lineup-daily-progress";
const DAILY_URL = "https://wesiper.xyz/lineup?daily=1";
// 카카오톡 공유에 쓰는 JavaScript 키(크보들·빙고와 같은 앱)
const KAKAO_KEY = "25dedcd63c24a5a75a9fae607290fd1f";
// 공유 글에 그리는 채점 결과
const SHARE_SQUARES = { correct: "🟩", present: "🟨", absent: "⬜" };
const DAY_MS = 86400000;
const KST_OFFSET_MS = 9 * 3600000;
// 오늘의 문제는 한국 시간 자정에 바뀐다.
const todayInKorea = () => new Date(Date.now() + KST_OFFSET_MS).toISOString().slice(0, 10);
const CLIENT_KEY = "lineup-client";
// 게임 설정의 기본값. autoFocus는 꺼 둬도 어려움·익스트림에서는 항상 켜진 것으로 본다.
const DEFAULT_OPTIONS = { autoFocus: false, stat: "avg", confirmSubmit: false };
const STAT_NAMES = [["avg", "타율"], ["ops", "OPS"]];
const SEARCH_DEBOUNCE_MS = 150;
const DRAG_THRESHOLD_PX = 6;
// 구단 색 [주색상, 보조색상]. 키는 로고 파일 코드(teamCodeByName)라서 시기별 팀(SK·넥센 등)도 따로 정한다.
const TEAM_COLORS = {
    kia: ["#ea0029", "#06141f"], sam: ["#074ca1", "#8fa3bd"], lg: ["#c30452", "#1a1a1a"], doo: ["#131230", "#ed1c24"],
    lot: ["#041e42", "#d00f31"], han: ["#fc4e00", "#07111f"], ssg: ["#ce0e2d", "#ffb81c"], kiw: ["#570514", "#b07f4a"],
    nc: ["#315288", "#af917b"], kt: ["#1a1a1a", "#eb1c24"], sk: ["#ea002c", "#f58220"], sk00: ["#0b4a8f", "#ea002c"],
    nex: ["#820024", "#b07f4a"], heroes: ["#820024", "#1a1a1a"], hyd: ["#0b6b3a", "#f6c416"],
};
const DEFAULT_COLORS = ["#1f2937", "#6b7280"];
const WEEKDAYS = ["일", "월", "화", "수", "목", "금", "토"];
const BAT_NAMES = { R: "우타", L: "좌타", S: "양타(스위치)" };
const POSITION_NAMES = { C: "포수", "1B": "1루수", "2B": "2루수", "3B": "3루수", SS: "유격수", LF: "좌익수", CF: "중견수", RF: "우익수", DH: "지명타자", P: "투수" };

// 연도에 맞는 팀명. 그해에 없던 팀이면 null.
const teamNameIn = ([, label, eras], year) => year === "random" ? label : eras.find(([from, to]) => year >= from && year <= to)?.[2] ?? null;
const formatDate = (date) => {
    const [year, month, day] = date.split("-").map(Number);
    return `${year}년 ${month}월 ${day}일 (${WEEKDAYS[new Date(year, month - 1, day).getDay()]})`;
};
const loadSettings = () => {
    const fallback = { year: "random", team: "random", difficulty: "easy" };
    try {
        const saved = JSON.parse(localStorage.getItem(SETTINGS_KEY)) || {};
        const year = YEARS.includes(saved.year) ? saved.year : "random";
        const team = TEAMS.find(([id]) => id === saved.team);
        return {
            year,
            team: team && teamNameIn(team, year) ? saved.team : "random",
            difficulty: DIFFICULTIES.some(([id]) => id === saved.difficulty) ? saved.difficulty : "easy",
        };
    } catch {
        return fallback;
    }
};

// 시간은 밀리초로 재고 저장한다. 보여줄 때 1분 미만은 초의 소수 둘째 자리까지(12.35초), 1분 이상은 초 단위까지만 쓴다(1분 12초).
const formatDuration = (milliseconds) => {
    // 59.999초가 「60.00초」로 찍히지 않도록 먼저 1/100초로 반올림해 보고 1분이 되는지 가른다.
    const hundredths = Math.round(milliseconds / 10);
    if (hundredths < 6000) return `${(hundredths / 100).toFixed(2)}초`;
    const seconds = Math.round(milliseconds / 1000);
    return `${Math.floor(seconds / 60)}분 ${seconds % 60}초`;
};

// 서버 통계·랭킹에서 같은 사람을 한 번만 세기 위한 식별자. 개인 정보는 담지 않는다.
// 닉네임을 함께 쓰려고 크보빙고가 만든 값(localStorage "uuid")을 그대로 쓰고, 없으면 여기서 만들어 빙고와 나눠 쓴다.
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const newUuid = () => {
    if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
    const hex = Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, "0")).join("");
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};
const clientUuid = () => {
    try {
        const shared = localStorage.getItem("uuid");
        if (UUID_PATTERN.test(shared || "")) return shared.toLowerCase();
        // 빙고가 예전에 다른 형식으로 만들어 둔 값은 건드리지 않고, 라인업 전용 값을 따로 둔다.
        let own = localStorage.getItem(CLIENT_KEY) || "";
        // 처음에는 줄표 없는 32자로 보관했다.
        if (/^[0-9a-f]{32}$/.test(own)) own = `${own.slice(0, 8)}-${own.slice(8, 12)}-${own.slice(12, 16)}-${own.slice(16, 20)}-${own.slice(20)}`;
        if (!UUID_PATTERN.test(own)) own = newUuid();
        localStorage.setItem(CLIENT_KEY, own);
        if (shared === null) localStorage.setItem("uuid", own);
        return own.toLowerCase();
    } catch {
        return null;
    }
};
// 서버에는 줄표를 뺀 32자로 보낸다.
const clientId = () => clientUuid()?.replace(/-/g, "") ?? null;

// 풀던 문제의 진행 상황. 새로고침하거나 같은 주소로 다시 들어와도 이어서 풀 수 있게 가장 최근 한 문제만 보관한다.
const loadProgress = (key, storage = PROGRESS_KEY) => {
    try {
        const saved = JSON.parse(localStorage.getItem(storage));
        return saved?.key === key ? saved : null;
    } catch {
        return null;
    }
};
const saveProgress = (progress, storage = PROGRESS_KEY) => {
    try {
        localStorage.setItem(storage, JSON.stringify(progress));
    } catch {
        // 저장소를 쓸 수 없으면 새로고침 때 처음부터 다시 푼다.
    }
};

// 난이도별 통계: { [난이도]: { played, solved, attempts(맞힌 판의 제출 횟수 합), best(가장 적은 제출 횟수), milliseconds·timed(시간을 잰 맞힌 판의 시간 합·판 수) } }
const loadStats = () => {
    try {
        return JSON.parse(localStorage.getItem(STATS_KEY)) || {};
    } catch {
        return {};
    }
};
// 다 맞혔거나, 정답을 봤거나, 제출한 뒤 건너뛴 판을 한 판으로 센다. 제출 없이 건너뛰거나 설정으로 나간 판은 세지 않는다.
const recordResult = (difficulty, solved, attempts, milliseconds = null) => {
    const stats = loadStats();
    const entry = { played: 0, solved: 0, attempts: 0, best: null, milliseconds: 0, timed: 0, ...stats[difficulty] };
    entry.played += 1;
    if (solved) {
        entry.solved += 1;
        entry.attempts += attempts;
        entry.best = entry.best === null ? attempts : Math.min(entry.best, attempts);
        if (milliseconds !== null) {
            entry.milliseconds += milliseconds;
            entry.timed += 1;
        }
    }
    try {
        localStorage.setItem(STATS_KEY, JSON.stringify({ ...stats, [difficulty]: entry }));
    } catch {
        // 저장소를 쓸 수 없으면 통계만 남지 않는다.
    }
};

const StatsModal = ({ onClose }) => {
    const [stats, setStats] = useState(loadStats);
    useEffect(() => {
        const onKeyDown = (event) => { if (event.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, [onClose]);
    const reset = () => {
        if (!window.confirm("통계를 모두 지울까요?")) return;
        try {
            localStorage.removeItem(STATS_KEY);
        } catch {
            // 지울 수 없으면 화면만 비운다.
        }
        setStats({});
    };
    return (
        <div className="lineup-modal" role="dialog" aria-modal="true" aria-labelledby="lineup-stats-title" onClick={onClose}>
            <div className="lineup-modal-panel lineup-stats" onClick={(event) => event.stopPropagation()}>
                <h2 id="lineup-stats-title">내 통계</h2>
                <table>
                    <thead><tr><th scope="col">난이도</th><th scope="col">플레이</th><th scope="col">정답</th><th scope="col">정답률</th><th scope="col">평균 제출</th><th scope="col">최소 제출</th><th scope="col">평균 시간</th></tr></thead>
                    <tbody>
                        {DIFFICULTIES.map(([id, name]) => {
                            const { played = 0, solved = 0, attempts = 0, best = null, milliseconds = 0, timed = 0 } = stats[id] || {};
                            return (
                                <tr key={id}>
                                    <th scope="row">{name}</th><td>{played}</td><td>{solved}</td>
                                    <td>{played ? `${Math.round(solved / played * 100)}%` : "-"}</td>
                                    <td>{solved ? (attempts / solved).toFixed(1) : "-"}</td>
                                    <td>{best ?? "-"}</td>
                                    <td>{timed ? formatDuration(milliseconds / timed) : "-"}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div>
                    <button type="button" className="lineup-secondary" onClick={reset}>통계 지우기</button>
                    <button type="button" className="lineup-primary" autoFocus onClick={onClose}>닫기</button>
                </div>
            </div>
        </div>
    );
};

const loadOptions = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(OPTIONS_KEY)) || {};
        return {
            autoFocus: saved.autoFocus === true,
            stat: STAT_NAMES.some(([id]) => id === saved.stat) ? saved.stat : DEFAULT_OPTIONS.stat,
            confirmSubmit: saved.confirmSubmit === true,
        };
    } catch {
        return DEFAULT_OPTIONS;
    }
};

const SettingsModal = ({ options, onChange, onClose }) => {
    useEffect(() => {
        const onKeyDown = (event) => { if (event.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, [onClose]);
    const toggle = (key, title, note) => (
        <label>
            <span><b>{title}</b>{note && <small>{note}</small>}</span>
            <input type="checkbox" role="switch" checked={options[key]} onChange={(event) => onChange({ [key]: event.target.checked })} />
        </label>
    );
    return (
        <div className="lineup-modal" role="dialog" aria-modal="true" aria-labelledby="lineup-settings-title" onClick={onClose}>
            <div className="lineup-modal-panel lineup-settings" onClick={(event) => event.stopPropagation()}>
                <h2 id="lineup-settings-title">설정</h2>
                <button type="button" className="lineup-modal-close" aria-label="닫기" autoFocus onClick={onClose}>×</button>
                {toggle("autoFocus", "시작할 때 입력창에 자동 포커스", "어려움·익스트림은 항상 켜집니다.")}
                {toggle("confirmSubmit", "제출할 때 한 번 더 확인")}
                <div className="lineup-settings-row">
                    <span><b>타자 기록</b></span>
                    <div className="lineup-segment" role="radiogroup" aria-label="타자 기록">
                        {STAT_NAMES.map(([id, name]) => (
                            <button key={id} type="button" role="radio" aria-checked={options.stat === id} className={options.stat === id ? "is-active" : ""} onClick={() => onChange({ stat: id })}>{name}</button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
};

const Gear = () => (
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
    </svg>
);

const Chevron = () => (
    <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M5 7.5l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" /></svg>
);

// 팀 고르기. <select>의 <option>에는 로고를 넣을 수 없어 목록을 직접 그린다.
const TeamSelect = ({ value, year, teams, onChange }) => {
    const [open, setOpen] = useState(false);
    const root = useRef(null);
    // 연도를 정하지 않았으면 지금 팀명·로고로 보여준다.
    const options = [{ id: "random", name: "랜덤" }, ...teams.map((team) => ({
        id: team[0], name: teamNameIn(team, year), logo: year === "random" ? [team[2].at(-1)[2], LAST_YEAR] : [teamNameIn(team, year), year],
    }))];
    const current = Math.max(0, options.findIndex((option) => option.id === value));

    useEffect(() => {
        if (!open) return;
        const onPointerDown = (event) => { if (!root.current?.contains(event.target)) setOpen(false); };
        const onKeyDown = (event) => { if (event.key === "Escape") setOpen(false); };
        document.addEventListener("pointerdown", onPointerDown);
        document.addEventListener("keydown", onKeyDown);
        return () => {
            document.removeEventListener("pointerdown", onPointerDown);
            document.removeEventListener("keydown", onKeyDown);
        };
    }, [open]);

    const face = (option) => (
        <>
            <i>{option.logo ? <TeamLogo team={option.logo[0]} year={option.logo[1]} /> : "?"}</i>
            <span>{option.name}</span>
        </>
    );
    // 방향키로는 목록을 열지 않고도 앞뒤 팀으로 바꿀 수 있다.
    const onKeyDown = (event) => {
        if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
        event.preventDefault();
        onChange(options[(current + (event.key === "ArrowDown" ? 1 : options.length - 1)) % options.length].id);
    };

    return (
        <div className="lineup-select" ref={root}>
            <button type="button" aria-haspopup="listbox" aria-expanded={open} aria-labelledby="lineup-team-label" onClick={() => setOpen(!open)} onKeyDown={onKeyDown}>
                {face(options[current])}<Chevron />
            </button>
            {open && (
                <ul role="listbox" aria-labelledby="lineup-team-label">
                    {options.map((option, index) => (
                        <li key={option.id} role="option" aria-selected={index === current} className={index === current ? "is-active" : ""}
                            onClick={() => { onChange(option.id); setOpen(false); }}>{face(option)}</li>
                    ))}
                </ul>
            )}
        </div>
    );
};

// 다음 문제(한국 시간 자정)까지 남은 시간
const DailyCountdown = () => {
    const left = () => DAY_MS - (Date.now() + KST_OFFSET_MS) % DAY_MS;
    const [remaining, setRemaining] = useState(left);
    useEffect(() => {
        const timer = setInterval(() => setRemaining(left()), 1000);
        return () => clearInterval(timer);
    }, []);
    const seconds = Math.floor(remaining / 1000);
    return <b>{[Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60].map((part) => String(part).padStart(2, "0")).join(":")}</b>;
};

const Setup = ({ settings, setSettings, onStart, onDaily, onStats, onOptions, loading, error }) => {
    // 오늘의 문제를 이미 끝냈는지(이 브라우저 기준)
    const dailyDone = Boolean(loadProgress(`daily|${todayInKorea()}`, DAILY_PROGRESS_KEY)?.answer);
    const teams = TEAMS.filter((team) => teamNameIn(team, settings.year));
    const change = (patch) => {
        const next = { ...settings, ...patch };
        // 연도를 바꿨을 때 그해에 없던 팀이면 랜덤으로 되돌린다.
        const team = TEAMS.find(([id]) => id === next.team);
        if (team && !teamNameIn(team, next.year)) next.team = "random";
        setSettings(next);
    };
    return (
        <section className="lineup-card lineup-setup">
            <div className="lineup-title">
                <h1 className="font-family-kbo">라인업 맞추기</h1>
                <span>
                    <button type="button" className="lineup-text-button" onClick={onStats}>내 통계</button>
                    <button type="button" className="lineup-text-button lineup-icon-button" aria-label="설정" title="설정" onClick={onOptions}><Gear /></button>
                </span>
            </div>
            <p className="lineup-lead">정규시즌 한 경기의 선발 라인업을 맞추어 보세요!</p>
            <button type="button" className="lineup-daily" onClick={onDaily}>
                <span><strong>오늘의 라인업 맞추기</strong><small>{dailyDone ? "오늘 문제를 풀었어요 · 결과 보기" : "하루에 한 문제 · 난이도 보통"}</small></span>
                <b aria-hidden="true">→</b>
            </button>
            <h2 className="lineup-section-title">자유 플레이</h2>
            <div className="lineup-fields">
                <label>
                    <span>연도</span>
                    <select value={settings.year} onChange={(e) => change({ year: e.target.value === "random" ? "random" : Number(e.target.value) })}>
                        <option value="random">랜덤</option>
                        {YEARS.map((year) => <option key={year} value={year}>{year}</option>)}
                    </select>
                </label>
                <div>
                    <span id="lineup-team-label">팀</span>
                    <TeamSelect value={settings.team} year={settings.year} teams={teams} onChange={(team) => change({ team })} />
                </div>
            </div>
            <div className="lineup-difficulties" role="radiogroup" aria-label="난이도">
                {DIFFICULTIES.map(([id, name], level) => (
                    <button key={id} type="button" role="radio" aria-checked={settings.difficulty === id}
                        className={`is-${id}${settings.difficulty === id ? " is-active" : ""}`} onClick={() => change({ difficulty: id })}>
                        <strong>{name}</strong>
                        {/* 난이도 단계를 막대 수로 보여준다. */}
                        <span aria-hidden="true">{DIFFICULTIES.map(([step], index) => <i key={step} className={index <= level ? "is-on" : ""} />)}</span>
                    </button>
                ))}
            </div>
            {/* 고른 난이도의 설명 */}
            <p className={`lineup-difficulty-note is-${settings.difficulty}`} aria-live="polite">{DIFFICULTIES.find(([id]) => id === settings.difficulty)[2]}</p>
            {error && <p className="lineup-error" role="alert">{error}</p>}
            <button type="button" className="lineup-primary" onClick={onStart} disabled={loading}>{loading ? "경기 고르는 중…" : "게임 시작"}</button>
        </section>
    );
};

// 이름을 입력해 선수를 고른다. options가 있으면(쉬움·보통) 그 후보 안에서, 없으면 그해(year) 1군에 출전한 선수 중에서 찾는다.
// initialText·onCancel: 이미 넣은 선수를 고칠 때 그 이름을 채운 채로 열고, 고르지 않고 나가면 원래대로 되돌린다.
const PlayerSearch = ({ slot, year, label, placeholder, options, hint, initialText = "", onCancel, onPick }) => {
    const [text, setText] = useState(initialText);
    const [open, setOpen] = useState(Boolean(initialText));
    const input = useRef(null);
    useEffect(() => {
        if (!initialText) return;
        // 바로 덮어쓸 수 있게 이름 전체를 선택해 둔다.
        input.current.focus({ preventScroll: true });
        input.current.select();
    }, []);
    // 휴대폰 키보드는 Enter와 함께 포커스를 먼저 빼기도 해서, 등록이 처리될 틈을 두고 되돌린다.
    const onBlur = () => {
        setOpen(false);
        if (onCancel) setTimeout(onCancel, 150);
    };
    // null = 서버 응답을 기다리는 중
    const [found, setFound] = useState(null);
    const [active, setActive] = useState(0);
    const latest = useRef(0);
    // 결과가 오기 전에 Enter를 눌렀는지
    const enterPending = useRef(false);
    // 응답을 기다리는 사이 판이 바뀌어도 최신 onPick을 부른다.
    const pickLatest = useRef(onPick);
    pickLatest.current = onPick;
    const keyword = text.trim();
    const local = Boolean(options);

    useEffect(() => {
        if (local) return;
        const id = ++latest.current;
        enterPending.current = false;
        setFound(null);
        if (!keyword) return;
        const timer = setTimeout(async () => {
            try {
                const { data } = await axios.post("/api/lineup/search.php", { keyword, year });
                // 늦게 도착한 이전 검색어의 결과는 버린다.
                if (id !== latest.current) return;
                const results = data.list || [];
                if (enterPending.current && results.length) pickLatest.current(results[0]);
                else setFound(results);
            } catch {
                if (id === latest.current) setFound([]);
            }
        }, SEARCH_DEBOUNCE_MS);
        return () => clearTimeout(timer);
    }, [keyword, local, year]);

    // 지금 검색어에 맞는 선수들(null = 검색어가 없거나 서버 응답을 기다리는 중). 목록은 입력창에 포커스가 있을 때만 펼친다.
    // 후보 안에서 찾을 때도 서버 검색처럼 풀네임·개명 전 이름·별명을 함께 보고, 띄어쓰기는 무시한다.
    const compact = (value) => value.replace(/\s+/g, "");
    const matchesKeyword = (player) => [player.name, ...(player.aliases || [])].some((name) => compact(name).includes(compact(keyword)));
    const matches = !keyword ? null : local ? options.filter(matchesKeyword) : found;
    const list = open ? matches : null;

    // Enter: 목록에서 고른(기본은 맨 위) 선수를 등록한다. 결과를 기다리는 중이면 도착하는 대로 등록한다.
    const pickActive = () => {
        if (matches?.length) onPick(matches[Math.min(active, matches.length - 1)]);
        else if (!local && keyword && found === null) enterPending.current = true;
    };
    const onKeyDown = (event) => {
        // 한글 조합 중의 키는 글자 확정에 쓰인다. 이때의 Enter는 onKeyUp에서 처리한다.
        if (event.nativeEvent.isComposing || event.keyCode === 229) return;
        if (event.key === "Enter") {
            event.preventDefault();
            pickActive();
        } else if (list?.length && (event.key === "ArrowDown" || event.key === "ArrowUp")) {
            event.preventDefault();
            setActive((active + (event.key === "ArrowDown" ? 1 : list.length - 1)) % list.length);
        } else if (event.key === "Escape") {
            setOpen(false);
            onCancel?.();
        }
    };
    // 한글을 조합하던 중에 누른 Enter는 keydown이 입력기 몫이라 여기서 받는다. 조합이 끝난 뒤라 글자가 다음 칸으로 넘어가지 않는다.
    // keydown에서 이미 등록했다면 이 입력창은 사라졌고, 포커스가 옮겨 간 빈 입력창에서는 고를 목록이 없어 아무 일도 없다.
    const onKeyUp = (event) => { if (event.key === "Enter") pickActive(); };
    // 휴대폰 키보드는 Enter(이동·완료) 키를 키 이벤트로 알려주지 않는 경우가 많다. 그때도 폼 제출은 일어나므로 여기서 받는다.
    // 같은 Enter가 키 이벤트로도 들어와 두 번 불려도, 한 칸에는 한 번만 등록된다.
    const onSubmit = (event) => {
        event.preventDefault();
        pickActive();
    };

    return (
        <form className="lineup-search" onSubmit={onSubmit}>
            <input type="text" value={text} placeholder={placeholder} aria-label={label} autoComplete="off" enterKeyHint="next" data-slot-input={slot}
                onChange={(e) => { setText(e.target.value); setOpen(true); setActive(0); }} onKeyDown={onKeyDown} onKeyUp={onKeyUp} onBlur={onBlur} ref={input} />
            {list && (
                <ul className="lineup-search-list">
                    {list.length === 0 && <li className="is-empty">{local ? "후보에 없는 선수입니다." : "검색 결과가 없습니다."}</li>}
                    {list.map((player, index) => (
                        // mousedown에서 포커스가 빠지면 목록이 닫혀 클릭이 사라진다.
                        <li key={player.id} className={index === active ? "is-active" : ""} onMouseDown={(e) => e.preventDefault()}
                            onClick={(e) => { e.stopPropagation(); onPick(player); }}>
                            <b>{player.name}</b>
                            <small>{[player.birthYear && `${player.birthYear}년생`, player.pos].filter(Boolean).join(" · ")}</small>
                            {hint(player) && <em className={`is-${hint(player)[0]}`}>{hint(player)[1]}</em>}
                        </li>
                    ))}
                </ul>
            )}
        </form>
    );
};

// 한 타순의 그날 타석 결과(땅볼·1루타·삼진 …)
const Records = ({ records }) => records.length === 0
    ? <p className="lineup-records-empty">타석 기록이 없습니다.</p>
    : (
        <ol className="lineup-record-list">
            {records.map((record, index) => <li key={index}><b>{index + 1}</b><span>{record}</span></li>)}
        </ol>
    );

// 그해의 팀 작은 로고(없으면 큰 로고). 옆에 팀명을 함께 적으므로 장식으로만 쓴다.
const TeamLogo = ({ team, year }) => {
    const code = teamCodeByName(team, year);
    const logo = teamSmallLogoByCode(code) || teamLogoByCode(code);
    return logo ? <img src={logo} alt="" draggable="false" /> : null;
};

// 오늘의 라인업을 맞힌 뒤의 공유 버튼: X, 카카오톡, 기기의 공유 창(없으면 복사)
const DailyShare = ({ number, attempts, solvedIn, history }) => {
    const [copied, setCopied] = useState(false);
    // 제출이 많으면 글이 너무 길어지므로 마지막 몇 번만 그린다.
    const rows = history.slice(-6).map((row) => row.map((result) => SHARE_SQUARES[result] ?? "⬛").join(""));
    const text = [
        `오늘의 라인업 맞추기 #${number}`,
        `${attempts}번 만에 성공${solvedIn !== null ? ` · ${formatDuration(solvedIn)}` : ""}`,
        ...(history.length > rows.length ? [`… (앞의 ${history.length - rows.length}번 생략)`] : []),
        ...rows,
    ].join("\n");

    useEffect(() => {
        const Kakao = window.Kakao;
        if (!Kakao) return;
        // 다른 페이지(크보들·빙고)에서 초기화해 둔 것이 있으면 정리하고 다시 연결한다.
        Kakao.cleanup();
        Kakao.init(KAKAO_KEY);
        Kakao.Share.createDefaultButton({
            container: "#lineup-kakao-share",
            objectType: "text",
            text,
            link: { webUrl: DAILY_URL, mobileWebUrl: DAILY_URL },
            buttonTitle: "나도 풀어보기",
        });
    }, [text]);
    useEffect(() => {
        if (!copied) return undefined;
        const timer = setTimeout(() => setCopied(false), 2000);
        return () => clearTimeout(timer);
    }, [copied]);

    const share = async () => {
        const full = `${text}\n${DAILY_URL}`;
        try {
            if (navigator.share) await navigator.share({ text: full });
            else {
                await navigator.clipboard.writeText(full);
                setCopied(true);
            }
        } catch {
            // 공유 창을 그냥 닫았거나 복사 권한이 없는 경우
        }
    };

    return (
        <div className="lineup-share">
            <TwitterShareButton url={DAILY_URL} title={text} aria-label="X에 공유"><XIcon size={34} round /></TwitterShareButton>
            <button type="button" id="lineup-kakao-share" className="lineup-share-kakao" aria-label="카카오톡으로 공유">
                <img src="https://developers.kakao.com/assets/img/about/logos/kakaotalksharing/kakaotalk_sharing_btn_medium.png" alt="" />
            </button>
            <button type="button" className="lineup-secondary" onClick={share}>
                {/* 공유 아이콘: 상자에서 위로 나가는 화살표 */}
                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M12 15V3M8 7l4-4 4 4M5 11v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8" />
                </svg>
                {copied ? "복사했어요" : "공유하기"}
            </button>
        </div>
    );
};

// 오늘의 라인업을 끝낸 뒤의 결과 창: 내 기록, 공유, 오늘의 랭킹, 다른 게임으로 가는 버튼
const DailyResultModal = ({ daily, difficulty, solved, attempts, solvedIn, correctCount, history, puzzleStats, onClose }) => {
    // undefined = 불러오는 중, null = 불러오지 못함
    const [ranking, setRanking] = useState(undefined);
    const loadRanking = () => axios.get("/api/lineup/ranking.php", { params: { client: clientId() } })
        .then(({ data }) => setRanking(data.ranking ?? null))
        .catch(() => setRanking(null));
    useEffect(() => {
        loadRanking();
        const onKeyDown = (event) => { if (event.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, [onClose]);
    // 닉네임은 크보빙고와 함께 쓴다(같은 저장소, 같은 규칙: 2~12자).
    const changeNickname = async () => {
        const nickname = window.prompt("새 닉네임을 입력하세요. (2~12자)", ranking?.nickname || "")?.trim();
        if (nickname === undefined) return;
        if (nickname.length < 2 || nickname.length > 12) {
            window.alert("닉네임은 2자 이상 12자 이하로 입력해 주세요.");
            return;
        }
        try {
            const { data } = await axios.post("/api/kbobingo/set_nickname.php", { uuid: clientUuid(), nickname });
            if (data?.code !== 200) throw new Error(data?.message);
            await loadRanking();
        } catch {
            window.alert("닉네임을 바꾸지 못했습니다. 잠시 후 다시 시도해 주세요.");
        }
    };
    // 참가자 전체에서 내 순위가 위에서 몇 %인지. 크보빙고 결과 창과 같은 규칙(backend/api/kbobingo/get_stat.php)으로 적는다:
    // 2% 초과는 정수, 0.1% 초과는 소수 한 자리, 0.01% 초과는 소수 두 자리, 그보다 작으면 0.01.
    const topPercent = (() => {
        if (!ranking?.me || !(ranking.played > 0)) return null;
        const percentage = 100 * ranking.me.rank / ranking.played;
        if (percentage > 2) return percentage.toFixed(0);
        if (percentage > 0.1) return percentage.toFixed(1);
        if (percentage > 0.01) return percentage.toFixed(2);
        return "0.01";
    })();
    const rankRow = (row, mine) => (
        <tr key={row.rank} className={mine ? "is-me" : ""}>
            <th scope="row">{row.rank}</th><td className="lineup-ranking-name">{row.nickname}{mine && <em>나</em>}</td><td>{row.attempts}회</td><td>{row.milliseconds === null ? "-" : formatDuration(row.milliseconds)}</td>
        </tr>
    );
    return (
        <div className="lineup-modal" role="dialog" aria-modal="true" aria-labelledby="lineup-result-title" onClick={onClose}>
            <div className="lineup-modal-panel lineup-result" onClick={(event) => event.stopPropagation()}>
                <button type="button" className="lineup-modal-close" aria-label="닫기" onClick={onClose}>×</button>
                <p className="lineup-result-label">오늘의 라인업 #{daily.number}</p>
                <h2 id="lineup-result-title">{solved ? "라인업을 완성했습니다!" : "정답을 공개했습니다"}</h2>

                <dl className="lineup-result-figures">
                    {solved
                        ? <><div><dt>제출</dt><dd>{attempts}회</dd></div><div><dt>걸린 시간</dt><dd>{solvedIn === null ? "-" : formatDuration(solvedIn)}</dd></div></>
                        : <div><dt>맞힌 타순</dt><dd>9명 중 {correctCount}명</dd></div>}
                    {solved && <div><dt>오늘의 순위</dt><dd>{topPercent === null ? "-" : `상위 ${topPercent}%`}</dd></div>}
                </dl>
                {/* 이 문제를 같은 난이도로 푼 사람들의 평균(자유 플레이로 푼 사람 포함) */}
                {puzzleStats?.averageAttempts != null && (
                    <p className="lineup-result-average">
                        <em className={`lineup-level is-${difficulty}`}>{DIFFICULTIES.find(([id]) => id === difficulty)[1]}</em>
                        평균 {puzzleStats.averageAttempts.toFixed(1)}회
                        {puzzleStats.averageMilliseconds != null && ` · 평균 ${formatDuration(puzzleStats.averageMilliseconds)}`}
                    </p>
                )}

                {solved && <DailyShare number={daily.number} attempts={attempts} solvedIn={solvedIn} history={history} />}

                <section className="lineup-ranking">
                    <h3>오늘의 랭킹{ranking && <span>정답자 {ranking.solved}명 · 참가 {ranking.played}명</span>}</h3>
                    {ranking === undefined && <p>랭킹을 불러오는 중…</p>}
                    {ranking === null && <p>랭킹을 불러오지 못했습니다.</p>}
                    {ranking && ranking.top.length === 0 && <p>아직 맞힌 사람이 없습니다.</p>}
                    {ranking && ranking.top.length > 0 && (
                        <table>
                            <thead><tr><th scope="col">순위</th><th scope="col">닉네임</th><th scope="col">제출</th><th scope="col">시간</th></tr></thead>
                            <tbody>
                                {ranking.top.map((row) => rankRow(row, row.me))}
                                {/* 10위 밖이면 내 순위를 따로 덧붙인다. */}
                                {ranking.me && ranking.me.rank > ranking.top.length && rankRow(ranking.me, true)}
                            </tbody>
                        </table>
                    )}
                    <small>제출 횟수가 적은 순, 같으면 빨리 푼 순입니다.</small>
                    {ranking && (
                        <p className="lineup-nickname">
                            내 닉네임 <b>{ranking.nickname}</b>
                            <button type="button" className="lineup-text-button" onClick={changeNickname}>닉네임 변경</button>
                        </p>
                    )}
                </section>

                <p className="lineup-daily-next">다음 문제까지 <DailyCountdown /></p>
                <div className="lineup-result-links">
                    <Link className="lineup-secondary" to="/kbodle">크보들 풀기</Link>
                    <Link className="lineup-secondary" to="/bingo">크보빙고 풀기</Link>
                </div>
            </div>
        </div>
    );
};

const Board = ({ puzzle, options, loading, onNext, onSetup, onStats, onOptions }) => {
    const { game, slots, candidates, difficulty } = puzzle;
    const typed = difficulty === "hard" || difficulty === "extreme";
    // 같은 문제(경기·팀·난이도)를 풀던 기록이 있으면 이어서 시작한다.
    const daily = puzzle.daily;
    const progressStorage = daily ? DAILY_PROGRESS_KEY : PROGRESS_KEY;
    const progressKey = daily ? `daily|${daily.date}` : `${game.code}|${game.side}|${difficulty}`;
    const saved = useRef(loadProgress(progressKey, progressStorage)).current;
    // 제출할 때마다의 채점 결과. 공유 글의 색 칸을 그리는 데 쓴다.
    const [history, setHistory] = useState(saved?.history ?? []);
    const [picks, setPicks] = useState(() => saved?.picks ?? Array(9).fill(null));
    // 마지막 채점 결과. 칸의 선수를 바꾸면 그 칸의 결과는 지운다.
    const [results, setResults] = useState(() => saved?.results ?? Array(9).fill(null));
    const [attempts, setAttempts] = useState(saved?.attempts ?? 0);
    const [answer, setAnswer] = useState(saved?.answer ?? null);
    const [solved, setSolved] = useState(saved?.solved ?? false);
    // 맞히는 데 걸린 시간(밀리초)과, 서버가 알려준 이 문제의 통계. 끝난 뒤에 채워진다.
    const [solvedIn, setSolvedIn] = useState(saved?.solvedIn ?? null);
    const [puzzleStats, setPuzzleStats] = useState(saved?.puzzleStats ?? null);
    // 자랑용 캡처를 위해 끝난 뒤 정답 이름을 가릴 수 있다.
    const [hideNames, setHideNames] = useState(false);
    // 이 문제를 화면에 띄워 둔 시간(밀리초). 새로고침 전까지 쓴 시간에 이어서 잰다.
    const startedAt = useRef(Date.now());
    const elapsed = () => (saved?.elapsed ?? 0) + Date.now() - startedAt.current;
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState("");
    // 탭으로 고른 선수: 이어서 칸을 누르면 그 칸에 들어간다. { player, from }
    const [selected, setSelected] = useState(null);
    const [ghost, setGhost] = useState(null);
    const [hover, setHover] = useState(null);
    // 누른 타순(칸 번호). 구단 색으로 강조하고, 넓은 화면에서는 오른쪽 패널에 그 타순의 「오늘의 기록」을 보여준다.
    // 후보를 누르면 이 타순에 들어간다. 이어서 푸는 판에서는 이미 채운 칸을 덮어쓰지 않도록 첫 빈 타순에서 시작한다.
    const [activeOrder, setActiveOrder] = useState(() => Math.max(0, picks.findIndex((pick) => !pick)));
    // 오늘의 라인업: 색종이 효과와 결과 창
    const [celebrating, setCelebrating] = useState(false);
    const [showResult, setShowResult] = useState(false);
    // 화면 아래에 잠깐 뜨는 안내
    const [toast, setToast] = useState("");
    useEffect(() => {
        if (!toast) return undefined;
        const timer = setTimeout(() => setToast(""), TOAST_MS);
        return () => clearTimeout(timer);
    }, [toast]);
    useEffect(() => {
        if (!celebrating) return undefined;
        const timer = setTimeout(() => setShowResult(true), CELEBRATION_MS);
        return () => clearTimeout(timer);
    }, [celebrating]);
    // 이미 넣은 선수를 고치는 중인 타순
    const [editing, setEditing] = useState(null);
    // 좁은 화면에서 행 아래에 기록을 펼쳐 둔 타순
    const [openOrder, setOpenOrder] = useState(null);
    // 한 번 더 묻는 중인 동작: "reveal"(정답 보기) 또는 "submit"(제출)
    const [confirm, setConfirm] = useState(null);
    const drag = useRef(null);
    const rows = useRef(null);
    // 한 번의 키 입력에 처리기가 겹쳐 불려도 예전 상태로 덮어쓰지 않도록, 가장 최근의 칸 상태를 따로 들고 있는다.
    const latestPicks = useRef(picks);
    latestPicks.current = picks;
    // 이름을 입력해 마지막으로 고른 시각. 한글 입력기는 Enter를 두 번 보내기도 해서, 직후의 Enter는 제출로 보지 않는다.
    const lastPickAt = useRef(0);

    useEffect(() => {
        if (!confirm) return;
        const onKeyDown = (event) => { if (event.key === "Escape") setConfirm(null); };
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, [confirm]);

    const finished = answer !== null;
    const pool = candidates.filter((candidate) => !picks.some((pick) => pick?.id === candidate.id));
    // 지난 제출에서 알게 된 것. 칸의 선수를 바꾸면 그 칸의 채점 표시는 지워지므로, 선수별로 따로 기억해 둔다.
    // { [선수 id]: { absent: 선발이 아님, wrongSlots: 선발이지만 이 칸(들)은 아니었음 } }
    const [memory, setMemory] = useState(saved?.memory ?? {});
    // 진행 상황이 바뀔 때마다, 그리고 화면을 떠날 때(쓴 시간을 맞추기 위해) 저장한다.
    const persist = useRef(null);
    persist.current = () => saveProgress({ key: progressKey, picks, results, attempts, memory, answer, solved, solvedIn, puzzleStats, history, elapsed: elapsed() }, progressStorage);
    useEffect(() => persist.current(), [picks, results, attempts, memory, answer, solved, solvedIn, puzzleStats, history]);
    useEffect(() => {
        const onPageHide = () => persist.current();
        window.addEventListener("pagehide", onPageHide);
        return () => {
            window.removeEventListener("pagehide", onPageHide);
            // 여기는 화면 안에서 이 판을 떠날 때만 온다(뒤로가기, 다른 페이지로 이동, 건너뛰기). 새로고침은 pagehide로만 지나간다.
            // 다음 판이 이미 자기 진행 상황을 저장했으면 건드리지 않는다.
            if (!loadProgress(progressKey, progressStorage)) return;
            // 오늘의 라인업은 하루에 한 번이라 떠났다 돌아와도 이어져야 하고, 자유 플레이는 떠나면 처음부터 다시 푼다.
            if (daily) persist.current();
            else localStorage.removeItem(progressStorage);
        };
    }, []);
    // 이미 답을 아는 자리: 선발이 아닌 선수는 어디에 넣어도 회색, 선발인 선수는 틀렸던 칸에 다시 넣으면 노란색으로 바로 보여준다.
    const knownResult = (player, index) => {
        const known = player && memory[player.id];
        return !known ? null : known.absent ? "absent" : known.wrongSlots.includes(index) ? "present" : null;
    };
    const shownResult = (index) => results[index] ?? knownResult(picks[index], index);
    const wrongOrders = (player) => (memory[player.id]?.wrongSlots || []).map((index) => slots[index].order).sort((a, b) => a - b).join("·");
    // 입력 목록에서 선수 옆에 붙이는 안내 [색, 문구]
    const searchHint = (player) => {
        const known = memory[player.id];
        if (!known) return null;
        return known.absent ? ["absent", "선발 아님"] : ["present", `${wrongOrders(player)}번 아님`];
    };
    // 채점해 볼 새 정보가 있을 때만 제출할 수 있다.
    const canCheck = !finished && !checking && picks.some((pick, index) => pick && shownResult(index) === null);

    // from·to: 칸 번호(0~8) 또는 "pool"
    const move = (player, from, to) => {
        setSelected(null);
        if (to === null || to === from || results[to] === "correct") return;
        const next = [...latestPicks.current];
        // 이미 다른 칸에 넣어 둔 선수를 또 넣으면 원래 칸에서 빼고 옮긴다. 맞혀서 고정된 칸의 선수는 다시 넣을 수 없다.
        const duplicate = from === "pool" && to !== "pool" ? next.findIndex((pick, index) => index !== to && pick?.id === player.id) : -1;
        if (duplicate >= 0 && results[duplicate] === "correct") {
            setToast("이미 라인업에 있는 선수에요!");
            return;
        }
        if (duplicate >= 0) next[duplicate] = null;
        if (to === "pool") {
            next[from] = null;
        } else {
            // 칸에서 칸으로 옮기면 서로 자리를 바꾼다.
            if (from !== "pool") next[from] = next[to];
            next[to] = player;
        }
        latestPicks.current = next;
        setPicks(next);
        setResults((previous) => previous.map((result, index) => [from, to, duplicate].includes(index) && result !== "correct" ? null : result));
    };

    // 이름을 입력해 고르면 다음 빈 타순의 입력창으로 넘어간다. 아래에 빈 타순이 없으면 맨 위의 빈 타순으로, 다 찼으면 포커스를 푼다.
    const pickBySearch = (player, index) => {
        // 같은 Enter가 두 번 들어와도 한 번만 넣는다. 이미 찬 칸은 고치는 중일 때만 바꾼다.
        const existing = latestPicks.current[index];
        if (existing && editing !== index) return;
        setEditing(null);
        if (existing?.id !== player.id) move(typed ? { id: player.id, name: player.name } : player, "pool", index);
        // 맞혀서 고정된 선수를 다시 고른 경우처럼 들어가지 않았으면 그 자리에 머문다.
        if (!latestPicks.current[index]) return;
        lastPickAt.current = Date.now();
        const filled = latestPicks.current;
        const empty = filled.map((_, slot) => slot).filter((slot) => !filled[slot]);
        const next = empty.find((slot) => slot > index) ?? empty[0];
        if (next === undefined) document.activeElement?.blur();
        else focusSlot(next);
    };
    // 방금 다른 칸에서 빠진 선수의 자리처럼 입력창이 아직 그려지지 않은 칸은, 다시 그린 뒤에 포커스를 준다.
    const pendingFocus = useRef(null);
    // 좁은 화면에서는 키보드가 화면 아래쪽을 가리므로, 포커스가 옮겨 간 행을 화면 위쪽으로 끌어올린다.
    const focusInput = (input) => {
        input.focus({ preventScroll: true });
        if (window.matchMedia("(max-width: 640px)").matches) input.closest(".lineup-row").scrollIntoView({ block: "start", behavior: "smooth" });
    };
    const focusSlot = (slot) => {
        const input = rows.current?.querySelector(`[data-slot-input="${slot}"]`);
        if (input) focusInput(input);
        else pendingFocus.current = slot;
    };
    // 시작하자마자 1번 타자 입력창으로 간다(설정에서 켰거나, 이름을 직접 입력하는 난이도일 때).
    useEffect(() => {
        // 새 경기는 항상 맨 위(경기 정보)부터 보여준다. 건너뛰기·다음 경기로 넘어왔을 때 아래쪽에 머물러 있지 않게 한다.
        window.scrollTo({ top: 0 });
        // 포커스를 주더라도 화면이 입력창 쪽으로 따라 내려가지 않게 한다. 이어서 푸는 판에서는 첫 빈 칸으로 간다.
        if (!finished && (options.autoFocus || typed)) rows.current?.querySelector("[data-slot-input]")?.focus({ preventScroll: true });
    }, []);
    useEffect(() => {
        if (pendingFocus.current === null) return;
        const input = rows.current?.querySelector(`[data-slot-input="${pendingFocus.current}"]`);
        if (input) focusInput(input);
        pendingFocus.current = null;
    }, [picks]);

    // 포커스가 들어온 행도 누른 것처럼 강조하고 「오늘의 기록」을 맞춘다(이름 입력 후 다음 칸으로 넘어갈 때 등).
    // 좁은 화면에서 기록을 펼쳐 둔 채 입력창을 옮겨 다니면 펼친 기록도 따라간다.
    const focusRow = (event, index) => {
        setActiveOrder(index);
        if (event.target.matches("input")) setOpenOrder((open) => (open === null ? null : index));
    };

    const tap = (player, from) => {
        // 후보를 누르면 강조된 타순에 바로 넣는다. 그 칸에 이미 선수가 있으면(제출해서 틀린 칸 등) 바꿔 넣는다.
        // 강조된 타순이 맞혀서 고정된 칸이면 첫 빈 타순에 넣는다.
        const target = results[activeOrder] !== "correct" ? activeOrder : latestPicks.current.findIndex((pick) => !pick);
        if (from === "pool" && !selected && target >= 0) {
            move(player, "pool", target);
            // 이름 입력과 같은 규칙으로 다음 빈 타순(아래에 없으면 맨 위의 빈 타순)으로 강조를 옮긴다. 빈 타순이 없으면 그대로 둔다.
            const filled = latestPicks.current;
            const empty = filled.map((_, slot) => slot).filter((slot) => !filled[slot]);
            const next = empty.find((slot) => slot > target) ?? empty[0];
            setActiveOrder(next ?? target);
            return;
        }
        // 칸에 넣어 둔 이름을 누르면 그 이름이 채워진 입력창으로 바뀌어 고칠 수 있다. 끌어서 옮기는 것은 그대로 된다.
        if (!selected && from !== "pool") {
            setEditing(from);
            return;
        }
        if (!selected) setSelected({ player, from });
        else if (selected.player.id === player.id) setSelected(null);
        else if (from === "pool") setSelected({ player, from });
        else move(selected.player, selected.from, from);
    };

    const dropTarget = (x, y) => {
        const key = document.elementFromPoint(x, y)?.closest("[data-drop]")?.dataset.drop;
        return key === undefined ? null : key === "pool" ? "pool" : Number(key);
    };
    const endDrag = () => {
        drag.current = null;
        setGhost(null);
        setHover(null);
    };
    // 마우스·터치 공통 드래그. 움직이지 않고 떼면 탭으로 처리한다.
    const chipHandlers = (player, from) => ({
        onPointerDown: (event) => {
            if (event.button > 0) return;
            drag.current = { x: event.clientX, y: event.clientY, moved: false };
            event.currentTarget.setPointerCapture(event.pointerId);
        },
        onPointerMove: (event) => {
            const state = drag.current;
            if (!state) return;
            if (!state.moved && Math.hypot(event.clientX - state.x, event.clientY - state.y) < DRAG_THRESHOLD_PX) return;
            state.moved = true;
            setGhost({ name: player.name, x: event.clientX, y: event.clientY });
            setHover(dropTarget(event.clientX, event.clientY));
        },
        onPointerUp: (event) => {
            const state = drag.current;
            if (!state) return;
            endDrag();
            if (state.moved) {
                const to = dropTarget(event.clientX, event.clientY);
                move(player, from, to);
                // 끌어다 놓은 타순도 누른 것처럼 강조하고 기록을 맞춘다.
                if (typeof to === "number") setActiveOrder(to);
            } else {
                tap(player, from);
            }
        },
        onPointerCancel: endDrag,
        // 탭은 pointerup에서 끝냈으므로 칸·후보 영역의 클릭 처리로 넘기지 않는다. 키보드(Enter·Space)로 누른 경우만 여기서 처리한다.
        onClick: (event) => {
            event.stopPropagation();
            if (event.detail === 0) tap(player, from);
        },
    });

    const check = async (reveal) => {
        setChecking(true);
        setError("");
        setSelected(null);
        try {
            const submitted = attempts + (reveal ? 0 : 1);
            const spent = Math.round(elapsed());
            const { data } = await axios.post("/api/lineup/check.php", {
                game: game.code, team: game.team, picks: picks.map((pick) => pick?.id ?? null), reveal,
                // 판이 끝났을 때 문제별 통계에 남길 값
                difficulty, client: clientId(), attempts: submitted, milliseconds: spent, daily: Boolean(daily),
            });
            setAttempts(submitted);
            if (!reveal) setHistory([...history, data.results]);
            setResults(data.results);
            setMemory((previous) => {
                const next = { ...previous };
                data.results.forEach((result, index) => {
                    const id = picks[index]?.id;
                    if (!id || !result || result === "correct") return;
                    // 이름은 직접 입력하는 난이도에서 확인된 선수 목록을 그릴 때 쓴다.
                    const known = { absent: false, wrongSlots: [], ...next[id], name: picks[index].name };
                    next[id] = result === "absent" ? { ...known, absent: true } : { ...known, wrongSlots: [...new Set([...known.wrongSlots, index])] };
                });
                return next;
            });
            if (data.answer) {
                setAnswer(data.answer);
                setSolved(data.solved);
                setSolvedIn(data.solved ? spent : null);
                setPuzzleStats(data.stats ?? null);
                recordResult(difficulty, data.solved, submitted, data.solved ? spent : null);
                // 오늘의 라인업: 맞혔으면 색종이를 한 번 터뜨린 뒤에, 정답을 봤으면 바로 결과 창을 띄운다.
                if (daily && data.solved) setCelebrating(true);
                else if (daily) setShowResult(true);
            }
        } catch {
            setError("채점하지 못했습니다. 잠시 후 다시 시도해 주세요.");
        } finally {
            setChecking(false);
        }
    };

    const submit = () => (options.confirmSubmit ? setConfirm("submit") : check(false));
    // 9칸이 다 찬 상태에서 Enter를 누르면 제출한다. 입력창·버튼 등에 포커스가 있을 때는 그쪽 동작이 먼저다(선수 칩은 예외).
    const submitByEnter = useRef(null);
    submitByEnter.current = (event) => {
        if (event.key !== "Enter" || event.repeat || event.isComposing || confirm || !canCheck || picks.some((pick) => !pick)) return;
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest("input, textarea, select, a, [role=dialog], button:not(.lineup-chip)")) return;
        if (Date.now() - lastPickAt.current < 400) return;
        event.preventDefault();
        submit();
    };
    useEffect(() => {
        const onKeyDown = (event) => submitByEnter.current(event);
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, []);

    // 한 번도 제출하지 않고 건너뛴 경기는 통계에 넣지 않는다. 제출한 뒤에 건너뛰면 못 맞힌 한 판으로 센다.
    const skip = () => {
        if (attempts > 0) {
            recordResult(difficulty, false, attempts);
            // 서버의 문제별 통계에도 못 맞힌 한 판으로 남긴다. 실패해도 다음 경기로 넘어가는 데는 지장이 없다.
            axios.post("/api/lineup/check.php", { game: game.code, team: game.team, picks: Array(9).fill(null), abandon: true, difficulty, client: clientId(), attempts }).catch(() => {});
        }
        onNext();
    };

    const correctCount = results.filter((result) => result === "correct").length;
    const year = game.date.slice(0, 4);
    const [primary, secondary] = TEAM_COLORS[teamCodeByName(game.team, year)] || DEFAULT_COLORS;
    // 빈 칸의 안내: 선수를 끌고 있거나 골라 둔 상태면 넣을 자리임을, 아니면 그 난이도에서 넣는 방법을 알려준다.
    const placeholder = ghost ? "여기에 놓기" : selected ? "여기에 넣기" : typed ? "이름 입력" : "입력 또는 후보 선택";
    // 후보에 동명이인이 있으면 출생연도가 함께 온다.
    // 직접 입력하는 난이도의 후보 영역: 지금까지 제출해서 노랑(선발이지만 그 타순은 아님)·회색(선발 아님)으로 확인된 선수들.
    // 제자리를 찾아 초록이 된 선수는 빠진다. 노랑을 앞에 둔다.
    const solvedIds = new Set(picks.filter((pick, index) => pick && results[index] === "correct").map((pick) => pick.id));
    const knownPlayers = Object.entries(memory)
        .filter(([id, known]) => known.name && !solvedIds.has(Number(id)))
        .map(([id, known]) => ({ id: Number(id), ...known }))
        .sort((a, b) => a.absent - b.absent || a.name.localeCompare(b.name, "ko"));
    // 눌러서 안내를 펼쳐 둔 선수(터치 화면에는 호버가 없다)
    const [openTip, setOpenTip] = useState(null);
    const wrongTip = (player) => (memory[player.id]?.wrongSlots.length ? `${wrongOrders(player)}번 타순 아님` : undefined);
    // 선발로 확인된 선수는 노란 점을 붙이고, 후보 영역에서는 틀렸던 타순도 함께 적는다.
    const chipLabel = (player, inPool) => {
        // 제자리를 찾아 맞힌 선수에게는 더 알려줄 것이 없다.
        const solvedPlayer = picks.some((pick, index) => pick?.id === player.id && results[index] === "correct");
        const known = finished || solvedPlayer ? null : memory[player.id];
        return (
            <>
                {known && !known.absent && <i className="lineup-known" />}
                {player.name}
                {player.birthYear && <small>{String(player.birthYear).slice(2)}년생</small>}
                {inPool && known?.wrongSlots.length > 0 && <small>{wrongOrders(player)}번 ✕</small>}
            </>
        );
    };

    return (
        <section className="lineup-card lineup-board" style={{ "--team": primary, "--team-2": secondary }}>
            <header className="lineup-head">
                <button type="button" className="lineup-text-button lineup-icon-button lineup-options-open" aria-label="설정" title="설정" onClick={onOptions}><Gear /></button>
                {daily && <p className="lineup-daily-label">오늘의 라인업 #{daily.number}</p>}
                <p className="lineup-meta">{formatDate(game.date)} · {game.stadium}{game.doubleheader ? ` · DH ${game.doubleheader}차전` : ""}<span>{DIFFICULTIES.find(([id]) => id === difficulty)[1]}</span></p>
                {/* 원정팀을 위, 홈팀을 아래에 둔다. */}
                <div className={`lineup-matchup${game.isHome ? " is-home" : ""}`}>
                    <div className="lineup-side is-own">
                        <i><TeamLogo team={game.team} year={year} /></i>
                        <div><h1><span>{teamFullName(game.team)}</span></h1><small>선발<span className="lineup-bar" />{game.starter || "-"}</small></div>
                        <strong>{game.teamScore}</strong>
                    </div>
                    <div className="lineup-side">
                        <i><TeamLogo team={game.opponent} year={year} /></i>
                        <div><b>{teamFullName(game.opponent)}</b><small>선발<span className="lineup-bar" />{game.opponentStarter || "-"}</small></div>
                        <strong>{game.opponentScore}</strong>
                    </div>
                </div>
            </header>

            <div className="lineup-body">
                <ol className={`lineup-rows${difficulty === "easy" ? " has-hands" : ""}`} ref={rows}>
                    {slots.map((slot, index) => {
                        const pick = picks[index];
                        const locked = results[index] === "correct";
                        const editingHere = !finished && !locked && Boolean(pick) && editing === index;
                        // 고치는 중에는 채점 색을 잠시 걷어 입력창처럼 보이게 한다.
                        const state = finished ? (locked ? "correct" : "missed") : editingHere ? null : shownResult(index);
                        const active = activeOrder === index;
                        // 익스트림은 끝나기 전까지 포지션과 타율을 가린다.
                        const shown = answer?.[index] ?? slot;
                        return (
                            <Fragment key={slot.order}>
                                <li className={`lineup-row${state ? ` is-${state}` : ""}${active ? " is-active" : ""}${hover === index && !locked ? " is-hover" : ""}`} data-drop={finished ? undefined : index}
                                    onClick={() => setActiveOrder(index)} onFocus={(event) => focusRow(event, index)}>
                                    <button type="button" className="lineup-order" aria-label={`${slot.order}번 타자`}>{slot.order}</button>
                                    {finished ? (
                                        <div className="lineup-slot is-final">
                                            {!hideNames && <span className="lineup-chip">{answer[index].name}</span>}
                                            {!hideNames && !locked && pick && <small>내 답: {pick.name}</small>}
                                            {/* 경기 화면을 잃지 않도록 새 탭에서 연다. */}
                                            {!hideNames && <Link className="lineup-profile-link" to={`/?pid=${answer[index].id}`} target="_blank" rel="noopener noreferrer" aria-label={`${answer[index].name} 프로필 (새 탭)`}>프로필<Chevron /></Link>}
                                        </div>
                                    ) : (
                                        <div className={`lineup-slot${pick && !editingHere ? "" : " is-open"}`}
                                            onClick={() => { if (selected && !pick) move(selected.player, selected.from, index); }}>
                                            {pick && !editingHere && (locked
                                                ? <span className="lineup-chip">{chipLabel(pick)}</span>
                                                : <button type="button" className={`lineup-chip is-movable${selected?.player.id === pick.id ? " is-selected" : ""}`} data-tip={solvedIds.has(pick.id) ? undefined : wrongTip(pick)} {...chipHandlers(pick, index)}>{chipLabel(pick)}</button>)}
                                            {pick && !locked && !editingHere && <button type="button" className="lineup-clear" aria-label={`${slot.order}번 타자 지우기`} onClick={() => move(pick, index, "pool")}>×</button>}
                                            {/* 고치는 중에는 지금 들어 있는 선수도 후보에 넣어, 그대로 Enter를 치면 유지되게 한다. */}
                                            {(!pick || editingHere) && <PlayerSearch key={editingHere ? "edit" : "new"} slot={index} year={year} label={`${slot.order}번 타자`} placeholder={placeholder}
                                                options={typed ? null : editingHere ? [pick, ...pool] : pool} initialText={editingHere ? pick.name : ""}
                                                onCancel={editingHere ? () => setEditing((current) => (current === index ? null : current)) : undefined}
                                                hint={searchHint} onPick={(player) => pickBySearch(player, index)} />}
                                        </div>
                                    )}
                                    {difficulty === "easy" && <span className="lineup-hand" title={BAT_NAMES[slot.bat]}>{slot.bat || ""}</span>}
                                    {/* 넓은 화면에서는 어떤 기록인지(타율·OPS) 숫자 앞에 적는다. 경기 전 기록이라 시즌 첫 경기면 값이 없어 「-」로 적는다. 익스트림은 끝날 때까지 가린다. */}
                                    <span className="lineup-avg">{(finished || difficulty !== "extreme") && <><small>{STAT_NAMES.find(([id]) => id === options.stat)[1]}</small>{shown[options.stat] ?? "-"}</>}</span>
                                    <span className="lineup-pos" title={POSITION_NAMES[shown.pos]}>{shown.pos || "?"}</span>
                                    <button type="button" className="lineup-records-toggle" aria-label={`${slot.order}번 타자의 오늘 기록 ${openOrder === index ? "접기" : "펼치기"}`} aria-expanded={openOrder === index}
                                        onClick={() => setOpenOrder(openOrder === index ? null : index)}><Chevron /></button>
                                </li>
                                {openOrder === index && <li className="lineup-row-records"><Records records={slot.records} /></li>}
                            </Fragment>
                        );
                    })}
                </ol>

                {/* 넓은 화면: 오른쪽 열에 선수 후보, 그 아래에 오늘의 기록. 스크롤해도 따라온다. 좁은 화면: 기록 패널은 숨고 후보만 표 아래에 온다. */}
                <div className="lineup-aside">
                    {!finished && typed && knownPlayers.length > 0 && (
                        <div className="lineup-pool is-known">
                            <strong className="lineup-pool-title">선수 후보</strong>
                            {knownPlayers.map((player) => (player.absent
                                ? <span key={player.id} className="lineup-chip is-out">{player.name}</span>
                                : (
                                    <button key={player.id} type="button" className={`lineup-chip${openTip === player.id ? " is-tip-open" : ""}`} data-tip={wrongTip(player)}
                                        onClick={() => setOpenTip(openTip === player.id ? null : player.id)}><i className="lineup-known" />{player.name}</button>
                                )))}
                        </div>
                    )}
                    {!finished && !typed && (
                        <div className={`lineup-pool${hover === "pool" ? " is-hover" : ""}`} data-drop="pool"
                            onClick={() => { if (selected && selected.from !== "pool") move(selected.player, selected.from, "pool"); }}>
                            {/* 제목은 위쪽 점선 자리에 걸쳐 놓는다(fieldset의 legend처럼). */}
                            <strong className="lineup-pool-title">선수 후보</strong>
                            {pool.length === 0 && <span className="lineup-placeholder">모든 선수를 배치했습니다.</span>}
                            {pool.map((player) => (
                                <button key={player.id} type="button" className={`lineup-chip is-movable${selected?.player.id === player.id ? " is-selected" : ""}${memory[player.id]?.absent ? " is-out" : ""}`} {...chipHandlers(player, "pool")}>{chipLabel(player, true)}</button>
                            ))}
                            {pool.length > 0 && <p className="lineup-pool-hint">선수를 터치하거나 드래그해보세요!</p>}
                        </div>
                    )}
                    <aside className="lineup-records" aria-live="polite">
                        <h2>오늘의 기록<span>{slots[activeOrder].order}번 타자</span></h2>
                        <Records records={slots[activeOrder].records} />
                    </aside>
                </div>
            </div>


            {attempts > 0 && !finished && (
                <p className="lineup-legend">
                    <span className="is-correct">정답</span><span className="is-present">선발이지만 다른 타순</span><span className="is-absent">선발 아님</span>
                </p>
            )}
            {finished && (
                <div className={`lineup-outcome${solved ? " is-solved" : ""}`} role="status">
                    <p>{solved ? `${attempts}번 만에 라인업을 완성했습니다!` : `정답을 공개했습니다. (9명 중 ${correctCount}명 정답)`}</p>
                    {solved && solvedIn !== null && <small>걸린 시간 {formatDuration(solvedIn)}</small>}
                    {/* 이 문제를 이 난이도로 끝낸 사람들 기준(나도 포함) */}
                    {puzzleStats?.played > 0 && (
                        <small className="lineup-outcome-stats">
                            <em className={`lineup-level is-${difficulty}`}>{DIFFICULTIES.find(([id]) => id === difficulty)[1]}</em>
                            정답률 {Math.round(puzzleStats.solved / puzzleStats.played * 100)}%
                            {/* 평균 제출 횟수·시간은 맞힌 사람들 기준이라, 아직 맞힌 사람이 없으면 나오지 않는다. */}
                            {/* 좁은 화면에서 한 줄에 들어가도록 「평균」은 한 번만 적는다(평균 2.3회 · 58.20초). */}
                            {puzzleStats.averageAttempts != null && ` · 평균 ${puzzleStats.averageAttempts.toFixed(1)}회`}
                            {puzzleStats.averageMilliseconds != null && ` · ${formatDuration(puzzleStats.averageMilliseconds)}`}
                        </small>
                    )}
                </div>
            )}
            {finished && (
                <label className="lineup-hide-names">
                    <span>선수 이름 가리기</span>
                    <input type="checkbox" role="switch" checked={hideNames} onChange={(event) => setHideNames(event.target.checked)} />
                </label>
            )}
            {daily && finished && <button type="button" className="lineup-secondary lineup-result-open" onClick={() => setShowResult(true)}>결과 · 랭킹 보기</button>}
            {celebrating && <Realistic autorun={{ speed: 0.2, duration: 1 }} />}
            {showResult && (
                <DailyResultModal daily={daily} difficulty={difficulty} solved={solved} attempts={attempts} solvedIn={solvedIn} correctCount={correctCount}
                    history={history} puzzleStats={puzzleStats} onClose={() => setShowResult(false)} />
            )}
            {toast && <p className="lineup-toast" role="status">{toast}</p>}
            {error && <p className="lineup-error" role="alert">{error}</p>}

            <div className="lineup-actions">
                <button type="button" className="lineup-secondary" onClick={onSetup}>뒤로가기</button>
                {finished
                    ? (
                        <>
                            <button type="button" className="lineup-secondary" onClick={onStats}>내 통계</button>
                            {/* 오늘의 라인업은 하루에 한 문제라 다음 경기가 없다. */}
                            {!daily && <button type="button" className="lineup-primary" onClick={onNext} disabled={loading}>{loading ? "경기 고르는 중…" : "다음 경기"}</button>}
                        </>
                    )
                    : (
                        <>
                            {!daily && <button type="button" className="lineup-secondary" onClick={skip} disabled={loading || checking}>{loading ? "고르는 중…" : "건너뛰기"}</button>}
                            <button type="button" className="lineup-secondary" onClick={() => setConfirm("reveal")} disabled={checking}>정답 보기</button>
                            <button type="button" className="lineup-primary" onClick={submit} disabled={!canCheck}>{attempts > 0 ? `제출 (${attempts}회)` : "제출"}</button>
                        </>
                    )}
            </div>

            {confirm && (
                <div className="lineup-modal" role="dialog" aria-modal="true" aria-labelledby="lineup-confirm-title" onClick={() => setConfirm(null)}>
                    <div className="lineup-modal-panel" onClick={(event) => event.stopPropagation()}>
                        <h2 id="lineup-confirm-title">{confirm === "reveal" ? "정답을 볼까요?" : "제출할까요?"}</h2>
                        <p>{confirm === "reveal" ? "정답을 보면 이 경기는 더 이상 풀 수 없습니다." : "제출하면 제출 횟수가 1회 늘어납니다."}</p>
                        <div>
                            {/* 정답 보기는 되돌릴 수 없어 「계속 풀기」에, 제출은 바로 Enter로 넘길 수 있게 「제출」에 포커스를 둔다. */}
                            <button type="button" className="lineup-secondary" autoFocus={confirm === "reveal"} onClick={() => setConfirm(null)}>{confirm === "reveal" ? "계속 풀기" : "취소"}</button>
                            <button type="button" className="lineup-primary" autoFocus={confirm === "submit"} onClick={() => { setConfirm(null); check(confirm === "reveal"); }}>{confirm === "reveal" ? "정답 보기" : "제출"}</button>
                        </div>
                    </div>
                </div>
            )}

            {ghost && <span className="lineup-chip lineup-ghost" style={{ left: ghost.x, top: ghost.y }}>{ghost.name}</span>}
        </section>
    );
};

const Lineup = () => {
    const [settings, setSettings] = useState(loadSettings);
    const [puzzle, setPuzzle] = useState(null);
    // 같은 경기가 다시 뽑혀도 판을 새로 시작하도록 판마다 번호를 붙인다.
    const [round, setRound] = useState(0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    const [showStats, setShowStats] = useState(false);
    const [options, setOptions] = useState(loadOptions);
    const [showOptions, setShowOptions] = useState(false);
    const changeOptions = (patch) => {
        const next = { ...options, ...patch };
        setOptions(next);
        try {
            localStorage.setItem(OPTIONS_KEY, JSON.stringify(next));
        } catch {
            // 저장소를 쓸 수 없으면 이번 방문 동안만 유지된다.
        }
    };

    // 풀고 있는 문제는 주소(?game=경기코드&side=away|home&level=난이도)에 담는다. 새로고침해도 그대로이고, 주소를 받은 사람도 같은 문제를 푼다.
    const [params, setParams] = useSearchParams();
    // 오늘의 라인업은 ?daily=1 하나로 연다(문제는 날짜로 정해진다).
    const address = { daily: params.has("daily"), game: params.get("game"), side: params.get("side"), level: params.get("level") };
    const wanted = address.daily || Boolean(address.game);
    const shown = puzzle && (address.daily
        ? Boolean(puzzle.daily)
        : !puzzle.daily && puzzle.game.code === address.game && puzzle.game.side === address.side && puzzle.difficulty === address.level);

    useEffect(() => {
        if (!wanted) {
            setPuzzle(null);
            return;
        }
        // 방금 뽑은 문제의 주소로 바뀐 것이면 다시 불러올 필요가 없다.
        if (shown) return;
        let cancelled = false;
        setLoading(true);
        setError("");
        axios.get("/api/lineup/new_game.php", { params: address.daily ? { daily: 1 } : { game: address.game, side: address.side, difficulty: address.level } })
            .then(({ data }) => {
                if (cancelled) return;
                setPuzzle(data);
                setRound((current) => current + 1);
                // 주소에 난이도가 빠졌거나 잘못 적혀 있었으면 실제로 내준 문제에 맞춰 고친다.
                if (!address.daily) setParams({ game: data.game.code, side: data.game.side, level: data.difficulty }, { replace: true });
            })
            .catch((failure) => {
                if (cancelled) return;
                setError(failure.response?.data?.error || "경기를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.");
                setParams({}, { replace: true });
            })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [address.daily, address.game, address.side, address.level]);

    const start = async () => {
        setLoading(true);
        setError("");
        try {
            localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
        } catch {
            // 저장소를 쓸 수 없어도 게임은 진행한다.
        }
        try {
            const { data } = await axios.get("/api/lineup/new_game.php", { params: settings });
            setPuzzle(data);
            setRound(round + 1);
            // 시작 화면에서 들어올 때만 방문 기록을 남겨, 브라우저의 뒤로 가기가 건너뛴 문제들이 아니라 시작 화면으로 가게 한다.
            setParams({ game: data.game.code, side: data.game.side, level: data.difficulty }, { replace: Boolean(puzzle) });
        } catch (failure) {
            setError(failure.response?.data?.error || "경기를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.");
            setParams({}, { replace: true });
        } finally {
            setLoading(false);
        }
    };

    return (
        <main className="lineup-page font-family-NaSqNe">
            {wanted && !shown
                ? <section className="lineup-card lineup-loading" role="status">경기를 불러오는 중…</section>
                : puzzle
                    ? <Board key={round} puzzle={puzzle} options={options} loading={loading} onNext={start} onSetup={() => setParams({})} onStats={() => setShowStats(true)} onOptions={() => setShowOptions(true)} />
                    : <Setup settings={settings} setSettings={setSettings} onStart={start} onDaily={() => setParams({ daily: "1" })} onStats={() => setShowStats(true)} onOptions={() => setShowOptions(true)} loading={loading} error={error} />}
            {showOptions && <SettingsModal options={options} onChange={changeOptions} onClose={() => setShowOptions(false)} />}
            {showStats && <StatsModal onClose={() => setShowStats(false)} />}
        </main>
    );
};

export default Lineup;
