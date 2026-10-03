import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import './player-profile.css';
import PlayerSilhouette from '../../components/PlayerSilhouette';
import { legacyTeams, legacyTeamColor, teamCapByCode, eraTeamCode, teamCapLogoByCode, teamLogoByCode, teamSmallLogoByCode } from '../../lib/teamAssets';
import YearRecords, { allStarSide } from './YearRecords';
import GameLogFilter from './GameLogFilter';
import ProfileCandleChart from './ProfileCandleChart';
import PlayerCompare from './PlayerCompare';
import PlayerGroupModal, { alumniSchoolChunks, parseDraft } from './PlayerGroupModal';
import ProfileLoading from './ProfileLoading';
import { getCachedProfileData, loadProfileData } from './profileDataCache';
import useTableDrag from './useTableDrag';
import useSheetDrag from './useSheetDrag';
import useStickyTableHead from './useStickyTableHead';
import { recordColumnWidth } from './recordTableLayout';
import { loadYearRecords } from './yearRecordsCache';
import { teamFullName } from '../../lib/teamFullName';
import { addRecentPlayer } from '../../lib/recentPlayers';
import kboSmallLogo from '../../assets/images/s-logos/kbo-white-small.svg';
import ulsanLogo from '../../assets/images/logos/ulsan-logo.png';
import ulsanSmallLogo from '../../assets/images/s-logos/ulsan-small-logo.png';
import npbLogo from '../../assets/images/logos/npb-logo.png';
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
const nationalTeamLogos = import.meta.glob('../../assets/images/logos/*.{svg,webp,png,jpg}', { eager: true, query: '?url', import: 'default' });
// 국가대표팀 로고와 헤더 색. 파일은 logos 폴더의 <code>.<ext> / <code>-small.<ext>, 5번째 값은 흰색 로고 여부,
// 6번째 값은 큰 로고가 어두운 단색이라 헤더 배경에서는 흰색으로 바꿔 깔아야 하는지 여부
const nationalTeams = {
    한국: ['korea', '대한민국', '#0d2a5c', '#c8102e', true], 대한민국: ['korea', '대한민국', '#0d2a5c', '#c8102e', true],
    일본: ['japan', '일본', '#1c262d', '#918049', false, true], 대만: ['taiwan', '대만', '#0b3270', '#2f6fd0'], 중국: ['china', '중국', '#8f0d1d', '#d62a2a'],
    미국: ['usa', '미국', '#0a3161', '#b31942'], 캐나다: ['canada', '캐나다', '#7a0c1c', '#d52b1e'], 멕시코: ['mexico', '멕시코', '#004d35', '#ce1126'],
    호주: ['australia', '호주', '#00573a', '#d4a300'], 이스라엘: ['israel', '이스라엘', '#0b2f8a', '#3a6fe0'],
    파나마: ['panama', '파나마', '#0c2a55', '#c41e2a'], 베네수엘라: ['venezuela', '베네수엘라', '#5e1410', '#e0a916'],
};
const nationalTeam = country => nationalTeams[String(country || '한국').trim()] || null;
const nationalTeamLogo = (country, small = false) => {
    const code = nationalTeam(country)?.[0];
    if (!code) return null;
    const find = names => names.flatMap(name => ['svg', 'webp', 'png', 'jpg'].map(ext => nationalTeamLogos[`../../assets/images/logos/${name}.${ext}`])).find(Boolean) || null;
    return find(small ? [`${code}-small`, code] : [code, `${code}-small`]);
};
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
    return <section><div className="profile-heading"><h2>연속 경기 기록</h2></div>
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
    ['move', '이적·계약', ['입단', '트레이드', '트레이드(웨이버)', 'FA 자격취득', 'FA 계약', '비FA 다년계약', '자유계약', '해외 복귀 FA 계약', 'FA 보상선수', '2차 드래프트', '소속선수 추가 등록']],
    ['release', '방출·은퇴', ['자유계약선수', '웨이버', '은퇴', '자유계약선수 - 참가활동정지']],
    ['injury', '부상', ['부상자 명단', '치료·재활명단', '재활선수(외국인 선수)']],
    ['military', '군보류', ['군보류', '군보류 자유계약선수']],
    ['number', '등번호', ['등번호 변경']],
    ['etc', '기타', []],
];
// 군보류 해제로 다시 등록된 경우(소속선수 추가 등록 + 비고 '군보류 해제')는 군보류로 묶는다.
// 임의해지(옛 임의탈퇴)와 임의해지 복귀(종류나 비고에 '임의해지 복귀')는 기타로 본다.
const movementCategory = movement => (movement.note || '').includes('군보류 해제') ? 'military' : /임의해지\s*복귀/.test(`${movement.type || ''} ${movement.note || ''}`) ? 'etc' : movementCategories.find(([, , types]) => types.includes(movement.type))?.[0] || 'etc';
const MOVEMENT_PREVIEW_COUNT = 6;
const movementYear = movement => Number(String(movement.date || '').slice(0, 4)) || undefined;
const movementRoute = movement => movement.type === '등번호 변경' ? null : (movement.note || '').trim().match(/^([^→\s]+)\s*→\s*([^→\s]+)$/);
// 현재 팀 + 옛 팀(해태·OB·넥센·SK 등)의 작은 로고와 색. 옛 팀 목록은 lib/teamAssets.js에 있다.
const legacyMovementTeams = Object.fromEntries(Object.entries(legacyTeams).map(([name, [code]]) => [name, code]));
const compareTeamColor = team => teams[team]?.[1] || legacyTeamColor(team);
// 시대에 따라 로고가 다른 팀(2000~2005년 SK 등)은 연도를 주면 그때 로고를 쓴다.
const movementTeamCode = (team, year) => eraTeamCode(team, year) || teams[team]?.[0] || legacyMovementTeams[team];
function movementLogo(team, year) {
    if (team === 'MLB' || team?.startsWith('美')) return logos['../../assets/images/logos/mlb-logo.svg'] || null;
    if (team?.startsWith('日')) return npbLogo;
    const code = movementTeamCode(team, year);
    if (!code) return null;
    if (code === 'ulsan') return ulsanSmallLogo;
    return teamSmallLogoByCode(code) || teamLogoByCode(code);
}
function MovementTeam({ team, year, withName = false }) {
    // 기본은 로고만, 팀 간 이동 경로(트레이드 등)는 로고와 팀 이름을 함께 보여준다. 로고가 없으면 이름만 쓴다.
    const src = movementLogo(team, year);
    if (src && withName) return <span className="profile-movement-team is-named"><img className="profile-movement-logo" src={src} alt="" />{team}</span>;
    return src ? <span className="profile-movement-team" title={team} role="img" aria-label={team}><img className="profile-movement-logo" src={src} alt="" /></span>
        : <span className="profile-movement-team is-text">{team}</span>;
}
// 계약 금액(원) → "80억", "24.5억", "6500만"
function formatContractAmount(amount, currency = 'KRW') {
    const value = Number(amount);
    if (!value) return null;
    if (currency === 'USD') return `$${value.toLocaleString('ko-KR')}`;
    if (currency === 'JPY') return `¥${value.toLocaleString('ko-KR')}`;
    if (currency && currency !== 'KRW') return `${value.toLocaleString('ko-KR')} ${currency}`;
    if (value >= 1e8) { const eok = value / 1e8; return `${Number.isInteger(eok) ? eok : eok.toFixed(1).replace(/\.0$/, '')}억`; }
    return `${Math.round(value / 1e4).toLocaleString('ko-KR')}만`;
}
function contractSources(movement) {
    return [...new Set((movement.contractSources || '').split(/\s*\n\s*/).filter(url => {
        try { return ['http:', 'https:'].includes(new URL(url).protocol); } catch { return false; }
    }))];
}
function contractSourceLabel(url) {
    const source = new URL(url);
    if (/KBO_FILE\/ebook/.test(source.pathname)) return 'KBO 연감';
    if (source.hostname.endsWith('koreabaseball.com')) return 'KBO 공식';
    return source.hostname.replace(/^www\./, '');
}
function hasContract(movement) { return Boolean(movement.contractTerm || movement.contractTotal || contractSources(movement).length); }
function MovementLine({ movement, open = false, onToggle }) {
    const route = movementRoute(movement);
    const note = (movement.note || '').trim();
    const contract = hasContract(movement);
    const expandable = Boolean(movement.trade) || (contract && Boolean(movement.contractDetails || contractSources(movement).length));
    const toggleName = movement.trade ? '트레이드 상세' : '계약 상세';
    let detail = null;
    if (route) {
        detail = <span className="profile-movement-route" aria-label={`${route[1]}에서 ${route[2]}로`}><MovementTeam team={route[1]} year={movementYear(movement)} withName /><i aria-hidden="true">→</i><MovementTeam team={route[2]} year={movementYear(movement)} withName /></span>;
    } else if (contract && (movement.contractTerm || movement.contractTotal)) {
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
        {expandable && <button type="button" className="profile-movement-toggle" aria-expanded={open} aria-label={open ? `${toggleName} 접기` : `${toggleName} 보기`} onClick={onToggle}>
            <span>{open ? '접기' : '상세'}</span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </button>}
    </div>;
}
function MovementContractDetail({ movement }) {
    const sources = contractSources(movement);
    return <div className="profile-movement-contract-detail">
        {movement.contractDetails && <p>{movement.contractDetails}</p>}
        {sources.length > 0 && <div className="profile-movement-sources"><span>출처</span>{sources.map(url => <a key={url} href={url} target="_blank" rel="noopener noreferrer">{contractSourceLabel(url)}<svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></a>)}</div>}
    </div>;
}
// 트레이드 상세: 팀별로 내준 선수·현금·지명권. 다른 선수는 그 선수 페이지로 이어진다.
function MovementTradeDetail({ movement, pid }) {
    const { trade } = movement;
    const year = movementYear(movement);
    const sides = [];
    for (const asset of trade.assets || []) {
        const key = `${asset.from}→${asset.to}`;
        let side = sides.find(item => item.key === key);
        if (!side) sides.push(side = { key, from: asset.from, to: asset.to, assets: [] });
        side.assets.push(asset);
    }
    const toTop = () => window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    const chevron = <svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" /></svg>;
    const assetChip = (asset, index) => {
        if (asset.type === 'player') {
            const name = asset.playerName || asset.text;
            if (asset.playerId && String(asset.playerId) !== String(pid)) return <Link key={index} className="profile-trade-asset is-player" to={`/?pid=${encodeURIComponent(asset.playerId)}`} onClick={toTop}>{name}{chevron}</Link>;
            return <span key={index} className={`profile-trade-asset is-player${asset.playerId ? ' is-self' : ''}`}>{name}</span>;
        }
        if (asset.type === 'cash') return <span key={index} className="profile-trade-asset is-cash">현금 {asset.text}</span>;
        if (asset.type === 'draft_pick') return <span key={index} className="profile-trade-asset is-pick">{asset.text}{asset.drafteeId && asset.drafteeName && <Link to={`/?pid=${encodeURIComponent(asset.drafteeId)}`} onClick={toTop} title="이 지명권으로 뽑힌 선수">{asset.drafteeName}{chevron}</Link>}</span>;
        return <span key={index} className="profile-trade-asset">{asset.text}</span>;
    };
    return <div className="profile-movement-contract-detail profile-movement-trade">
        {sides.map(side => <div key={side.key} className="profile-trade-side">
            <span className="profile-movement-route" aria-label={`${side.from}에서 ${side.to}로`}><MovementTeam team={side.from} year={year} withName /><i aria-hidden="true">→</i><MovementTeam team={side.to} year={year} withName /></span>
            <div className="profile-trade-assets">{side.assets.map(assetChip)}</div>
        </div>)}
    </div>;
}
// 원형 마커 전용 시각 보정: 로고는 상자 기준으로 가운데지만 모양이 비대칭이라 쏠려 보인다.
// 면적 중심이 원 가운데에 가까워지도록 로고 상자 크기 대비 [가로%, 세로%]만큼 민다.
const markerLogoShift = { kia: [0, 8], ssg: [7, -6], nex: [6.5, -2], lg: [2.5, 5.5], lot: [3.5, 0], nc: [-3, 0], sk: [3.5, 0], kiw: [3, 0], doo: [0, 2.5] };
function MovementMarker({ team, year }) {
    const src = movementLogo(team, year);
    const shift = markerLogoShift[movementTeamCode(team, year)];
    return <span className="profile-movement-marker" title={team || undefined} aria-label={team || undefined} role={team ? 'img' : undefined}>
        {src ? <img className="profile-movement-marker-logo" src={src} alt="" style={shift && { transform: `translate(${shift[0]}%, ${shift[1]}%)` }} /> : team ? <b>{team.slice(0, 2)}</b> : null}
    </span>;
}
function PlayerMovements({ movements = [], pid }) {
    const [filter, setFilter] = useState('all');
    const [expanded, setExpanded] = useState(false);
    const [openRows, setOpenRows] = useState({});
    if (!movements.length) return null;
    const counts = movements.reduce((map, movement) => map.set(movementCategory(movement), (map.get(movementCategory(movement)) || 0) + 1), new Map());
    const filters = movementCategories.filter(([id]) => counts.has(id));
    const filtered = filter === 'all' ? movements : movements.filter(movement => movementCategory(movement) === filter);
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
            {visible.map((movement, index) => { const rowKey = `${movement.date}-${movement.type}-${movement.team}-${index}`; const open = Boolean(openRows[rowKey]); return <li key={rowKey} className={`is-${movementCategory(movement)}${hasContract(movement) ? ' has-contract' : ''}`}>
                <MovementMarker team={movement.team} year={movementYear(movement)} />
                <div className="profile-movement-body">
                    <time dateTime={movement.date}>{movement.date.replaceAll('-', '.')}</time>
                    <MovementLine movement={movement} open={open} onToggle={() => setOpenRows(rows => ({ ...rows, [rowKey]: !rows[rowKey] }))} />
                    {open && (movement.trade ? <MovementTradeDetail movement={movement} pid={pid} /> : <MovementContractDetail movement={movement} />)}
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
const recentWeekday = date => ['일', '월', '화', '수', '목', '금', '토'][new Date(`${date}T12:00:00+09:00`).getDay()] || '';
// 최근 경기 결과: 점수는 원정-홈 순서. 선수 기록이 주인공이라 결과는 승·패 글자에만 옅은 색을 준다.
const recentResult = game => {
    const match = game.result?.match(/^([WLD])\s+(.+)$/);
    if (!match) return null;
    const [label, tone] = { W: ['승', 'win'], L: ['패', 'loss'], D: ['무', 'draw'] }[match[1]];
    const score = match[2].match(/^(\d+)\s*[-:]\s*(\d+)$/);
    return { label, tone, text: match[2], score: score ? [score[1], score[2]] : null };
};
// 기록 문장에서 홈런과 의미 있는 기록(안타·타점 등)을 굵게 강조한다.
const recentLine = text => String(text || '—').split(' ').map((token, index) => <span key={index} className={/^[1-9]\d*홈런$/.test(token) ? 'is-hr' : /^[1-9]\d*(안타|타점|득점|도루|볼넷|삼진|이닝)$/.test(token) ? 'is-key' : undefined}>{token}</span>);
const positionSymbols = { '투수': 'P', '포수': 'C', '1루': '1B', '2루': '2B', '3루': '3B', '유격': 'SS', '좌익': 'LF', '중견': 'CF', '우익': 'RF', '지명': 'DH' };
const substituteRoles = ['대타', '대주자', '교체', '대수비'];
const positionNames = { '투수': '투수', '포수': '포수', '1루': '1루수', '2루': '2루수', '3루': '3루수', '유격': '유격수', '좌익': '좌익수', '중견': '중견수', '우익': '우익수', '지명': '지명타자' };
// 최근 경기의 타순·선발 포지션. 교체 출장이면 포지션 없이 대타·대수비·대주자만 보여준다.
const recentRole = game => {
    const parts = (game.position || '').split(' · ');
    if (substituteRoles.includes(parts[0])) return { label: parts[0], position: '' };
    if (game.isStarter === false) return { label: '', position: '' };
    const position = (game.position || '').split('-')[0];
    return { label: game.order != null ? `${game.order}번` : '', position: positionNames[position] || position };
};
const bestNationalResult = rows => {
    const order = ['gold', 'silver', 'bronze'];
    return rows.map(row => ({ tone: careerResultTone(row.note), note: row.note })).filter(row => row.tone !== 'plain').sort((a, b) => order.indexOf(a.tone) - order.indexOf(b.tone))[0] || null;
};
function CareerTeam({ team, year }) {
    if (!team) return null;
    const src = movementLogo(team, Number(year) || undefined);
    return <span className="profile-career-team">{src && <img src={src} alt="" />}{team}</span>;
}
function CareerModal({ item, kind, onClose }) {
    const { dialog, expanded, close, justDragged, handlers: dragHandlers } = useSheetDrag();
    const combined = kind === 'national-all';
    const national = kind === 'national' || combined;
    const countries = [...new Set(item.rows.map(row => row.country || '한국'))];
    const multiCountry = countries.length > 1;
    const mainCountry = national ? (item.rows[item.rows.length - 1]?.country || countries[0] || '한국') : null;
    const team = national ? nationalTeam(mainCountry) : null;
    const countryName = team?.[1] || mainCountry;
    const countryLogo = national ? nationalTeamLogo(mainCountry) : null;
    const countryMark = national ? nationalTeamLogo(mainCountry, true) : null;
    const image = combined ? countryMark : national ? nationalImages[item.name] : awardImages[item.name];
    const headStyle = team ? { '--career-head-a': team[2], '--career-head-b': team[3] } : undefined;
    const years = item.rows.map(row => Number(row.year)).filter(Boolean);
    const span = years.length ? (Math.min(...years) === Math.max(...years) ? `${Math.min(...years)}` : `${Math.min(...years)} – ${Math.max(...years)}`) : null;
    const count = item.name === '우승' ? `V${item.rows.length}` : `${item.rows.length}회`;
    return <dialog ref={dialog} className={`profile-career-modal${expanded ? ' is-expanded' : ''}`} aria-labelledby="profile-career-title" onClose={onClose} onClick={e => { if (e.target !== e.currentTarget || justDragged.current) return; const box = e.currentTarget.getBoundingClientRect(); if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) close(); }}>
        <header className={`profile-career-modal-head${national && team ? ' is-national' : ''}`} style={headStyle} {...dragHandlers}>
            {national && countryLogo && <img className={`profile-career-watermark${team?.[5] ? ' is-dark-logo' : ''}`} src={countryLogo} alt="" aria-hidden="true" />}
            <div className={`profile-career-emblem${combined && countryMark ? ' is-country' : ''}${combined && team?.[4] ? ' is-light-logo' : ''}`}>{image ? <img src={image} alt="" /> : <span aria-hidden="true">{combined ? countryName.slice(0, 2) : item.name.slice(0, 1)}</span>}</div>
            <div className="profile-career-title">
                {!combined && <small>{national ? `${countryName} 국가대표` : '수상 경력'}</small>}
                <h2 id="profile-career-title">{combined ? (multiCountry ? countries.map(c => nationalTeam(c)?.[1] || c).join(' · ') : `${countryName} 국가대표`) : item.name}</h2>
                <p><strong>{count}</strong>{span && <span>{span}</span>}</p>
            </div>
            <button type="button" className="profile-career-close" aria-label="닫기" onClick={close}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg></button>
        </header>
        <ol className="profile-career-list">
            {item.rows.map((row, index) => <li key={index}>
                <span className="profile-career-year">{row.year || '—'}{row.month && <small>{row.month}월</small>}</span>
                <span className="profile-career-detail">
                    {combined && <span className="profile-career-tournament">{nationalImages[row.type] && <img src={nationalImages[row.type]} alt="" />}{row.type}</span>}
                    {national
                        ? <>{(multiCountry || !combined) && <span className={`profile-career-chip profile-career-country${nationalTeam(row.country)?.[4] ? ' is-light-logo' : ''}`} style={nationalTeam(row.country) ? { '--country-color': nationalTeam(row.country)[2] } : undefined}>{nationalTeamLogo(row.country, true) && <img src={nationalTeamLogo(row.country, true)} alt="" />}{nationalTeam(row.country)?.[1] || row.country || '한국'}</span>}<span className={`profile-career-result is-${careerResultTone(row.note)}`}>{careerResultTone(row.note) !== 'plain' && <i aria-hidden="true" />}{row.note || '대표 선발'}</span></>
                        : <><CareerTeam team={row.team} year={row.year} />{row.type === '올스타' && allStarSide(row.team, row.year) && <span className="profile-career-chip">{allStarSide(row.team, row.year)} 올스타</span>}{row.pos && <span className="profile-career-chip">{row.pos}</span>}{row.note && <span className="profile-career-note">{row.type === '올스타' && row.note === 'MVP' ? '미스터 올스타' : row.note}</span>}</>}
                </span>
            </li>)}
        </ol>
    </dialog>;
}
// 최근 7·15·30일 요약: 대표 지표(타율/ERA)를 크게 보여주고 시즌 기록과 비교해 상승·하락을 표시한다.
function RollingSummary({ rows = [], pitcher = false, stats = [] }) {
    if (!rows.length) return null;
    const seasonValue = names => { const found = (stats || []).find(([label]) => names.includes(label)); const value = found ? Number(found[1]) : NaN; return Number.isFinite(value) ? value : null; };
    const main = pitcher ? { key: 'era', label: 'ERA', season: seasonValue(['ERA', '평균자책점']), digits: 2, lowerBetter: true } : { key: 'avg', label: '타율', season: seasonValue(['타율']), digits: 3, lowerBetter: false };
    const shortDate = date => date ? date.slice(5).replace('-', '.') : '';
    return <div className="profile-rolling-cards">{rows.map(row => {
        const raw = row[main.key];
        const value = raw === null || raw === undefined || raw === '' ? null : Number(raw);
        const played = Number(row.games) > 0 && value !== null && Number.isFinite(value);
        const delta = played && main.season !== null ? value - main.season : null;
        const flat = delta === null || Math.abs(delta) < (pitcher ? 0.005 : 0.0005);
        const tone = !played ? 'empty' : flat ? 'flat' : (main.lowerBetter ? delta < 0 : delta > 0) ? 'hot' : 'cold';
        const detail = pitcher
            ? [['경기', row.games ?? 0], ['이닝', row.innings], ['삼진', row.so], ['WHIP', row.whip]]
            : [['경기', row.games ?? 0], ['안타', row.h ?? 0], ['홈런', row.hr ?? 0], ['OPS', row.ops]];
        return <article key={row.label} className={`profile-rolling-card is-${tone}`}>
            <header><strong>{row.label}</strong><small>{shortDate(row.startDate)}–{shortDate(row.endDate)}</small></header>
            <div className="profile-rolling-value"><span>{main.label}</span><b>{played ? value.toFixed(main.digits) : '—'}</b></div>
            <p className="profile-rolling-delta">{!played ? '경기 없음' : delta === null ? '\u00a0' : flat ? '시즌 평균 수준' : <><span className="profile-rolling-delta-label">시즌 대비 </span><em>{delta > 0 ? '▲' : '▼'} {Math.abs(delta).toFixed(main.digits)}</em></>}</p>
            {played && <dl className="profile-rolling-detail">{detail.filter(([, value]) => value != null && value !== '').map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>}
        </article>;
    })}</div>;
}
const heroIntroPlayed = new Set();
export default function PlayerProfile({ pid }) {
    const rollingScrollRef = useTableDrag();
    const gameScrollRef = useTableDrag();
    const gameStickyRef = useStickyTableHead(true);
    const location = useLocation();
    const navigate = useNavigate();
    const tabNames = ['', 'record', 'game', 'chart', 'compare'];
    const tabFromUrl = () => Math.max(0, tabNames.indexOf(new URLSearchParams(location.search).get('tab') || ''));
    const initial = String(location.state?.player?.PlayerId) === String(pid) ? location.state.player : null;
    const [selectedCareer, setSelectedCareer] = useState(null);
    // 선수 묶음 모달: 같은 학교·리틀야구단, 같은 해 입단, 같은 생일 ({ type, value })
    const [playerGroup, setPlayerGroup] = useState(null);
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
    // 경기 일지 타자·투수 선택. null이면 서버가 등록 포지션을 따른다.
    const [gamePitcher, setGamePitcher] = useState(null);
    const [gameLog, setGameLog] = useState({ years: [], games: [] });
    const [gameLogLoading, setGameLogLoading] = useState(false);
    const [gameLogError, setGameLogError] = useState('');
    const [photoIndex, setPhotoIndex] = useState(0);
    const [compact, setCompact] = useState(false);
    const heroRef = useRef(null);
    // 어떤 경로로 들어왔든 선수 페이지를 열면 검색 화면의 '최근 본 선수'에 남긴다.
    useEffect(() => {
        if (player?.Name && String(player.PlayerId) === String(pid)) addRecentPlayer(player);
    }, [pid, player?.PlayerId, player?.Name, player?.Team, player?.FormerTeam]);
    // hero 등장 애니메이션은 선수 페이지에 처음 들어왔을 때 한 번만 재생한다(탭 이동 시 반복 X).
    const [heroIntro, setHeroIntro] = useState(() => !heroIntroPlayed.has(String(pid)));
    useEffect(() => {
        if (heroIntroPlayed.has(String(pid))) { setHeroIntro(false); return undefined; }
        heroIntroPlayed.add(String(pid));
        setHeroIntro(true);
        const timer = window.setTimeout(() => setHeroIntro(false), 2200);
        return () => window.clearTimeout(timer);
    }, [pid]);
    const contentRef = useRef(null);
    useEffect(() => { setTab(tabFromUrl()); }, [location.search]);
    useEffect(() => { setGameYear(''); setGameSeason(''); setGamePitcher(null); setGameLog({ years: [], games: [] }); }, [pid]);
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
        if (gamePitcher !== null) query.set('type', gamePitcher ? 'pitcher' : 'batter');
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
    }, [pid, tab, gameYear, gameSeason, gamePitcher]);
    useEffect(() => {
        if (!player) return;
        const hero = heroRef.current;
        const shell = hero.closest('.player-profile-shell');
        const nav = shell?.querySelector('.site-navbar');
        const compactHeader = shell?.querySelector('.profile-compact-header');
        let frame = 0;
        const update = () => {
            frame = 0;
            const navHeight = window.innerWidth < 800 ? 0 : nav?.getBoundingClientRect().height || 0;
            shell?.style.setProperty('--profile-nav-height', `${navHeight}px`);
            // 표 머리글을 축약 헤더 바로 아래에 붙이기 위한 높이(연도별 기록)
            shell?.style.setProperty('--profile-compact-height', `${compactHeader?.getBoundingClientRect().height || 0}px`);
            const rect = hero.getBoundingClientRect();
            setCompact(rect.bottom <= navHeight + 52);
        };
        const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
        const observer = new ResizeObserver(schedule);
        observer.observe(hero);
        if (nav) observer.observe(nav);
        if (compactHeader) observer.observe(compactHeader);
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        update();
        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            shell?.style.removeProperty('--profile-nav-height');
            shell?.style.removeProperty('--profile-compact-height');
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
    // 부포지션이 있으면 주포지션과 나눠서 보여준다.
    const positionList = value => [...new Set((value || '').split(',').map(item => item.trim()).filter(Boolean))].map(positionText);
    const subPositions = positionList(player.SubPos).filter(item => !positionList(player.MainPos).includes(item));
    const detailedPosition = mainPosition && subPositions.length
        ? <span className="profile-positions"><span><small>주</small>{positionList(player.MainPos).join(', ')}</span><span><small>부</small>{subPositions.join(', ')}</span></span>
        : mainPosition ? extraPositions.map(positionText).join(', ') : player.Pos;
    const heroPosition = positionText(mainPosition) || player.Pos;
    const retirementYear = player.Retire || records?.year;
    const formerTeam = player.FormerTeam ? teamFullName(player.FormerTeam) : null;
    const heroSummary = [retired || numberRetired ? '은퇴' : team[3], heroPosition].filter(Boolean).join(' · ');
    const handedness = [player.Throws, player.Bat].filter(Boolean).join('');
    const birth = player.Birth;
    const birthMatch = birth?.match(/^(\d{4})[-.](\d{2})[-.](\d{2})$/);
    const dateParts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Seoul', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    const dateValue = key => dateParts.find(part => part.type === key).value;
    const age = birthMatch ? Number(dateValue('year')) - Number(birthMatch[1]) - (`${dateValue('month')}-${dateValue('day')}` < `${birthMatch[2]}-${birthMatch[3]}` ? 1 : 0) : null;
    const birthday = birth ? `${birth.replaceAll('-', '.')}${age !== null ? ` (${age}세)` : ''}` : null;
    // 오늘(한국 시간)이 생일이면 선수 정보의 생년월일 뒤에 케이크를 붙인다.
    const todayMonthDay = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Seoul', month: '2-digit', day: '2-digit' }).format(new Date());
    const isBirthday = Boolean(birth) && String(birth).slice(5, 10) === todayMonthDay;
    const groupCareer = rows => Object.entries((rows || []).reduce((groups, row) => { (groups[row.type] ||= []).push(row); return groups; }, {})).map(([name, rows]) => ({ name, rows: rows.sort((a, b) => Number(a.year) - Number(b.year) || Number(a.month) - Number(b.month)) }));
    const awards = groupCareer(player.Awards).sort((a,b)=>(awardOrder.indexOf(a.name) < 0 ? 99 : awardOrder.indexOf(a.name))-(awardOrder.indexOf(b.name) < 0 ? 99 : awardOrder.indexOf(b.name)));
    const national = groupCareer(player.National).sort((a, b) => nationalOrder.indexOf(a.name) - nationalOrder.indexOf(b.name));
    const birthMonthDay = /^\d{4}-\d{2}-\d{2}/.test(String(player.Birth || '')) ? String(player.Birth).slice(5, 10) : null;
    const draftYear = parseDraft(player.Draft)?.year;
    const info = [['이름', Number(player.IsForeign) === 1 ? player.FullName || player.Name : player.Name], ['개명', player.OldName ? `${player.OldName} → ${player.Name}` : null], ['등번호', numberRetired ? null : player.BackNo], ['소속팀', retired || numberRetired ? null : team[3]], ['영구결번', numberRetired ? `${team[3]}${player.BackNo != null ? ` No.${player.BackNo}` : ''}` : null], ['투타', handedness], ['포지션', detailedPosition], ['생년월일', birthday && birthMonthDay ? <span className="profile-fact-action">{birthday}<button type="button" onClick={() => setPlayerGroup({ type: 'birthday', value: birthMonthDay })}>같은 생일</button></span> : birthday], ['신체', player.Body], ['학력', player.School ? <span className="profile-schools">{String(player.School).split('-').map((part, index) => <span key={index}>{index > 0 && <i aria-hidden="true">-</i>}{alumniSchoolChunks(part).map((chunk, order) => chunk.name ? <button key={order} type="button" className="profile-school-link" title={`${chunk.name} ${/리틀$/.test(chunk.name) ? '출신' : '동문'} 선수 보기`} onClick={() => setPlayerGroup({ type: 'school', value: chunk.name })}>{chunk.text}</button> : chunk.text)}</span>)}</span> : null], ['입단', player.Draft && draftYear ? <span className="profile-fact-action">{player.Draft}<button type="button" onClick={() => setPlayerGroup({ type: 'draft', value: String(draftYear) })}>입단 동기</button></span> : player.Draft], ['은퇴', retired && retirementYear ? `${retirementYear}년` : null], ['별명', player.Nicknames?.join(', ')], ['가족', player.Family?.length ? <span className="profile-family">{player.Family.map(member => <Link key={`${member.PlayerId}-${member.Relationship}`} className="profile-family-link" to={`/?pid=${encodeURIComponent(member.PlayerId)}`} onClick={() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' })}><small>{member.Relationship}</small><b>{member.Name}</b><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" /></svg></Link>)}</span> : null]].filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '');
    const gameLogPitcher = gamePitcher ?? gameLog.pitcher ?? isPitcher;
    const gameColumns = [['날짜','date'],['상대','opponent'],['구장','stadium'],['경기 결과','result'],['선발','isStarter'],...((gameLog.pitcher ?? records?.pitcher) ? (detailedGames ? [['기록','badge'],['이닝','innings'],['실점','r'],['자책','er'],['삼진','so'],['피안타','h'],['피홈런','hr'],['볼넷','bb'],['사구','hbp']] : [['기록','summary']]) : [['포지션','position'],['타순','order'],...(detailedGames ? [['타석','pa'],['타수','ab'],['안타','h'],['2루타','doubles'],['3루타','triples'],['홈런','hr'],['타점','rbi'],['득점','r'],['볼넷','bb'],['사구','hbp'],['삼진','so'],['병살','gdp'],['희플','sf'],['희생번트','sh'],['도루','sb'],['도루실패','cs']] : [['기록','summary']])]),['비고','notes']];
    const rollingColumns = [['기간','label'],['경기','games'],...(records?.pitcher ? [['선발','starts'],['ERA','era'],['승리','wins'],['패전','losses'],['세이브','saves'],['홀드','holds'],['이닝','innings'],['실점','r'],['자책','er'],['삼진','so'],['피안타','h'],['피홈런','hr'],['볼넷','bb'],['사구','hbp'],['WHIP','whip']] : [['타석','pa'],['타율','avg'],['타수','ab'],['안타','h'],['2루타','doubles'],['3루타','triples'],['홈런','hr'],['타점','rbi'],['득점','r'],['볼넷','bb'],['사구','hbp'],['희플','sf'],['희생번트','sh'],['병살','gdp'],['도루','sb'],['도루자','cs'],['출루율','obp'],['장타율','slg'],['OPS','ops'],['OPS+','opsPlus']])];
    const futuresCaption = overviewLeagueCaption(records, Number(dateValue('year')));
    const recentCaption = futuresCaption || (records?.year < Number(dateValue('year')) ? `${records.year} 시즌` : '');
    const rollingMobileWidths = rollingColumns.map(([label, key]) => key === 'label' ? 64 : recordColumnWidth(label, key, true));
    const gameCell = (game, key) => {
        const positionParts = (game.position || '').split(' · ');
        const substituteRole = substituteRoles.includes(positionParts[0]) ? positionParts[0] : null;
        if (key === 'position') {
            const position = substituteRole ? positionParts.slice(1).join(' · ') : game.position;
            return position ? position.split('-').map(value => positionSymbols[value] || value).join('-') : '—';
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
            // 결과 점수는 원정-홈 순서. 내 팀 점수에 승(빨강)·패(파랑) 색을 입힌다.
            const score = match[2].match(/^(\d+)\s*[-:]\s*(\d+)$/);
            const ownIndex = game.isAway ? 0 : 1;
            const scoreText = score ? <span className="profile-season-score">{[score[1], score[2]].map((value, index) => <span key={index} className={index === ownIndex ? `is-own ${className}` : undefined}>{value}</span>).reduce((parts, part, index) => index ? [...parts, <i key={`sep-${index}`}>-</i>, part] : [part], [])}</span> : <span>{match[2]}</span>;
            return <span className="profile-season-result"><span className={className}>{label}</span>{scoreText}</span>;
        }
        return key === 'date' ? game.date.slice(5) : key === 'opponent' ? (game.opponent ? `${game.isAway ? '@' : ''}${game.opponent}` : '—') : key === 'isStarter' ? (game.isStarter === null || game.isStarter === undefined ? '—' : game.isStarter ? <span aria-label="선발">✓</span> : '') : game[key] ?? '—';
    };
    const photos = [`/assets/images/player/kbo/${encodeURIComponent(player.PlayerId)}.jpg`, `/assets/images/player/kbo/${encodeURIComponent(player.PlayerId)}.png`];
    // 사진이 없으면 실루엣에 팀 모자를 씌운다. 은퇴 선수는 마지막 소속팀 모자를 쓴다.
    const capTeam = String((retiredTheme ? player.FormerTeam : teamCode) || '').toUpperCase();
    const capCode = movementTeamCode(capTeam, retiredTheme ? Number(retirementYear) : undefined);
    const cap = teamCapByCode(capCode);
    const photo = photoIndex >= photos.length ? <PlayerSilhouette className="profile-photo-fallback" logo={cap?.noLogo ? null : capCode === 'ulsan' ? movementLogo(capTeam) : teamCapLogoByCode(capCode)} cap={cap} /> : <img src={photos[photoIndex]} alt={player.Name} onError={() => setPhotoIndex(i => i + 1)} />;
    const watermark = retiredTheme ? <img className="profile-watermark" src={kboSmallLogo} alt="" /> : <TeamLogo team={teamCode} className="profile-watermark" />;
    const compactWatermark = retiredTheme ? <img className="profile-watermark" src={kboSmallLogo} alt="" /> : <TeamLogo small team={teamCode} className="profile-watermark" />;
    const tabs = <div className="profile-tabs" style={{ '--active-tab': tab }} role="group" aria-label="선수 정보 보기"><span className="profile-tab-indicator" aria-hidden="true" />{['개요', '기록', '경기', '차트', '비교'].map((name, i) => <button key={name} type="button" aria-pressed={tab === i} className={tab === i ? 'active' : ''} onClick={() => { setTab(i); const params = new URLSearchParams(location.search); if (i === 0) params.delete('tab'); else params.set('tab', tabNames[i]); navigate(`${location.pathname}?${params.toString()}`, { state: location.state }); window.scrollTo({ top: 0, left: 0, behavior: 'instant' }); }}>{name}</button>)}</div>;
    return <main className="player-profile" style={{ '--team-primary': team[1], '--team-secondary': team[2], '--team-primary-light': heroPalette.light, '--team-primary-dark': heroPalette.dark, '--heading-primary': retiredTheme ? '#00b5e5' : team[1], '--heading-secondary': retiredTheme ? '#00d1c6' : team[2] }}>
        <section ref={heroRef} className={`profile-hero${heroIntro ? ' is-intro' : ''}`} aria-label="선수 소개">
            {watermark}
            <span className="profile-hero-spot" aria-hidden="true" /><span className="profile-hero-shine" aria-hidden="true" />
            <Link className="profile-back" to="/?search=1">← 선수 검색</Link>
            <div className="profile-identity">
                <div className="profile-photo">{photo}</div>
                <div className="profile-bio"><h1>{player.Name} {player.BackNo != null && <span>#{player.BackNo}</span>}</h1><p className="profile-hero-tags">{retiredTheme ? <span className="profile-hero-tag">은퇴</span> : <span className="profile-hero-tag is-team"><TeamLogo small team={teamCode} className="profile-hero-tag-logo" />{retired || numberRetired ? '은퇴' : team[3]}</span>}{heroPosition && <span className="profile-hero-tag">{heroPosition}</span>}</p>{(handedness || birthday) && <p>{[handedness, birthday && isBirthday ? `${birthday} 🎂` : birthday].filter(Boolean).join(' | ')}</p>}{numberRetired ? <p>{team[3]} 영구결번</p> : retired && formerTeam && <p>前 {formerTeam}</p>}{!retired && !numberRetired && player.Body && <p>{player.Body}</p>}</div>
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
            {records && records.year === Number(dateValue('year')) && tab === 1 && records.rolling && <section><div className="profile-heading"><h2>최근 성적</h2>{futuresCaption && <small className="profile-season-caption">{futuresCaption}</small>}</div><RollingSummary rows={records.rolling} pitcher={records.pitcher} stats={records.stats} /><div className="profile-game-scroll" ref={rollingScrollRef}><table className="profile-season-games profile-rolling-table" style={{ '--record-table-mobile-width': `${rollingMobileWidths.reduce((sum, width) => sum + width, 0)}px` }}><colgroup>{rollingMobileWidths.map((width, index) => <col key={index} style={{ '--record-column-mobile-width': `${width}px` }} />)}</colgroup><caption className="sr-only">최근 7·15·30일 성적</caption><thead><tr>{rollingColumns.map(([label,key])=><th key={key} scope="col">{label}</th>)}</tr></thead><tbody>{records.rolling.map(row=><tr key={row.label}>{rollingColumns.map(([,key])=><td key={key}>{row[key] ?? (['sf','sh'].includes(key) ? 0 : '—')}</td>)}</tr>)}</tbody></table></div></section>}
            {tab === 1 && <YearRecords key={`year-records-${pid}`} pid={pid} position={player.Pos} teams={teams} titles={player.Titleholders} awards={player.Awards} awardImages={awardImages} getTeamLogo={movementLogo} />}
            {tab === 1 && <TitleholderRecords rows={player.Titleholders} />}
            {records && tab === 0 && <section style={retiredTheme ? { '--team-primary': '#002561', '--team-secondary': '#286fcc' } : undefined}><div className="profile-heading"><h2>{records.career ? '통산 주요 기록' : `${records.year} 시즌 주요 기록`}</h2>{futuresCaption && <small className="profile-season-caption">{records.career ? '퓨처스리그' : futuresCaption}</small>}</div><div className={`profile-stats ${`profile-stats-ranked${tab === 0 ? ' profile-stats-overview' : ''}`}`}>{records.stats.map(([label, value]) => {
                const rank = value != null && Number(value) !== 0 ? ranks[label] : null;
                return <div key={label} className={rank >= 1 && rank <= 5 ? 'profile-stat-top-five' : undefined}>{tab === 0 ? <><div className="profile-stat-header"><span>{label}</span>{rank != null && <small className={`profile-stat-rank ${rank === 1 ? 'is-gold' : rank === 2 ? 'is-silver' : rank === 3 ? 'is-bronze' : rank <= 5 ? 'is-top' : ''}${rank >= 100 ? ' is-long' : ''}`}>{rank}위</small>}</div><strong style={{ '--value-chars': String(value ?? '—').length }}>{value ?? '—'}</strong></> : <><span>{label}</span><strong>{value ?? '—'}</strong>{<small>{rank != null ? `${rank}위` : ''}</small>}</>}</div>;
            })}</div></section>}
            {records && tab === 0 && !retired && !numberRetired && <section><div className="profile-heading"><h2>최근 5경기</h2>{recentCaption && <small className="profile-season-caption">{recentCaption}</small>}</div>{records.recent.length ? <ol className="profile-recent-list">{records.recent.map((game, i) => { const result = recentResult(game); const logo = movementLogo(game.opponent); const role = recentRole(game); return <li key={`${game.date}-${i}`}>
                <span className="profile-recent-date"><b>{game.date.slice(5).replace('-', '.')}</b><small>{recentWeekday(game.date)}</small></span>
                <span className="profile-recent-main"><span className="profile-recent-line">{role.label && <span className="profile-recent-role">{role.label}</span>}{recentLine(game.text)}<GameBadge value={game.badge} /></span><span className="profile-recent-vs">{!records.pitcher && <b>{role.position}</b>}{logo &&<img src={logo} alt="" />}{game.opponent ? `${game.isAway ? '@' : 'vs'} ${game.opponent}` : '—'}{game.stadium && <small>{game.stadium}</small>}</span></span>
                {result ? <span className={`profile-recent-score is-${result.tone}`}><b>{result.label}</b>{result.score ? result.score.join(':') : result.text}</span> : <span />}
            </li>; })}</ol> : <p className="profile-games-empty">최근 경기 기록이 없습니다.</p>}</section>}
            {tab === 2 && <StreakRecords streaks={gameLog.currentSeasonStreaks} pitcher={isPitcher} />}
            {tab === 2 && <section><div className="profile-heading profile-game-heading"><h2>경기 일지</h2><GameLogFilter years={gameLog.years} year={gameYear || gameLog.year} season={gameSeason || gameLog.season || 'regular'} availableSeasons={gameLog.availableSeasons} onYearChange={value => { setGameYear(value); const available = gameLog.availableSeasons?.[value] || []; setGameSeason(available.includes(gameSeason || gameLog.season) ? gameSeason || gameLog.season : available[0] || ''); }} onSeasonChange={value => { setGameYear(String(gameYear || gameLog.year)); setGameSeason(value); }} /><div className="profile-game-switches"><div className={`profile-game-switch ${gameLogPitcher ? 'is-detailed' : ''}`} role="group" aria-label="타자 투수 기록 선택">{['타자','투수'].map((label,i)=><button key={label} type="button" className={gameLogPitcher === Boolean(i) ? 'active' : ''} aria-pressed={gameLogPitcher === Boolean(i)} onClick={()=>{ if (gameLogPitcher === Boolean(i)) return; setGamePitcher(Boolean(i)); setGameYear(''); setGameSeason(''); }}>{label}</button>)}</div><div className={`profile-game-switch ${detailedGames ? 'is-detailed' : ''}`} role="group" aria-label="경기 기록 표시 방식">{['간략히','자세히'].map((label,i)=><button key={label} type="button" className={detailedGames === Boolean(i) ? 'active' : ''} aria-pressed={detailedGames === Boolean(i)} onClick={()=>setDetailedGames(Boolean(i))}>{label}</button>)}</div></div></div>{!gameLogLoading && gameLog.games?.length ? <><div className="profile-table-sticky-head" ref={gameStickyRef} aria-hidden="true"><div><table className="profile-season-games"><thead><tr>{gameColumns.map(([label,key])=><th key={key}>{label}</th>)}</tr></thead></table></div></div><div className="profile-game-scroll" ref={gameScrollRef}><table className="profile-season-games"><caption className="sr-only">시즌 전체 경기 기록</caption><thead><tr>{gameColumns.map(([label,key])=><th key={key} scope="col">{label}</th>)}</tr></thead><tbody>{gameLog.games.map(game=><tr key={game.gameId}>{gameColumns.map(([,key])=><td key={key} className={key === 'summary' || key === 'badge' ? 'profile-record-cell' : undefined}>{gameCell(game,key)}</td>)}</tr>)}</tbody></table></div></> : gameLogLoading ? <ProfileLoading>경기 일지를 불러오는 중이에요.</ProfileLoading> : <p className="profile-games-empty">{gameLogError || '경기 기록이 없습니다.'}</p>}</section>}
            {tab === 0 && <PlayerMovements key={`movements-${pid}`} movements={player.Movements} pid={pid} />}
            {tab === 0 && awards.length > 0 && <section><div className="profile-heading"><h2>수상 경력</h2></div><div className="profile-honors">{awards.map(award => { const clickable = award.name !== '신인왕'; const Tag = clickable ? 'button' : 'div'; return <Tag key={award.name} {...(clickable ? { type: 'button', 'aria-label': `${award.name} 상세 내역`, onClick: () => setSelectedCareer({ item: award, kind: 'award' }) } : {})} className={`profile-honor${['MVP', '우승', '골든글러브'].includes(award.name) ? ' is-premium' : ''}`}>
                <span className="profile-honor-icon">{awardImages[award.name] ? <img src={awardImages[award.name]} alt="" loading="lazy" /> : <b>{award.name.slice(0, 1)}</b>}</span>
                <span className="profile-honor-text"><strong>{award.name}</strong><small>{[...new Set(award.rows.map(row => row.year).filter(Boolean))].join(' · ')}</small></span>
                <span className="profile-honor-count">{award.name === '우승' ? `V${award.rows.length}` : <>{award.rows.length}<small>회</small></>}</span>
                {clickable && <svg className="profile-honor-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>}
            </Tag>; })}</div></section>}
            {tab === 0 && national.length > 0 && <section><div className="profile-heading"><h2>국가대표 경력</h2><button type="button" className="profile-heading-more" onClick={() => setSelectedCareer({ item: { name: '국가대표 경력', rows: [...(player.National || [])].sort((a, b) => Number(a.year) - Number(b.year) || nationalOrder.indexOf(a.type) - nationalOrder.indexOf(b.type)) }, kind: 'national-all' })}>자세히 보기<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></button></div><div className="profile-honors is-national">{national.map(item => { return <div key={item.name} className="profile-honor">
                <span className="profile-honor-icon">{nationalImages[item.name] ? <img src={nationalImages[item.name]} alt="" loading="lazy" /> : <b>{item.name.slice(0, 1)}</b>}</span>
                <span className="profile-honor-text"><strong>{item.name}</strong><small>{[...new Set(item.rows.map(row => row.year).filter(Boolean))].join(' · ')}</small></span>
                <span className="profile-honor-count">{item.rows.length}<small>회</small></span>
            </div>; })}</div></section>}
            {tab === 0 && <section><div className="profile-heading"><h2>선수 정보</h2></div><dl className="profile-facts">{(() => {
                // 짧은 항목은 격자로, 긴 항목(학력·입단 등)은 아래에 한 줄씩. 격자의 마지막 줄이 비지 않게 마지막 칸을 늘린다.
                const wideLabels = ['학력', '입단', '별명', '개명', '영구결번', '가족'];
                const narrow = info.filter(([label]) => !wideLabels.includes(label));
                const wide = info.filter(([label]) => wideLabels.includes(label));
                const fill = [narrow.length % 2 ? 'fill-m' : '', narrow.length % 3 === 1 ? 'fill-d3' : narrow.length % 3 === 2 ? 'fill-d2' : ''].filter(Boolean).join(' ');
                return [...narrow.map(([label, value], index) => <div key={label} className={index === narrow.length - 1 && fill ? fill : undefined}><dt>{label}</dt><dd>{value}</dd></div>), ...wide.map(([label, value]) => <div key={label} className="is-wide"><dt>{label}</dt><dd>{value}</dd></div>)];
            })()}</dl></section>}
            {(chartVisited || tab === 3) && <ProfileCandleChart key={`candle-chart-${pid}`} pid={pid} player={player} active={tab === 3} />}
            {tab === 4 && <PlayerCompare key={`compare-${pid}`} pid={pid} player={player} getTeamLogo={movementLogo} getTeamColor={compareTeamColor} />}
        </div>
        {playerGroup && <PlayerGroupModal key={`${playerGroup.type}-${playerGroup.value}`} group={playerGroup} currentPid={pid} getLogo={movementLogo} onClose={() => setPlayerGroup(null)} />}
        {selectedCareer && <CareerModal item={selectedCareer.item} kind={selectedCareer.kind} onClose={() => setSelectedCareer(null)} />}
    </main>;
}
