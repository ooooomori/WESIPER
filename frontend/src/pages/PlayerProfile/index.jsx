import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import './player-profile.css';
import YearRecords from './YearRecords';
import GameLogFilter from './GameLogFilter';
import ProfileCandleChart from './ProfileCandleChart';
import PlayerCompare from './PlayerCompare';
import ProfileLoading from './ProfileLoading';
import { getCachedProfileData, loadProfileData } from './profileDataCache';
import useTableDrag from './useTableDrag';
import { recordColumnWidth } from './recordTableLayout';
import { loadYearRecords } from './yearRecordsCache';
import { teamFullName } from '../../lib/teamFullName';
import kboSmallLogo from '../../assets/images/s-logos/kbo-white-small.svg';
import ulsanLogo from '../../assets/images/logos/ulsan-logo.png';
import ulsanSmallLogo from '../../assets/images/s-logos/ulsan-small-logo.png';
import mvpAward from '../../assets/images/awards/mvp-transparent.png';
import goldenGloveAward from '../../assets/images/awards/golden-glove.png';
import defenseAward from '../../assets/images/awards/defense-transparent.png';
import rookieAward from '../../assets/images/awards/rookie-transparent.png';
import monthlyMvpAward from '../../assets/images/awards/monthly-mvp-transparent.png';
import allStarAward from '../../assets/images/awards/all-star-transparent.png';
import championshipAward from '../../assets/images/awards/championship.png';
import wbcLogo from '../../assets/images/logos/wbc-logo.svg';
import premier12Logo from '../../assets/images/logos/premier12.svg';
import apbcLogo from '../../assets/images/logos/apbc.webp';
import asianGamesLogo from '../../assets/images/logos/asiangame.svg';
import olympicLogo from '../../assets/images/logos/olympic.svg';

const awardImages = { MVP: mvpAward, '골든글러브': goldenGloveAward, '수비상': defenseAward, '신인왕': rookieAward, '월간 MVP': monthlyMvpAward, '올스타': allStarAward, '우승': championshipAward };
const nationalImages = { WBC: wbcLogo, '프리미어12': premier12Logo, APBC: apbcLogo, '아시안게임': asianGamesLogo, '올림픽': olympicLogo };
const awardOrder = ['MVP', '골든글러브', '수비상', '올스타', '신인왕', '월간 MVP', '우승'];
const nationalOrder = ['WBC', '올림픽', '프리미어12', '아시안게임', 'APBC'];
const titleholderTypes = [['타율', '타격왕'], ['안타', '최다안타'], ['홈런', '홈런왕'], ['타점', '타점왕'], ['득점', '득점왕'], ['도루', '도루왕'], ['출루율', '출루왕'], ['장타율', '장타왕'], ['승리타점', '승리타점 1위'], ['다승', '다승왕'], ['평균자책점', '평균자책점왕'], ['탈삼진', '탈삼진왕'], ['세이브', '세이브왕'], ['홀드', '홀드왕'], ['승률', '승률왕'], ['세이브포인트', '세이브포인트 1위']];
export function TitleholderRecords({ rows = [] }) {
    const titles = titleholderTypes.map(([type, name]) => ({ type, name, rows: rows.filter(row => row.type === type).sort((a, b) => Number(a.year) - Number(b.year)) })).filter(title => title.rows.length);
    if (!titles.length) return null;
    const formatRecord = (type, record) => {
        if (record === null || record === undefined || record === '') return '-';
        const value = Number(record);
        if (!Number.isFinite(value)) return '-';
        if (['타율', '출루율', '장타율', '승률'].includes(type)) return value.toFixed(3);
        if (type === '평균자책점') return value.toFixed(2);
        const units = { 안타: '안타', 홈런: '홈런', 타점: '타점', 득점: '득점', 도루: '도루', 승리타점: '타점', 다승: '승', 탈삼진: '탈삼진', 세이브: '세이브', 홀드: '홀드', 세이브포인트: 'SP' };
        return `${value}${units[type] || ''}`;
    };
    const total = titles.reduce((sum, title) => sum + title.rows.length, 0);
    return <section><div className="profile-heading"><h2>타이틀홀더</h2><small className="profile-season-caption">통산 {total}회</small></div>
        <div className="profile-titles">{titles.map(title => <article className="profile-title-card" key={title.type}>
            <div className="profile-title-head">
                <span className="profile-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 8.5 7.5 12 12 5l4.5 7L21 8.5 19.2 18H4.8L3 8.5Z" /><rect x="4.8" y="19.2" width="14.4" height="2" rx="1" /></svg></span>
                <h3>{title.name}</h3>
                <span className="profile-title-count">{title.rows.length}회</span>
            </div>
            <ul className="profile-title-years" aria-label={`${title.name} 연도별 기록`}>{title.rows.map(row => <li key={row.year}><span>{row.year}</span><strong>{formatRecord(title.type, row.record)}</strong></li>)}</ul>
        </article>)}</div>
    </section>;
}
function GameBadge({ value }) { return value ? <span className={`profile-game-badge ${value === '패전' ? 'loss' : value === '홀드' ? 'hold' : value === '세이브' ? 'save' : ''}`}>{value}</span> : null; }
export function overviewLeagueCaption(records, currentYear) {
    return records?.leagueLevel === 2 ? (records.year === currentYear ? '퓨처스리그' : `${records.year} 시즌 퓨처스리그`) : '';
}

export function StreakRecords({ streaks, pitcher = false }) {
    if (!streaks || pitcher) return null;
    const negativeLabels = { h: '연속 무안타', ob: '연속 무출루', hr: '연속 무홈런', sb: '연속 도루 실패' };
    const dateText = value => value?.replaceAll('-', '.');
    // 여러 해에 걸친 기록(수년째 무홈런 등)은 연도를 두 자리로 줄여 카드 폭을 넘지 않게 한다.
    const multiYear = row => row.startDate?.slice(0, 4) !== row.endDate?.slice(0, 4);
    const shortDate = value => value?.slice(2).replaceAll('-', '.');
    const startDateText = row => multiYear(row) ? shortDate(row.startDate) : dateText(row.startDate);
    const endDateText = row => multiYear(row) ? shortDate(row.endDate) : dateText(row.endDate)?.slice(5);
    const streakEmoji = row => row.positive ? (row.count >= 10 ? '🎉' : '🔥') : row.count >= 100 ? '☠️' : row.count >= 10 ? '🧊' : '❄️';
    const hasCount = row => row.positive !== null || (row.key === 'sb' && row.count > 0);
    const stateText = row => row.positive === null ? '기록 없음' : row.positive ? `${streakEmoji(row)} 진행 중` : `${streakEmoji(row)} ${row.key === 'sb' ? '실패' : '침묵'}`;
    const description = row => row.positive === null ? (row.key === 'sb' ? '연속 도루 시도 없음' : '타격 기록 없음') : row.positive ? `연속 ${row.label}${row.key === 'sb' ? ' 성공' : ''}` : negativeLabels[row.key];
    return <section><div className="profile-heading"><h2>연속 경기 기록</h2><small className="profile-season-caption">{streaks.year} 정규시즌 · 현재 진행 중</small></div>
        <dl className="profile-streak-cards" aria-label={`${streaks.year}년 1군 정규시즌 연속 경기 기록`}>{streaks.rows.map(row => <div className={`profile-streak-card ${row.positive === true ? 'is-positive' : row.positive === false ? 'is-negative' : 'is-empty'}`} key={row.key}>
            <dt><span className="profile-streak-label">{row.label}</span><span className="profile-streak-state">{stateText(row)}</span></dt>
            <dd className="profile-streak-count"><strong>{hasCount(row) ? row.count : '—'}</strong>{hasCount(row) && <span>경기</span>}</dd>
            <dd className="profile-streak-detail"><span className="profile-streak-description">{description(row)}</span>{row.startDate && row.endDate && <span className="profile-streak-period"><time dateTime={row.startDate}>{startDateText(row)}</time><span>~</span><time dateTime={row.endDate}>{endDateText(row)}</time></span>}</dd>
        </div>)}</dl>
    </section>;
}
export { GameLogFilter };

const teams = {
    KIA: ['kia', '#ea0029', '#101d30', 'KIA 타이거즈'], LG: ['lg', '#c30452', '#202020', 'LG 트윈스'],
    SSG: ['ssg', '#ce0e2d', '#b69b65', 'SSG 랜더스'], 두산: ['doo', '#131d40', '#ed1c24', '두산 베어스'],
    삼성: ['sam', '#074ca1', '#c0c0c0', '삼성 라이온즈'], 롯데: ['lot', '#041e42', '#d00f31', '롯데 자이언츠'],
    KT: ['kt', '#202020', '#eb1c24', 'KT 위즈'], 한화: ['han', '#f37321', '#25282a', '한화 이글스'],
    NC: ['nc', '#315288', '#c8a168', 'NC 다이노스'], 키움: ['kiw', '#820024', '#d6b879', '키움 히어로즈'],
    울산: ['ulsan', '#c90000', '#202020', '울산 웨일즈'],
    '울산 웨일즈': ['ulsan', '#c90000', '#202020', '울산 웨일즈'],
};
const logos = import.meta.glob('../../assets/images/logos/*-logo.svg', { eager: true, query: '?url', import: 'default' });
const smallLogos = import.meta.glob('../../assets/images/s-logos/*-small-logo.svg', { eager: true, query: '?url', import: 'default' });
function heroColors(hex) {
    const rgb = [1, 3, 5].map(start => parseInt(hex.slice(start, start + 2), 16) / 255);
    const max = Math.max(...rgb), min = Math.min(...rgb), delta = max - min;
    const lightness = (max + min) / 2;
    let hue = 0;
    if (delta) {
        const index = rgb.indexOf(max);
        hue = ((index === 0 ? (rgb[1] - rgb[2]) / delta : index === 1 ? (rgb[2] - rgb[0]) / delta + 2 : (rgb[0] - rgb[1]) / delta + 4) * 60 + 360) % 360;
    }
    const saturation = delta ? delta / (1 - Math.abs(2 * lightness - 1)) * 100 : 0;
    return {
        light: `hsl(${hue} ${saturation}% ${Math.min(48, Math.max(34, lightness * 100 + 14))}%)`,
        dark: `hsl(${hue} ${saturation}% ${Math.max(10, lightness * 60)}%)`,
    };
}
function TeamLogo({ team, className = '', small = false }) {
    const code = teams[team]?.[0];
    const src = code === 'ulsan' ? (small ? ulsanSmallLogo : ulsanLogo) : (small ? smallLogos[`../../assets/images/s-logos/${code}-small-logo.svg`] : null) || logos[`../../assets/images/logos/${code}-logo.svg`];
    return code ? <img className={className} src={src} alt="" /> : null;
}
const movementCategories = [
    ['move', '이적·계약', ['트레이드', '트레이드(웨이버)', 'FA 자격취득', 'FA 계약', '비FA 다년계약', '자유계약', '해외 복귀 FA 계약', 'FA 보상선수', '2차 드래프트', '소속선수 추가 등록']],
    ['release', '방출', ['자유계약선수', '웨이버', '임의해지', '군보류 자유계약선수', '자유계약선수 - 참가활동정지']],
    ['injury', '부상', ['부상자 명단', '치료·재활명단', '재활선수(외국인 선수)']],
    ['military', '군보류', ['군보류']],
    ['number', '등번호', ['등번호 변경']],
    ['etc', '기타', []],
];
const movementCategory = type => movementCategories.find(([, , types]) => types.includes(type))?.[0] || 'etc';
const MOVEMENT_PREVIEW_COUNT = 6;
const movementRoute = movement => movement.type === '등번호 변경' ? null : (movement.note || '').trim().match(/^([^→\s]+)\s*→\s*([^→\s]+)$/);
// 이동 현황 전용: 현재 팀 + 옛 팀명(넥센·SK) 작은 로고
const legacyMovementTeams = { 넥센: 'nex', SK: 'sk' };
const legacyTeamColors = { 넥센: '#820024', SK: '#ea002c' };
const compareTeamColor = team => teams[team]?.[1] || legacyTeamColors[team] || null;
function movementLogo(team) {
    const code = teams[team]?.[0] || legacyMovementTeams[team];
    if (!code) return null;
    if (code === 'ulsan') return ulsanSmallLogo;
    return smallLogos[`../../assets/images/s-logos/${code}-small-logo.svg`] || logos[`../../assets/images/logos/${code}-logo.svg`] || null;
}
function MovementTeam({ team, withName = false }) {
    // 기본은 로고만, 팀 간 이동 경로(트레이드 등)는 로고와 팀 이름을 함께 보여준다. 로고가 없으면 이름만 쓴다.
    const src = movementLogo(team);
    if (src && withName) return <span className="profile-movement-team is-named"><img className="profile-movement-logo" src={src} alt="" />{team}</span>;
    return src ? <span className="profile-movement-team" title={team} role="img" aria-label={team}><img className="profile-movement-logo" src={src} alt="" /></span>
        : <span className="profile-movement-team is-text">{team}</span>;
}
// 계약 금액(원) → "80억", "24.5억", "6500만"
function formatContractAmount(amount, currency = 'KRW') {
    const value = Number(amount);
    if (!value) return null;
    if (currency && currency !== 'KRW') return `${value.toLocaleString('ko-KR')} ${currency}`;
    if (value >= 1e8) { const eok = value / 1e8; return `${Number.isInteger(eok) ? eok : eok.toFixed(1).replace(/\.0$/, '')}억`; }
    return `${Math.round(value / 1e4).toLocaleString('ko-KR')}만`;
}
function contractSourceLabel(url) {
    if (/KBO_FILE|연감/.test(decodeURIComponent(url))) return 'KBO 연감';
    try { return new URL(url).hostname.replace(/^www\./, ''); } catch { return '출처'; }
}
function hasContract(movement) { return Boolean(movement.contractTerm || movement.contractTotal); }
function MovementLine({ movement, open = false, onToggle }) {
    const route = movementRoute(movement);
    const note = (movement.note || '').trim();
    const contract = hasContract(movement);
    const expandable = contract && Boolean(movement.contractDetails || movement.contractSources || (movement.contractRegistered && movement.contractRegistered !== movement.contractTotal));
    let detail = null;
    if (route) {
        detail = <span className="profile-movement-route" aria-label={`${route[1]}에서 ${route[2]}로`}><MovementTeam team={route[1]} withName /><i aria-hidden="true">→</i><MovementTeam team={route[2]} withName /></span>;
    } else if (contract) {
        const amount = formatContractAmount(movement.contractTotal, movement.contractCurrency);
        detail = <span className="profile-movement-contract">{movement.contractTerm && <b>{movement.contractTerm}</b>}{movement.contractTerm && amount && <i aria-hidden="true">·</i>}{amount && <b className="is-amount">{amount}</b>}</span>;
    } else if (movement.type === '등번호 변경' && (movement.oldBackNo || movement.newBackNo)) {
        detail = <span className="profile-movement-detail profile-movement-number">#{movement.oldBackNo ?? '-'}<i aria-hidden="true">→</i>#{movement.newBackNo ?? '-'}</span>;
    } else if (note) {
        detail = <span className="profile-movement-detail" title={note}>{note}</span>;
    }
    return <div className="profile-movement-line">
        <span className="profile-movement-type">{movement.type}</span>
        {detail}
        {expandable && <button type="button" className="profile-movement-toggle" aria-expanded={open} aria-label={open ? '계약 상세 접기' : '계약 상세 보기'} onClick={onToggle}>
            <span>{open ? '접기' : '상세'}</span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </button>}
    </div>;
}
function MovementContractDetail({ movement }) {
    const sources = (movement.contractSources || '').split(/\s*\n\s*/).filter(Boolean);
    const registered = movement.contractRegistered && movement.contractRegistered !== movement.contractTotal ? formatContractAmount(movement.contractRegistered, movement.contractCurrency) : null;
    return <div className="profile-movement-contract-detail">
        {movement.contractDetails && <p>{movement.contractDetails}</p>}
        {registered && <p className="profile-movement-registered">KBO 등록 금액 <b>{registered}</b></p>}
        {sources.length > 0 && <div className="profile-movement-sources"><span>출처</span>{sources.map(url => <a key={url} href={url} target="_blank" rel="noopener noreferrer">{contractSourceLabel(url)}<svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></a>)}</div>}
    </div>;
}
function MovementMarker({ team }) {
    const src = movementLogo(team);
    return <span className="profile-movement-marker" title={team || undefined} aria-label={team || undefined} role={team ? 'img' : undefined}>
        {src ? <img className="profile-movement-marker-logo" src={src} alt="" /> : team ? <b>{team.slice(0, 2)}</b> : null}
    </span>;
}
function PlayerMovements({ movements = [] }) {
    const [filter, setFilter] = useState('all');
    const [expanded, setExpanded] = useState(false);
    const [openRows, setOpenRows] = useState({});
    if (!movements.length) return null;
    const counts = movements.reduce((map, movement) => map.set(movementCategory(movement.type), (map.get(movementCategory(movement.type)) || 0) + 1), new Map());
    const filters = movementCategories.filter(([id]) => counts.has(id));
    const filtered = filter === 'all' ? movements : movements.filter(movement => movementCategory(movement.type) === filter);
    const visible = expanded ? filtered : filtered.slice(0, MOVEMENT_PREVIEW_COUNT);
    const choose = id => { setFilter(id); setExpanded(false); };
    return <section className="profile-movements-section">
        <div className="profile-heading profile-movements-heading">
            <h2>이동 현황</h2>
            {filters.length > 1 && <div className="profile-movement-filters" role="group" aria-label="이동 현황 종류 필터">
                {[['all', '전체'], ...filters.map(([id, label]) => [id, label])].map(([id, label]) => <button key={id} type="button" className={`is-${id}`} aria-pressed={filter === id} onClick={() => choose(id)}>
                    {label}<span>{id === 'all' ? movements.length : counts.get(id)}</span>
                </button>)}
            </div>}
        </div>
        <ol className="profile-movements">
            {visible.map((movement, index) => { const rowKey = `${movement.date}-${movement.type}-${movement.team}-${index}`; const open = Boolean(openRows[rowKey]); return <li key={rowKey} className={`is-${movementCategory(movement.type)}${hasContract(movement) ? ' has-contract' : ''}`}>
                <MovementMarker team={movement.team} />
                <div className="profile-movement-body">
                    <time dateTime={movement.date}>{movement.date.replaceAll('-', '.')}</time>
                    <MovementLine movement={movement} open={open} onToggle={() => setOpenRows(rows => ({ ...rows, [rowKey]: !rows[rowKey] }))} />
                    {open && <MovementContractDetail movement={movement} />}
                </div>
            </li>; })}
        </ol>
        {filtered.length > MOVEMENT_PREVIEW_COUNT && <button type="button" className="profile-movements-more" aria-expanded={expanded} onClick={() => setExpanded(value => !value)}>
            {expanded ? '접기' : `전체 보기 (${filtered.length})`}
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d={expanded ? 'm6 15 6-6 6 6' : 'm6 9 6 6 6-6'} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </button>}
    </section>;
}
const careerResultTone = note => /금메달|^우승$/.test(note || '') ? 'gold' : /은메달|준우승/.test(note || '') ? 'silver' : /동메달|^3위$/.test(note || '') ? 'bronze' : 'plain';
function CareerTeam({ team }) {
    if (!team) return null;
    const src = movementLogo(team);
    return <span className="profile-career-team">{src && <img src={src} alt="" />}{team}</span>;
}
function CareerModal({ item, kind, onClose }) {
    const dialog = useRef(null);
    useEffect(() => { dialog.current.showModal(); }, []);
    const close = () => dialog.current.close();
    const national = kind === 'national';
    const image = national ? nationalImages[item.name] : awardImages[item.name];
    const years = item.rows.map(row => Number(row.year)).filter(Boolean);
    const span = years.length ? (Math.min(...years) === Math.max(...years) ? `${Math.min(...years)}` : `${Math.min(...years)} – ${Math.max(...years)}`) : null;
    const count = item.name === '우승' ? `V${item.rows.length}` : `${item.rows.length}회`;
    const medals = national ? item.rows.filter(row => careerResultTone(row.note) !== 'plain').length : 0;
    return <dialog ref={dialog} className="profile-career-modal" aria-labelledby="profile-career-title" onClose={onClose} onClick={e => { if (e.target === e.currentTarget) close(); }}>
        <header className="profile-career-modal-head">
            <div className="profile-career-emblem">{image ? <img src={image} alt="" /> : <span aria-hidden="true">{item.name.slice(0, 1)}</span>}</div>
            <div className="profile-career-title">
                <small>{national ? '국가대표 경력' : '수상 경력'}</small>
                <h2 id="profile-career-title">{item.name}</h2>
                <p><strong>{count}</strong>{span && <span>{span}</span>}{medals > 0 && <span>메달·입상 {medals}회</span>}</p>
            </div>
            <button type="button" className="profile-career-close" aria-label="닫기" onClick={close}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg></button>
        </header>
        <ol className="profile-career-list">
            {item.rows.map((row, index) => <li key={index}>
                <span className="profile-career-year">{row.year || '—'}{row.month && <small>{row.month}월</small>}</span>
                <span className="profile-career-detail">
                    {national
                        ? <span className={`profile-career-result is-${careerResultTone(row.note)}`}>{careerResultTone(row.note) !== 'plain' && <i aria-hidden="true" />}{row.note || '대표 선발'}</span>
                        : <><CareerTeam team={row.team} />{row.pos && <span className="profile-career-chip">{row.pos}</span>}{row.note && <span className="profile-career-note">{row.note}</span>}</>}
                </span>
            </li>)}
        </ol>
    </dialog>;
}
export default function PlayerProfile({ pid }) {
    const rollingScrollRef = useTableDrag();
    const location = useLocation();
    const navigate = useNavigate();
    const tabNames = ['', 'record', 'game', 'chart', 'compare'];
    const tabFromUrl = () => Math.max(0, tabNames.indexOf(new URLSearchParams(location.search).get('tab') || ''));
    const initial = String(location.state?.player?.PlayerId) === String(pid) ? location.state.player : null;
    const [selectedCareer, setSelectedCareer] = useState(null);
    const [player, setPlayer] = useState(initial ? { ...initial, IsKbodle: initial.IsActive === false ? 0 : 1 } : null);
    const [records, setRecords] = useState(null);
    const [recordLoading, setRecordLoading] = useState(true);
    const [recordError, setRecordError] = useState('');
    const [ranks, setRanks] = useState({});
    const [error, setError] = useState('');
    const [tab, setTab] = useState(tabFromUrl);
    const [chartVisited, setChartVisited] = useState(() => tabFromUrl() === 3);
    useEffect(() => { if (tab === 3) setChartVisited(true); }, [tab]);
    const [detailedGames, setDetailedGames] = useState(false);
    const [gameYear, setGameYear] = useState('');
    const [gameSeason, setGameSeason] = useState('');
    const [gameLog, setGameLog] = useState({ years: [], games: [] });
    const [gameLogLoading, setGameLogLoading] = useState(false);
    const [gameLogError, setGameLogError] = useState('');
    const [photoIndex, setPhotoIndex] = useState(0);
    const [compact, setCompact] = useState(false);
    const heroRef = useRef(null);
    const contentRef = useRef(null);
    useEffect(() => { setTab(tabFromUrl()); }, [location.search]);
    useEffect(() => { setGameYear(''); setGameSeason(''); setGameLog({ years: [], games: [] }); }, [pid]);
    useEffect(() => {
        // 인기 선수 집계용 조회 기록. 같은 탭에서 같은 선수를 다시 열면 보내지 않는다(서버도 하루 1회로 거른다).
        const key = `wesiper-viewed-${pid}`;
        try { if (window.sessionStorage.getItem(key)) return; window.sessionStorage.setItem(key, '1'); } catch { /* 저장소 차단 시 서버 중복 제거에 맡긴다. */ }
        fetch('/api/playerView.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pid: String(pid) }), keepalive: true }).catch(() => {});
    }, [pid]);
    useEffect(() => {
        if (tab !== 2) return;
        let active = true;
        const query = new URLSearchParams({ pid, part: 'games' });
        if (gameSeason) query.set('season', gameSeason);
        if (gameYear) query.set('year', gameYear);
        const url = `/api/playerProfile.php?${query}`;
        const cached = getCachedProfileData(url);
        setGameLogError('');
        if (cached) { setGameLog(cached); setGameLogLoading(false); return; }
        setGameLogLoading(true);
        loadProfileData(url, data => Array.isArray(data.games) && Array.isArray(data.years))
            .then(data => { if (active) setGameLog(data); })
            .catch(() => { if (active) { setGameLog(previous => ({ ...previous, games: [] })); setGameLogError('경기 기록을 불러오지 못했습니다.'); } })
            .finally(() => { if (active) setGameLogLoading(false); });
        return () => { active = false; };
    }, [pid, tab, gameYear, gameSeason]);
    useEffect(() => {
        if (!player) return;
        const hero = heroRef.current;
        const shell = hero.closest('.player-profile-shell');
        const nav = shell?.querySelector('.site-navbar');
        let frame = 0;
        const update = () => {
            frame = 0;
            const navHeight = window.innerWidth < 800 ? 0 : nav?.getBoundingClientRect().height || 0;
            shell?.style.setProperty('--profile-nav-height', `${navHeight}px`);
            const rect = hero.getBoundingClientRect();
            setCompact(rect.bottom <= navHeight + 52);
        };
        const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
        const observer = new ResizeObserver(schedule);
        observer.observe(hero);
        if (nav) observer.observe(nav);
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        update();
        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            shell?.style.removeProperty('--profile-nav-height');
        };
    }, [player]);
    useEffect(() => {
        let active = true;
        window.scrollTo(0, 0);
        setRecords(null);
        setRanks({});
        setRecordLoading(true);
        setRecordError('');
        setError('');
        async function load() {
            try {
                const result = await loadProfileData(`/api/playerProfile.php?pid=${encodeURIComponent(pid)}&part=profile`, data => !!data.player);
                if (!active) return;
                setPlayer(previous => ({ ...result.player, FormerTeam: result.player.FormerTeam || previous?.FormerTeam || null, Awards: (result.career || []).filter(row => row.category === 'award'), National: (result.career || []).filter(row => row.category === 'national') }));
                try {
                    const recordResult = await loadProfileData(`/api/playerProfile.php?pid=${encodeURIComponent(pid)}&part=records`, data => Object.hasOwn(data, 'records'));
                    if (!active) return;
                    setRecords(recordResult.records);
                    if (recordResult.player?.FormerTeam) setPlayer(previous => ({ ...previous, FormerTeam: recordResult.player.FormerTeam }));
                    setRecordLoading(false);
                    if (recordResult.records) {
                        try {
                            const rankResult = await loadProfileData(`/api/playerProfile.php?pid=${encodeURIComponent(pid)}&part=ranks&year=${recordResult.records.year}&league=${recordResult.records.leagueLevel ?? 1}`);
                            if (active) setRanks(rankResult.ranks || {});
                        } catch { /* 순위 조회 실패가 이미 표시한 기록을 가리지 않도록 한다. */ }
                    }
                } catch (e) { if (active) { setRecordLoading(false); setRecordError(e.message); } }
            } catch (e) { if (active) { setError(e.message); setRecordLoading(false); } }
            finally { if (active) loadYearRecords(pid).catch(() => {}); }
        }
        load();
        return () => { active = false; };
    }, [pid]);
    if (!player) return <main className="player-profile profile-message">{error ? <><p role="alert">{error}</p><Link to="/?search=1">선수 검색으로 돌아가기</Link></> : <div className="profile-loading-shell" aria-label="선수 정보 불러오는 중" aria-busy="true"><div className="profile-skeleton-hero" /><div className="profile-skeleton-grid">{Array.from({length:8},(_,i)=><span key={i}/>)}</div></div>}</main>;
    const retired = String(player.IsKbodle) === '0';
    const numberRetired = Number(player.IsNumberRetired) === 1;
    const teamCode = String(numberRetired ? player.NumberRetiredTeam || player.FormerTeam || player.Team : player.Team).toUpperCase();
    const retiredTheme = retired && !numberRetired;
    const team = retiredTheme ? [null, '#737b86', '#303640', '은퇴'] : teams[teamCode] || [null, '#476582', '#17283d', teamCode];
    const heroPalette = heroColors(team[1]);
    const extraPositions = [...new Set([player.MainPos, player.SubPos].flatMap(value => (value || '').split(',')).map(value => value.trim()).filter(Boolean))];
    const isPitcher = (player.Pos || '').includes('투수');
    const mainPosition = player.MainPos?.trim();
    const positionText = value => isPitcher && value && !value.endsWith('투수') ? `${value}투수` : value;
    const detailedPosition = mainPosition ? extraPositions.map(positionText).join(', ') : player.Pos;
    const heroPosition = positionText(mainPosition) || player.Pos;
    const retirementYear = player.Retire || records?.year;
    const formerTeam = player.FormerTeam ? teamFullName(player.FormerTeam) : null;
    const heroSummary = [retired || numberRetired ? '은퇴' : team[3], heroPosition].filter(Boolean).join(' | ');
    const handedness = [player.Throws, player.Bat].filter(Boolean).join('');
    const birth = player.Birth;
    const birthMatch = birth?.match(/^(\d{4})[-.](\d{2})[-.](\d{2})$/);
    const dateParts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Seoul', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    const dateValue = key => dateParts.find(part => part.type === key).value;
    const age = birthMatch ? Number(dateValue('year')) - Number(birthMatch[1]) - (`${dateValue('month')}-${dateValue('day')}` < `${birthMatch[2]}-${birthMatch[3]}` ? 1 : 0) : null;
    const birthday = birth ? `${birth.replaceAll('-', '.')}${age !== null ? ` (${age}세)` : ''}` : null;
    const groupCareer = rows => Object.entries((rows || []).reduce((groups, row) => { (groups[row.type] ||= []).push(row); return groups; }, {})).map(([name, rows]) => ({ name, rows: rows.sort((a, b) => Number(a.year) - Number(b.year) || Number(a.month) - Number(b.month)) }));
    const awards = groupCareer(player.Awards).sort((a,b)=>(awardOrder.indexOf(a.name) < 0 ? 99 : awardOrder.indexOf(a.name))-(awardOrder.indexOf(b.name) < 0 ? 99 : awardOrder.indexOf(b.name)));
    const national = groupCareer(player.National).sort((a, b) => nationalOrder.indexOf(a.name) - nationalOrder.indexOf(b.name));
    const info = [['이름', Number(player.IsForeign) === 1 ? player.FullName || player.Name : player.Name], ['개명', player.OldName ? `${player.OldName} → ${player.Name}` : null], ['등번호', numberRetired ? null : player.BackNo], ['소속팀', retired || numberRetired ? null : team[3]], ['영구결번', numberRetired ? `${team[3]}${player.BackNo != null ? ` No.${player.BackNo}` : ''}` : null], ['포지션', detailedPosition], ['투타', handedness], ['생년월일', birthday], ['신체', player.Body], ['학력', player.School], ['입단', player.Draft], ['은퇴', retired && retirementYear ? `${retirementYear}년` : null], ['별명', player.Nicknames?.join(', ')]].filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '');
    const gameColumns = [['날짜','date'],['상대','opponent'],['구장','stadium'],['경기 결과','result'],['선발','isStarter'],...((gameLog.pitcher ?? records?.pitcher) ? (detailedGames ? [['기록','badge'],['이닝','innings'],['실점','r'],['자책','er'],['삼진','so'],['피안타','h'],['피홈런','hr'],['볼넷','bb'],['사구','hbp']] : [['기록','summary']]) : [['포지션','position'],['타순','order'],...(detailedGames ? [['타석','pa'],['타수','ab'],['안타','h'],['2루타','doubles'],['3루타','triples'],['홈런','hr'],['타점','rbi'],['득점','r'],['볼넷','bb'],['사구','hbp'],['희플','sf'],['희생번트','sh'],['병살','gdp'],['도루','sb'],['도루실패','cs']] : [['기록','summary']])]),['비고','notes']];
    const rollingColumns = [['기간','label'],['경기','games'],...(records?.pitcher ? [['선발','starts'],['ERA','era'],['승리','wins'],['패전','losses'],['세이브','saves'],['홀드','holds'],['이닝','innings'],['실점','r'],['자책','er'],['삼진','so'],['피안타','h'],['피홈런','hr'],['볼넷','bb'],['사구','hbp'],['WHIP','whip']] : [['타석','pa'],['타율','avg'],['타수','ab'],['안타','h'],['2루타','doubles'],['3루타','triples'],['홈런','hr'],['타점','rbi'],['득점','r'],['볼넷','bb'],['사구','hbp'],['희플','sf'],['희생번트','sh'],['병살','gdp'],['도루','sb'],['도루자','cs'],['출루율','obp'],['장타율','slg'],['OPS','ops'],['OPS+','opsPlus']])];
    const futuresCaption = overviewLeagueCaption(records, Number(dateValue('year')));
    const recentCaption = futuresCaption || (records?.year < Number(dateValue('year')) ? `${records.year} 시즌` : '');
    const rollingMobileWidths = rollingColumns.map(([label, key]) => key === 'label' ? 64 : recordColumnWidth(label, key, true));
    const gameCell = (game, key) => {
        const positionParts = (game.position || '').split(' · ');
        const substituteRole = ['대타', '대주자', '교체', '대수비'].includes(positionParts[0]) ? positionParts[0] : null;
        if (key === 'position') {
            const position = substituteRole ? positionParts.slice(1).join(' · ') : game.position;
            const symbols = { '투수': 'P', '포수': 'C', '1루': '1B', '2루': '2B', '3루': '3B', '유격': 'SS', '좌익': 'LF', '중견': 'CF', '우익': 'RF', '지명': 'DH' };
            return position ? position.split('-').map(value => symbols[value] || value).join('-') : '—';
        }
        if (key === 'isStarter' && !(gameLog.pitcher ?? records?.pitcher) && game.isStarter === false) return substituteRole || '';
        if (key === 'notes') return game.notes || '';
        if (key === 'badge') return game.badge ? <GameBadge value={game.badge} /> : '—';
        if (key === 'summary') {
            if (gameLog.pitcher ?? records?.pitcher) {
                const walks = game.bb == null || game.hbp == null ? null : Number(game.bb) + Number(game.hbp);
                const earnedRuns = game.er != null && (game.r == null || Number(game.er) !== Number(game.r)) ? `(${game.er}자책)` : '';
                const extraRecords = [[game.so, '삼진'], [game.h, '피안타'], [walks, '사사구']]
                    .filter(([value]) => value == null || Number(value) !== 0)
                    .map(([value, label]) => ` ${value ?? '—'}${label}`).join('');
                return <>{game.innings}이닝 {game.r ?? '—'}실점{earnedRuns}{extraRecords} <GameBadge value={game.badge} /></>;
            }
            return `${game.ab ?? '—'}타수 ${game.h ?? '—'}안타` + [['hr','홈런'],['bb','볼넷'],['rbi','타점'],['r','득점'],['sb','도루']].filter(([key])=>game[key]>0).map(([key,label])=>` ${game[key]}${label}`).join('');
        }
        if (key === 'result') {
            const match = game.result?.match(/^([WLD])\s+(.+)$/);
            if (!match) return '—';
            const [label, className] = { W: ['승', 'is-win'], L: ['패', 'is-loss'], D: ['무', 'is-draw'] }[match[1]];
            return <span className="profile-season-result"><span className={className}>{label}</span><span>{match[2]}</span></span>;
        }
        return key === 'date' ? game.date.slice(5) : key === 'opponent' ? (game.opponent ? `${game.isAway ? '@' : ''}${game.opponent}` : '—') : key === 'isStarter' ? (game.isStarter === null || game.isStarter === undefined ? '—' : game.isStarter ? <span aria-label="선발">✓</span> : '') : game[key] ?? '—';
    };
    const photos = [`/assets/images/player/kbo/${encodeURIComponent(player.PlayerId)}.jpg`, `/assets/images/player/kbo/${encodeURIComponent(player.PlayerId)}.png`];
    const photo = photoIndex >= photos.length ? <img className="profile-photo-fallback" src="/wesiper-favicon.png" alt="" /> : <img src={photos[photoIndex]} alt={player.Name} onError={() => setPhotoIndex(i => i + 1)} />;
    const watermark = retiredTheme ? <img className="profile-watermark" src={kboSmallLogo} alt="" /> : <TeamLogo team={teamCode} className="profile-watermark" />;
    const compactWatermark = retiredTheme ? <img className="profile-watermark" src={kboSmallLogo} alt="" /> : <TeamLogo small team={teamCode} className="profile-watermark" />;
    const tabs = <div className="profile-tabs" style={{ '--active-tab': tab }} role="group" aria-label="선수 정보 보기"><span className="profile-tab-indicator" aria-hidden="true" />{['개요', '기록', '경기', '차트', '비교'].map((name, i) => <button key={name} type="button" aria-pressed={tab === i} className={tab === i ? 'active' : ''} onClick={() => { setTab(i); const params = new URLSearchParams(location.search); if (i === 0) params.delete('tab'); else params.set('tab', tabNames[i]); navigate(`${location.pathname}?${params.toString()}`, { state: location.state }); window.scrollTo({ top: 0, left: 0, behavior: 'instant' }); }}>{name}</button>)}</div>;
    return <main className="player-profile" style={{ '--team-primary': team[1], '--team-secondary': team[2], '--team-primary-light': heroPalette.light, '--team-primary-dark': heroPalette.dark, '--heading-primary': retiredTheme ? '#00b5e5' : team[1], '--heading-secondary': retiredTheme ? '#00d1c6' : team[2] }}>
        <section ref={heroRef} className="profile-hero" aria-label="선수 소개">
            {watermark}
            <Link className="profile-back" to="/?search=1">← 선수 검색</Link>
            <div className="profile-identity">
                <div className="profile-photo">{photo}</div>
                <div className="profile-bio"><h1>{player.Name} {player.BackNo != null && <span>#{player.BackNo}</span>}</h1><p>{heroSummary}</p>{(handedness || birthday) && <p>{[handedness, birthday].filter(Boolean).join(' | ')}</p>}{numberRetired ? <p>{team[3]} 영구결번</p> : retired && formerTeam && <p>前 {formerTeam}</p>}{!retired && !numberRetired && player.Body && <p>{player.Body}</p>}</div>
            </div>
            {tabs}
        </section>
        <div className={`profile-compact-header ${compact ? 'is-visible' : ''}`} aria-hidden={!compact} inert={!compact ? '' : undefined}>
            <div className="profile-compact-identity"><Link className="profile-compact-back" to="/?search=1" aria-label="선수 검색으로 돌아가기"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 5-7 7 7 7M5 12h14" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></Link>{compactWatermark}<div className="profile-photo">{photo}</div><div><strong>{player.Name} {player.BackNo != null && <span>#{player.BackNo}</span>}</strong><p>{retired || numberRetired ? heroSummary : [team[3], heroPosition].filter(Boolean).join(' · ')}</p></div></div>
            <div className="profile-compact-tabs">{tabs}</div>
        </div>
        <div ref={contentRef} className="profile-content">
            {(error || recordError) && <p role="alert">{error || recordError}</p>}
            {recordLoading && tab === 0 && <section aria-busy="true" aria-label="경기 기록 불러오는 중"><div className="profile-skeleton-grid">{Array.from({length:8},(_,i)=><span key={i}/>)}</div></section>}
            {records && records.year === Number(dateValue('year')) && tab === 1 && records.rolling && <section><div className="profile-heading"><h2>최근 성적</h2></div><div className="profile-game-scroll" ref={rollingScrollRef}><table className="profile-season-games profile-rolling-table" style={{ '--record-table-mobile-width': `${rollingMobileWidths.reduce((sum, width) => sum + width, 0)}px` }}><colgroup>{rollingMobileWidths.map((width, index) => <col key={index} style={{ '--record-column-mobile-width': `${width}px` }} />)}</colgroup><caption className="sr-only">최근 7·15·30일 성적</caption><thead><tr>{rollingColumns.map(([label,key])=><th key={key} scope="col">{label}</th>)}</tr></thead><tbody>{records.rolling.map(row=><tr key={row.label}>{rollingColumns.map(([,key])=><td key={key}>{row[key] ?? (['sf','sh'].includes(key) ? 0 : '—')}</td>)}</tr>)}</tbody></table></div></section>}
            {tab === 1 && <YearRecords key={`year-records-${pid}`} pid={pid} position={player.Pos} teams={teams} />}
            {tab === 1 && <TitleholderRecords rows={player.Titleholders} />}
            {records && tab === 0 && <section style={retiredTheme ? { '--team-primary': '#002561', '--team-secondary': '#286fcc' } : undefined}><div className="profile-heading"><h2>{records.career ? '통산 주요 기록' : `${records.year} 시즌 주요 기록`}</h2>{futuresCaption && <small className="profile-season-caption">{records.career ? '퓨처스리그' : futuresCaption}</small>}</div><div className={`profile-stats ${`profile-stats-ranked${tab === 0 ? ' profile-stats-overview' : ''}`}`}>{records.stats.map(([label, value]) => {
                const rank = value != null && Number(value) !== 0 ? ranks[label] : null;
                return <div key={label} className={rank >= 1 && rank <= 5 ? 'profile-stat-top-five' : undefined}>{tab === 0 ? <><div className="profile-stat-header"><span>{label}</span><small>{rank != null ? `${rank}위` : ''}</small></div><strong>{value ?? '—'}</strong></> : <><span>{label}</span><strong>{value ?? '—'}</strong>{<small>{rank != null ? `${rank}위` : ''}</small>}</>}</div>;
            })}</div></section>}
            {records && tab === 0 && <section><div className="profile-heading"><h2>최근 5경기</h2>{recentCaption && <small className="profile-season-caption">{recentCaption}</small>}</div><div className="profile-game-scroll"><table className="profile-games"><caption className="sr-only">최근 5경기 기록</caption><tbody>{records.recent.map((game, i) => <tr key={`${game.date}-${i}`}><td>{game.date.slice(5).replace('-', '.')}</td><td>{game.opponent ? `vs ${game.isAway ? '@' : ''}${game.opponent}` : '—'}</td><td><span className="profile-recent-result">{game.text}<GameBadge value={game.badge} /></span></td></tr>)}</tbody></table></div></section>}
            {tab === 2 && <StreakRecords streaks={gameLog.currentSeasonStreaks} pitcher={(player.Pos || '').includes('투수') || gameLog.pitcher === true} />}
            {tab === 2 && <section><div className="profile-heading profile-game-heading"><h2>경기 일지</h2><GameLogFilter years={gameLog.years} year={gameYear || gameLog.year} season={gameSeason || gameLog.season || 'regular'} availableSeasons={gameLog.availableSeasons} onYearChange={value => { setGameYear(value); const available = gameLog.availableSeasons?.[value] || []; setGameSeason(available.includes(gameSeason || gameLog.season) ? gameSeason || gameLog.season : available[0] || ''); }} onSeasonChange={value => { setGameYear(String(gameYear || gameLog.year)); setGameSeason(value); }} /><div className={`profile-game-switch ${detailedGames ? 'is-detailed' : ''}`} role="group" aria-label="경기 기록 표시 방식">{['간략히','자세히'].map((label,i)=><button key={label} type="button" className={detailedGames === Boolean(i) ? 'active' : ''} aria-pressed={detailedGames === Boolean(i)} onClick={()=>setDetailedGames(Boolean(i))}>{label}</button>)}</div></div>{!gameLogLoading && gameLog.games?.length ? <div className="profile-game-scroll"><table className="profile-season-games"><caption className="sr-only">시즌 전체 경기 기록</caption><thead><tr>{gameColumns.map(([label,key])=><th key={key} scope="col">{label}</th>)}</tr></thead><tbody>{gameLog.games.map(game=><tr key={game.gameId}>{gameColumns.map(([,key])=><td key={key} className={key === 'summary' || key === 'badge' ? 'profile-record-cell' : undefined}>{gameCell(game,key)}</td>)}</tr>)}</tbody></table></div> : gameLogLoading ? <ProfileLoading>경기 일지를 불러오는 중이에요.</ProfileLoading> : <p className="profile-games-empty">{gameLogError || '경기 기록이 없습니다.'}</p>}</section>}
            {tab === 0 && <PlayerMovements key={`movements-${pid}`} movements={player.Movements} />}
            {tab === 0 && awards.length > 0 && <section><div className="profile-heading"><h2>수상 경력</h2></div><div className="profile-awards">{awards.map(award => <div key={award.name}><strong className="profile-award-name">{awardImages[award.name] && <img className="profile-award-image" src={awardImages[award.name]} alt="" loading="lazy" />}{award.name}</strong><span className="profile-award-count">{award.name === '우승' ? `V${award.rows.length}` : `${award.rows.length}회`}</span><span className="profile-award-years">{[...new Set(award.rows.map(row => row.year).filter(Boolean))].join(', ')}</span>{award.name !== '신인왕' ? <button type="button" aria-label={`${award.name} 상세 내역`} onClick={() => setSelectedCareer({ item: award, kind: 'award' })}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg></button> : <span />}</div>)}</div></section>}
            {tab === 0 && national.length > 0 && <section><div className="profile-heading"><h2>국가대표 경력</h2></div><div className="profile-awards">{national.map(item=><div key={item.name}><strong className="profile-award-name">{nationalImages[item.name] && <img className="profile-award-image" src={nationalImages[item.name]} alt="" loading="lazy" />}{item.name}</strong><span className="profile-award-count">{item.rows.length}회</span><span className="profile-award-years">{[...new Set(item.rows.map(row=>row.year).filter(Boolean))].join(', ')}</span><button type="button" aria-label={`${item.name} 상세 내역`} onClick={() => setSelectedCareer({ item, kind: 'national' })}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg></button></div>)}</div></section>}
            {tab === 0 && <section><div className="profile-heading"><h2>선수 정보</h2></div><dl className="profile-info">{info.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>}
            {(chartVisited || tab === 3) && <ProfileCandleChart key={`candle-chart-${pid}`} pid={pid} player={player} active={tab === 3} />}
            {tab === 4 && <PlayerCompare key={`compare-${pid}`} pid={pid} player={player} getTeamLogo={movementLogo} getTeamColor={compareTeamColor} />}
        </div>
        {selectedCareer && <CareerModal item={selectedCareer.item} kind={selectedCareer.kind} onClose={() => setSelectedCareer(null)} />}
    </main>;
}
