import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import useTableDrag from './useTableDrag';
import useStickyTableHead from './useStickyTableHead';
import GameLogFilter from './GameLogFilter';
import { recordColumnWidth } from './recordTableLayout';
import { recordTeamColor } from './recordTeamColor';
import { nextYearRecordSort, sortedYearRecordRows } from './yearRecordSorting';
import { Spinner } from 'flowbite-react';
import { getCachedYearRecords, loadYearRecords } from './yearRecordsCache';

export const yearRecordColumns = {
    batter: {
        basic: [['경기','games'],['선발','starts'],['타율','avg'],['타석','pa'],['타수','ab'],['안타','h'],['2루타','doubles'],['3루타','triples'],['홈런','hr'],['타점','rbi'],['득점','r'],['볼넷','bb'],['사구','hbp'],['삼진','so'],['병살','gdp'],['희플','sf'],['희생번트','sh'],['도루','sb'],['도루자','cs'],['출루율','obp'],['장타율','slg'],['OPS','ops'],['실질OPS','effectiveOps'],['OPS+','opsPlus']],
        advanced: [['경기','games'],['타석','pa'],['wOBA','woba'],['순출루율','isoObp'],['순장타율','isoSlg'],['BABIP','babip'],['땅볼/뜬공','groundFly'],['BB%','bbPct'],['K%','kPct'],['BB/K','bbK']],
        special: [['경기','games'],['Spd','spd'],['도루시도','sbAttempts'],['도루','sb'],['도루자','cs'],['도루성공률','sbPct'],['주루사','runOut']],
        fielding: [['포지션','position'],['경기','games'],['선발','starts'],['이닝','innings'],['실책','errors'],['자살','putouts'],['보살','assists'],['병살','doublePlays'],['수비율','fieldingPct'],['견제사','pickoffs'],['포일','passedBalls'],['도루허용','stolenBases'],['도루저지','caughtStealing'],['도루저지율','caughtStealingPct']],
    },
    pitcher: {
        basic: [['경기','games'],['선발','starts'],['ERA','era'],['승리','wins'],['패전','losses'],['세이브','saves'],['홀드','holds'],['이닝','innings'],['실점','r'],['자책','er'],['삼진','so'],['피안타','h'],['피홈런','hr'],['볼넷','bb'],['사구','hbp'],['승률','winPct'],['WHIP','whip'],['FIP','fip'],['ERA+','eraPlus']],
        advanced: [['경기','games'],['이닝','innings'],['K/9','k9'],['B/9','bb9'],['K%','kPct'],['BB%','bbPct'],['K/BB','kBb'],['피안타율','opponentAvg'],['피출루율','opponentObp'],['피장타율','opponentSlg'],['피OPS','opponentOps'],['H/9','h9'],['HR/9','hr9'],['땅볼/뜬공','groundFly'],['BABIP','babip']],
        special: [['경기','games'],['선발','starts'],['구원','reliefs'],['마무리','finishes'],['선발이닝','starterInnings'],['구원이닝','reliefInnings'],['투구수','pitches'],['투구수/이닝','pitchesPerInning'],['투구수/경기','pitchesPerGame'],['QS','qs'],['QS+','qsPlus'],['DS','ds'],['완투','complete'],['완봉','shutouts']],
    },
};
const fieldingPositionLabels = { 투수: 'P', 포수: 'C', '1루수': '1B', '2루수': '2B', '3루수': '3B', 유격수: 'SS', 좌익수: 'LF', 중견수: 'CF', 우익수: 'RF', 외야수: 'OF', 내야수: 'IF', 지명타자: 'DH', 지명: 'DH' };
const fieldingPositionOrder = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF', 'DH'];
const compareFieldingPositions = (a, b) => {
    const rank = stats => {
        const index = fieldingPositionOrder.indexOf(fieldingPositionLabels[stats.position] || stats.position);
        return index < 0 ? fieldingPositionOrder.length : index;
    };
    return rank(a) - rank(b);
};
const metricHints = {
    age: '해당 연도 7월 1일 기준 만 나이', bb9: '9이닝당 볼넷',
    eraPlus: '구장 보정 없이 시즌별 리그 평균 ERA와 비교 (리그 평균 100)',
    groundFly: '땅볼아웃 ÷ 뜬공아웃 (병살타 포함, 직선타 제외)',
    finishes: '선발 등판을 제외하고 팀의 마지막 투수로 등판한 경기 수',
    spd: 'F6 수비 요소를 제외한 F1~F5의 평균 (각 요소는 0~10으로 제한)',
    woba: '사용자 지정 가중치: 볼넷·사구 0.7, 단타·실책 출루 0.9, 2루타 1.25, 3루타 1.6, 홈런 2.0, 도루 0.25, 도루자 -0.5; 분모는 타석-고의4구-희생번트',
};

// 타이틀홀더 종목 → 연도별 기록 열. 해당 연도에 리그 1위(타이틀)를 한 칸을 강조한다.
const titleColumns = {
    batter: { 타율: 'avg', 안타: 'h', 홈런: 'hr', 타점: 'rbi', 득점: 'r', 도루: 'sb', 출루율: 'obp', 장타율: 'slg' },
    pitcher: { 다승: 'wins', 평균자책점: 'era', 탈삼진: 'so', 세이브: 'saves', 홀드: 'holds', 승률: 'winPct' },
};
const titleNames = { 타율: '타격왕', 안타: '최다안타', 홈런: '홈런왕', 타점: '타점왕', 득점: '득점왕', 도루: '도루왕', 출루율: '출루율 1위', 장타율: '장타율 1위', 다승: '다승왕', 평균자책점: '평균자책점 1위', 탈삼진: '탈삼진왕', 세이브: '세이브왕', 홀드: '홀드왕', 승률: '승률왕' };
// 커리어 하이는 표본 크기에 흔들리지 않는 누적 기록만 본다.
const careerHighKeys = {
    batter: ['h', 'doubles', 'triples', 'hr', 'rbi', 'r', 'bb', 'sb'],
    pitcher: ['wins', 'saves', 'holds', 'innings', 'so', 'qs', 'complete', 'shutouts'],
};
// 비율 기록 커리어 하이: 규정 타석·이닝을 채운 시즌끼리만 비교한다(lower: 낮을수록 좋음).
const careerHighRateKeys = {
    batter: { avg: 'higher', obp: 'higher', slg: 'higher', ops: 'higher', effectiveOps: 'higher', opsPlus: 'higher' },
    pitcher: { era: 'lower', whip: 'lower', fip: 'lower', eraPlus: 'higher', winPct: 'higher' },
};
const inningsValue = value => { const [whole, part = '0'] = String(value).split('.'); return Number(whole) + Number(part) / 3; };

// 수상 열: 연도별 수상 아이콘(같은 상 여러 번이면 ×n). 기본 탭·정규시즌에서만, 통산 행에는 넣지 않는다.
const yearAwardOrder = ['MVP', '골든글러브', '수비상', '신인왕', '올스타', '월간 MVP', '우승'];
const awardShortNames = { MVP: 'MVP', 골든글러브: 'GG', 수비상: '수비상', 신인왕: '신인왕', 올스타: '올스타', '월간 MVP': '월간', 우승: '우승' };
// 포지션이 있는 상(골든글러브 등)은 '외야수 골든글러브'처럼 포지션을 앞에 쓴다.
const awardLabel = award => [award.pos || '', award.type, award.month ? `${award.month}월` : '', award.note || ''].filter(Boolean).join(' ');

export default function YearRecords({ pid, position, teams, titles = [], awards = [], awardImages = {}, getTeamLogo, initialView = 'basic' }) {
    const [pitcher, setPitcher] = useState((position || '').includes('투수'));
    const [view, setView] = useState(initialView);
    const [allData, setAllData] = useState(() => getCachedYearRecords(pid));
    const [loading, setLoading] = useState(() => !getCachedYearRecords(pid));
    const [error, setError] = useState('');
    const [expanded, setExpanded] = useState({});
    const scrollRef = useTableDrag();
    const [season, setSeason] = useState('regular');
    const viewsRef = useRef(null);
    const [indicator, setIndicator] = useState(null);
    const [sort, setSort] = useState(null);
    const [careerExpanded, setCareerExpanded] = useState(false);
    // 수상 아이콘을 누르면(모바일 포함) 설명 말풍선을 띄운다. 표 스크롤 영역에 잘리지 않게 화면 고정 위치로 그린다.
    const [awardTip, setAwardTip] = useState(null);
    useEffect(() => {
        if (!awardTip) return undefined;
        const close = event => { if (!event.target.closest?.('.profile-year-award, .profile-year-award-tip')) setAwardTip(null); };
        const dismiss = () => setAwardTip(null);
        document.addEventListener('pointerdown', close);
        window.addEventListener('scroll', dismiss, true);
        window.addEventListener('resize', dismiss);
        return () => { document.removeEventListener('pointerdown', close); window.removeEventListener('scroll', dismiss, true); window.removeEventListener('resize', dismiss); };
    }, [awardTip]);
    const stickyRef = useStickyTableHead();
    useEffect(() => {
        let active = true;
        const cached = getCachedYearRecords(pid);
        setAllData(cached); setLoading(!cached); setError(''); setExpanded({});
        loadYearRecords(pid)
            .then(result => { if (active) setAllData(result); })
            .catch(() => { if (active) setError('기록을 불러오지 못했습니다.'); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [pid]);
    // 처음 보여줄 시즌 종류: 정규시즌 기록이 없으면 퓨처스리그 → 포스트시즌 → 시범경기 순으로 기록이 있는 것을 고른다.
    // 사용자가 직접 고른 뒤에는 바꾸지 않는다.
    const seasonChosen = useRef(false);
    useEffect(() => { seasonChosen.current = false; }, [pid]);
    useEffect(() => {
        if (!allData || seasonChosen.current) return;
        const kind = pitcher ? 'pitcher' : 'batter';
        const hasRows = key => Boolean((key === 'regular' ? allData : allData.seasons?.[key])?.[kind]?.rows?.length);
        setSeason(['regular', 'futures', 'postseason', 'preseason'].find(hasRows) || 'regular');
    }, [allData, pitcher]);
    const currentView = (pitcher || season !== 'regular') && view === 'fielding' ? 'basic' : view;
    useLayoutEffect(() => {
        const tabs = viewsRef.current;
        const updateIndicator = () => {
            const selected = tabs?.querySelector('button[aria-pressed="true"]');
            if (!selected) return;
            setIndicator({ left: selected.offsetLeft, top: selected.offsetTop + selected.offsetHeight - 2, width: selected.offsetWidth });
        };
        updateIndicator();
        const observer = new ResizeObserver(updateIndicator);
        observer.observe(tabs);
        for (const button of tabs.querySelectorAll('button')) observer.observe(button);
        return () => observer.disconnect();
    }, [currentView, pitcher, season]);
    useEffect(() => { setSort(null); }, [pid, pitcher, currentView, season]);
    const isFielding = currentView === 'fielding';
    const seasonData = season === 'regular' ? allData : allData?.seasons?.[season];
    const data = seasonData?.[isFielding ? 'fielding' : pitcher ? 'pitcher' : 'batter'] || { rows: [], career: null };
    const columns = yearRecordColumns[pitcher ? 'pitcher' : 'batter'][currentView];
    const showAge = currentView === 'basic';
    const showPosition = !pitcher && currentView === 'basic';
    const leadingColumns = 2 + Number(showAge) + Number(showPosition);
    const awardsByYear = new Map();
    for (const award of awards || []) {
        if (!award?.year) continue;
        const list = awardsByYear.get(String(award.year)) || [];
        const group = list.find(item => item.type === award.type);
        if (group) group.rows.push(award); else list.push({ type: award.type, rows: [award] });
        awardsByYear.set(String(award.year), list);
    }
    for (const list of awardsByYear.values()) list.sort((a, b) => (yearAwardOrder.indexOf(a.type) + 1 || 99) - (yearAwardOrder.indexOf(b.type) + 1 || 99));
    const showAwards = currentView === 'basic' && season === 'regular' && awardsByYear.size > 0;
    // 같은 상은 같은 세로줄에 오도록, 선수가 받은 상 종류마다 고정 칸을 둔다.
    const awardSlots = [...new Set([...awardsByYear.values()].flatMap(list => list.map(group => group.type)))].sort((a, b) => (yearAwardOrder.indexOf(a) + 1 || 99) - (yearAwardOrder.indexOf(b) + 1 || 99));
    const awardColumnWidth = Math.max(56, 14 + 36 * awardSlots.length);
    const columnCount = columns.length + leadingColumns + Number(showAwards);
    const widths = [48, 70, ...(showAge ? [36] : []), ...(showPosition ? [48] : []), ...columns.map(([label, key]) => recordColumnWidth(label, key)), ...(showAwards ? [awardColumnWidth] : [])];
    const mobileWidths = [52, 66, ...(showAge ? [36] : []), ...(showPosition ? [48] : []), ...columns.map(([label, key]) => recordColumnWidth(label, key, true) + (sort?.key === key ? 8 : 0)), ...(showAwards ? [awardColumnWidth - 6] : [])];
    const displayedRows = sortedYearRecordRows(data.rows, sort, isFielding);
    // 여러 팀에서 뛴 선수는 통산 행을 펼쳐 팀별 통산을 본다(수비 탭 제외).
    const careerTeams = !loading && !error && !isFielding ? data.careerTeams || [] : [];
    const sortableHeading = (label, key, className) => <th key={key} scope="col" className={className} title={metricHints[key]} aria-sort={sort?.key === key ? (sort.direction === 'desc' ? 'descending' : 'ascending') : 'none'}><button type="button" className="profile-year-sort-heading" onClick={() => setSort(previous => nextYearRecordSort(previous, key))} aria-label={`${label} ${sort?.key === key && sort.direction === 'desc' ? '오름차순' : '내림차순'} 정렬`}>{label}{sort?.key === key && <span aria-hidden="true">{sort.direction === 'desc' ? '▾' : '▴'}</span>}</button></th>;
    const teamColor = name => recordTeamColor(name, teams);
    const kind = pitcher ? 'pitcher' : 'batter';
    // 연도·열별 강조: 리그 1위(타이틀)는 정규시즌에서만, 커리어 하이는 연도 행이 2개 이상일 때만.
    const titleCells = new Map();
    if (season === 'regular' && currentView === 'basic') {
        // 미리 계산된 리그 1위(기본 탭 전 기록, 부정적 기록 제외) + 공식 타이틀 이름
        for (const [year, keys] of Object.entries(data.leaders || {})) for (const key of keys) titleCells.set(`${year}:${key}`, '리그 1위');
        for (const title of titles || []) {
            const key = titleColumns[kind][title.type];
            if (key) titleCells.set(`${title.year}:${key}`, titleNames[title.type] || `${title.type} 1위`);
        }
    }
    const careerHighCells = new Set();
    if (!isFielding && data.rows.length > 1) for (const key of careerHighKeys[kind]) {
        if (!columns.some(([, column]) => column === key)) continue;
        const values = data.rows.map(row => ({ year: row.year, value: key === 'innings' ? inningsValue(row.stats?.[key] ?? 0) : Number(row.stats?.[key]) }));
        const best = Math.max(...values.map(item => item.value).filter(Number.isFinite));
        if (best > 0) values.filter(item => item.value === best).forEach(item => careerHighCells.add(`${item.year}:${key}`));
    }
    if (!isFielding) for (const [key, direction] of Object.entries(careerHighRateKeys[kind])) {
        if (!columns.some(([, column]) => column === key)) continue;
        // 승률은 10승 이상 시즌만, 나머지는 규정 타석·이닝을 채운 시즌만
        const eligible = data.rows.filter(row => key === 'winPct' ? Number(row.stats?.wins) >= 10 : row.qualified === true)
            .filter(row => row.stats?.[key] !== null && row.stats?.[key] !== undefined && row.stats?.[key] !== '')
            .map(row => ({ year: row.year, value: Number(row.stats[key]) })).filter(item => Number.isFinite(item.value));
        if (eligible.length < 2) continue;
        const best = direction === 'lower' ? Math.min(...eligible.map(item => item.value)) : Math.max(...eligible.map(item => item.value));
        eligible.filter(item => item.value === best).forEach(item => careerHighCells.add(`${item.year}:${key}`));
    }
    const statCells = (stats, year = null) => columns.map(([, key]) => {
        const rawValue = stats?.[key];
        const value = isFielding && key === 'position' ? fieldingPositionLabels[rawValue] || rawValue : rawValue;
        const title = year !== null ? titleCells.get(`${year}:${key}`) : null;
        const isHigh = year !== null && careerHighCells.has(`${year}:${key}`);
        const high = !title && isHigh;
        const className = [key === 'position' ? 'profile-year-position-cell' : '', title ? 'is-league-best' : '', title && isHigh ? 'is-best-high' : '', high ? 'is-career-high' : ''].filter(Boolean).join(' ') || undefined;
        const text = value === null || value === undefined ? '' : ['bbPct', 'kPct', 'sbPct', 'caughtStealingPct'].includes(key) ? `${value}%` : value;
        return <td key={key} className={className} title={title ? `${year} ${title}${isHigh ? ' · 커리어 하이' : ''}` : high ? '커리어 하이' : undefined}>{text}</td>;
    });
    const hasLeagueBest = !loading && data.rows.some(row => columns.some(([, key]) => titleCells.has(`${row.year}:${key}`)));
    const hasCareerHigh = !loading && careerHighCells.size > 0;
    const awardCell = year => {
        if (!showAwards) return null;
        const list = year == null ? null : awardsByYear.get(String(year));
        return <td className="profile-year-award-cell">{list && <span className="profile-year-awards">{awardSlots.map(type => list.find(group => group.type === type) || { type, empty: true }).map(group => {
            if (group.empty) return <span key={group.type} className="profile-year-award-slot" aria-hidden="true" />;
            const id = `${year}:${group.type}`;
            const open = awardTip?.id === id;
            return <button type="button" key={group.type} className={`profile-year-award${open ? ' is-open' : ''}`} aria-label={`${year} ${group.rows.map(awardLabel).join(', ')}`} aria-expanded={open} onClick={event => {
                if (open) { setAwardTip(null); return; }
                const box = event.currentTarget.getBoundingClientRect();
                setAwardTip({ id, title: `${year} ${group.type}${group.rows.length > 1 ? ` ${group.rows.length}회` : ''}`, lines: group.rows.map(awardLabel).filter(line => line !== group.type), x: box.left + box.width / 2, y: box.bottom + 6 });
            }}>
                <span className="profile-year-award-icon">{awardImages[group.type] ? <img src={awardImages[group.type]} alt="" /> : <b>{group.type.slice(0, 2)}</b>}{group.rows.length > 1 && <small>×{group.rows.length}</small>}</span>
                <span className="profile-year-award-name">{awardShortNames[group.type] || group.type}</span>
            </button>;
        })}</span>}</td>;
    };
    const teamLabel = (name, color, year) => {
        const logo = getTeamLogo?.(name, year);
        return logo ? <span className="profile-year-team-logo" title={name}><img src={logo} alt="" /><span>{name}</span></span> : <span style={{ color }}>{name}</span>;
    };
    const identityCells = (row, expandable = false) => <>
        <td className="profile-year-cell">{expandable && row.teams.length ? <button className="profile-year-expand" type="button" aria-expanded={!!expanded[row.year]} onClick={() => setExpanded(previous => ({ ...previous, [row.year]: !previous[row.year] }))}>{row.year}<svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" style={{ transform: expanded[row.year] ? 'rotate(180deg)' : undefined }}><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2" /></svg></button> : row.year}</td>
        <td className="profile-year-team-cell" style={{ '--row-team-color': teamColor(row.team) }}>{expandable && row.teams.length ? <span className="profile-year-multi-team">{row.team}</span> : teamLabel(row.team, teamColor(row.team), row.year)}</td>
        {showAge && <td className="profile-year-age-cell">{row.age ?? ''}</td>}{showPosition && <td className="profile-year-position-cell">{row.position || ''}</td>}
    </>;
    const tableStyle = { '--record-table-width': `${widths.reduce((sum, width) => sum + width, 0)}px`, '--record-table-mobile-width': `${mobileWidths.reduce((sum, width) => sum + width, 0)}px` };
    const colgroup = <colgroup>{widths.map((width, index) => <col key={index} style={{ '--record-column-width': `${width}px`, '--record-column-mobile-width': `${mobileWidths[index]}px` }} />)}</colgroup>;
    const headRow = <tr><th className="profile-year-cell" scope="col">연도</th><th className="profile-year-team-cell" scope="col">팀</th>{showAge && sortableHeading('나이', 'age', 'profile-year-age-cell')}{showPosition && sortableHeading('포지션', 'position', 'profile-year-position-cell')}{columns.map(([label,key]) => sortableHeading(label, key, key === 'position' ? 'profile-year-position-cell' : undefined))}{showAwards && <th className="profile-year-award-cell" scope="col">수상</th>}</tr>;
    return <section>
        <div className="profile-heading profile-year-heading"><h2>연도별 기록</h2><GameLogFilter seasonOnly season={season} disabled={loading} availableSeasons={['regular','preseason','postseason','futures'].filter(key => (key === 'regular' ? allData : allData?.seasons?.[key])?.[pitcher ? 'pitcher' : 'batter']?.rows?.length)} onSeasonChange={value => { seasonChosen.current = true; setSeason(value); setExpanded({}); }} /><div className={`profile-game-switch ${pitcher ? 'is-detailed' : ''}`} role="group" aria-label="타자 투수 기록 선택">{['타자', '투수'].map((label, index) => <button type="button" key={label} className={pitcher === Boolean(index) ? 'active' : ''} aria-pressed={pitcher === Boolean(index)} onClick={() => setPitcher(Boolean(index))}>{label}</button>)}</div></div>
        <div className="profile-year-views" ref={viewsRef} role="group" aria-label="연도별 기록 종류">{[['basic','기본'],['advanced','심화'],['special',pitcher ? '이닝' : '주루'],...(!pitcher && season === 'regular' ? [['fielding','수비']] : [])].map(([key, label]) => <button key={key} type="button" aria-pressed={currentView === key} onClick={() => setView(key)}>{label}</button>)}{sort && <button type="button" className="profile-year-sort-reset" aria-label="표 정렬 초기화" title="정렬 초기화" onClick={() => setSort(null)}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2 8M3 4v6h6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></button>}{indicator && <span className="profile-year-view-indicator" aria-hidden="true" style={indicator} />}</div>
        <div className="profile-year-table-wrap">
            {/* 아래로 내려도 컬럼명이 보이도록 화면 위에 붙는 머리글 사본. 가로 스크롤은 표와 맞춘다. */}
            <div className="profile-table-sticky-head" ref={stickyRef} aria-hidden="true"><div><table className="profile-season-games profile-year-records" style={tableStyle}>{colgroup}<thead>{headRow}</thead></table></div></div>
            <div className="profile-game-scroll" ref={scrollRef}><table className="profile-season-games profile-year-records" aria-busy={loading} style={tableStyle}>
                {colgroup}
                <caption className="sr-only">선택한 시즌의 연도별 기록과 통산 기록</caption>
                <thead>{headRow}</thead>
                <tbody>{loading ? <tr aria-hidden="true"><td className="profile-year-loading-space" colSpan={columnCount} /></tr> : error || !data.rows.length ? <tr aria-hidden="true"><td className="profile-year-empty-space" colSpan={columnCount} /></tr> : displayedRows.map((row, index) => <Fragment key={isFielding ? `${row.year}-${row.team}-${index}` : row.year}>
                    {!sort && index > 0 && Math.abs(Number(row.year) - Number(displayedRows[index - 1].year)) > 1 && <tr aria-hidden="true" className="profile-year-gap"><td colSpan={columnCount} /></tr>}
                    {isFielding ? row.positions.map((stats, index) => <tr key={index}>{index === 0 && <><td className="profile-year-cell" rowSpan={row.positions.length}>{row.year}</td><td className="profile-year-team-cell" rowSpan={row.positions.length} style={{ '--row-team-color': teamColor(row.team) }}>{teamLabel(row.team, teamColor(row.team), row.year)}</td></>}{statCells(stats)}</tr>) : <><tr>{identityCells(row, true)}{statCells(row.stats, row.year)}{awardCell(row.year)}</tr>{expanded[row.year] && row.teams.map(child => <tr className="profile-year-team-row" key={child.team}>{identityCells(child)}{statCells(child.stats)}{awardCell(null)}</tr>)}</>}
                </Fragment>)}</tbody>
                <tfoot>{isFielding ? (!loading && !error ? [...(data.careerPositions || [])].sort(compareFieldingPositions) : []).map((stats, index, positions) => <tr key={stats.position}>{index === 0 && <th className="profile-year-career-cell" colSpan={2} rowSpan={positions.length} scope="rowgroup">통산</th>}{statCells(stats)}</tr>) : <>
                    <tr><th className="profile-year-career-cell" colSpan={2} scope="row">{careerTeams.length > 1 ? <button className="profile-year-expand profile-year-career-toggle" type="button" aria-expanded={careerExpanded} onClick={() => setCareerExpanded(open => !open)}>통산<svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" style={{ transform: careerExpanded ? 'rotate(180deg)' : undefined }}><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2" /></svg></button> : '통산'}</th>{showAge && <td />}{showPosition && <td className="profile-year-position-cell">{!loading && !error ? data.career?.position || '' : ''}</td>}{statCells(!loading && !error ? data.career : null)}{awardCell(null)}</tr>
                    {careerExpanded && careerTeams.map(item => <tr key={item.team} className="profile-year-career-team-row"><th className="profile-year-career-cell" colSpan={2} scope="row" title={`${item.team} ${item.firstYear}${item.lastYear !== item.firstYear ? `–${item.lastYear}` : ''}`}>{teamLabel(item.team, teamColor(item.team), item.lastYear)}</th>{showAge && <td />}{showPosition && <td className="profile-year-position-cell">{item.stats?.position || ''}</td>}{statCells(item.stats)}{awardCell(null)}</tr>)}
                </>}</tfoot>
            </table></div>
            {loading && <div className="profile-year-table-loading"><Spinner size="lg" className="fill-blue-600" aria-label="연도별 기록 불러오는 중" /></div>}
            {!loading && (error || !data.rows.length) && <p className="profile-games-empty profile-year-empty-message">{error || '기록이 없습니다.'}</p>}
            {awardTip && <div className="profile-year-award-tip" role="tooltip" style={{ left: Math.min(Math.max(awardTip.x, 90), window.innerWidth - 90), top: awardTip.y }}><strong>{awardTip.title}</strong>{awardTip.lines.map((line, index) => <span key={index}>{line}</span>)}</div>}
            {(hasLeagueBest || hasCareerHigh) && <p className="profile-year-legend">{hasLeagueBest && <span className="is-league-best"><i aria-hidden="true" />리그 1위</span>}{hasCareerHigh && <span className="is-career-high"><i aria-hidden="true" />커리어 하이</span>}</p>}
        </div>
    </section>;
}
