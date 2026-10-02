import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import useTableDrag from './useTableDrag';
import GameLogFilter from './GameLogFilter';
import { recordColumnWidth } from './recordTableLayout';
import { recordTeamColor } from './recordTeamColor';
import { nextYearRecordSort, sortedYearRecordRows } from './yearRecordSorting';
import { Spinner } from 'flowbite-react';
import { getCachedYearRecords, loadYearRecords } from './yearRecordsCache';

export const yearRecordColumns = {
    batter: {
        basic: [['경기','games'],['선발','starts'],['타율','avg'],['타석','pa'],['타수','ab'],['안타','h'],['2루타','doubles'],['3루타','triples'],['홈런','hr'],['타점','rbi'],['득점','r'],['볼넷','bb'],['사구','hbp'],['희플','sf'],['희생번트','sh'],['병살','gdp'],['도루','sb'],['도루자','cs'],['출루율','obp'],['장타율','slg'],['OPS','ops'],['실질OPS','effectiveOps'],['OPS+','opsPlus']],
        advanced: [['경기','games'],['타석','pa'],['wOBA','woba'],['순출루율','isoObp'],['순장타율','isoSlg'],['BABIP','babip'],['땅볼/뜬공','groundFly'],['BB%','bbPct'],['K%','kPct'],['BB/K','bbK']],
        special: [['경기','games'],['Spd','spd'],['도루시도','sbAttempts'],['도루','sb'],['도루자','cs'],['도루성공률','sbPct'],['주루사','runOut']],
        fielding: [['포지션','position'],['경기','games'],['선발','starts'],['이닝','innings'],['수비율','fieldingPct'],['도루저지율','caughtStealingPct']],
    },
    pitcher: {
        basic: [['경기','games'],['선발','starts'],['ERA','era'],['승리','wins'],['패전','losses'],['세이브','saves'],['홀드','holds'],['이닝','innings'],['실점','r'],['자책','er'],['삼진','so'],['피안타','h'],['피홈런','hr'],['볼넷','bb'],['사구','hbp'],['WHIP','whip'],['FIP','fip'],['ERA+','eraPlus']],
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

export default function YearRecords({ pid, position, teams, initialView = 'basic' }) {
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
    const columnCount = columns.length + leadingColumns;
    const widths = [52, 44, ...(showAge ? [36] : []), ...(showPosition ? [48] : []), ...columns.map(([label, key]) => recordColumnWidth(label, key))];
    const mobileWidths = [52, 44, ...(showAge ? [36] : []), ...(showPosition ? [48] : []), ...columns.map(([label, key]) => recordColumnWidth(label, key, true) + (sort?.key === key ? 8 : 0))];
    const displayedRows = sortedYearRecordRows(data.rows, sort, isFielding);
    const sortableHeading = (label, key, className) => <th key={key} scope="col" className={className} title={metricHints[key]} aria-sort={sort?.key === key ? (sort.direction === 'desc' ? 'descending' : 'ascending') : 'none'}><button type="button" className="profile-year-sort-heading" onClick={() => setSort(previous => nextYearRecordSort(previous, key))} aria-label={`${label} ${sort?.key === key && sort.direction === 'desc' ? '오름차순' : '내림차순'} 정렬`}>{label}{sort?.key === key && <span aria-hidden="true">{sort.direction === 'desc' ? '▾' : '▴'}</span>}</button></th>;
    const teamColor = name => recordTeamColor(name, teams);
    const statCells = stats => columns.map(([, key]) => {
        const rawValue = stats?.[key];
        const value = isFielding && key === 'position' ? fieldingPositionLabels[rawValue] || rawValue : rawValue;
        return <td key={key} className={key === 'position' ? 'profile-year-position-cell' : undefined}>{value === null || value === undefined ? '' : ['bbPct', 'kPct', 'sbPct', 'caughtStealingPct'].includes(key) ? `${value}%` : value}</td>;
    });
    const identityCells = (row, expandable = false) => <>
        <td className="profile-year-cell">{expandable && row.teams.length ? <button className="profile-year-expand" type="button" aria-expanded={!!expanded[row.year]} onClick={() => setExpanded(previous => ({ ...previous, [row.year]: !previous[row.year] }))}>{row.year}<svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" style={{ transform: expanded[row.year] ? 'rotate(180deg)' : undefined }}><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2" /></svg></button> : row.year}</td>
        <td className="profile-year-team-cell" style={{ color: expandable && row.teams.length ? '#000' : teamColor(row.team) }}>{row.team}</td>
        {showAge && <td className="profile-year-age-cell">{row.age ?? ''}</td>}{showPosition && <td className="profile-year-position-cell">{row.position || ''}</td>}
    </>;
    return <section>
        <div className="profile-heading profile-year-heading"><h2>연도별 기록</h2><GameLogFilter seasonOnly season={season} disabled={loading} availableSeasons={['regular','preseason','postseason','futures'].filter(key => (key === 'regular' ? allData : allData?.seasons?.[key])?.[pitcher ? 'pitcher' : 'batter']?.rows?.length)} onSeasonChange={value => { setSeason(value); setExpanded({}); }} /><div className={`profile-game-switch ${pitcher ? 'is-detailed' : ''}`} role="group" aria-label="타자 투수 기록 선택">{['타자', '투수'].map((label, index) => <button type="button" key={label} className={pitcher === Boolean(index) ? 'active' : ''} aria-pressed={pitcher === Boolean(index)} onClick={() => setPitcher(Boolean(index))}>{label}</button>)}</div></div>
        <div className="profile-year-views" ref={viewsRef} role="group" aria-label="연도별 기록 종류">{[['basic','기본'],['advanced','심화'],['special',pitcher ? '이닝' : '주루'],...(!pitcher && season === 'regular' ? [['fielding','수비']] : [])].map(([key, label]) => <button key={key} type="button" aria-pressed={currentView === key} onClick={() => setView(key)}>{label}</button>)}{sort && <button type="button" className="profile-year-sort-reset" aria-label="표 정렬 초기화" title="정렬 초기화" onClick={() => setSort(null)}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2 8M3 4v6h6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></button>}{indicator && <span className="profile-year-view-indicator" aria-hidden="true" style={indicator} />}</div>
        <div className="profile-year-table-wrap">
            <div className="profile-game-scroll" ref={scrollRef}><table className="profile-season-games profile-year-records" aria-busy={loading} style={{ '--record-table-width': `${widths.reduce((sum, width) => sum + width, 0)}px`, '--record-table-mobile-width': `${mobileWidths.reduce((sum, width) => sum + width, 0)}px` }}>
                <colgroup>{widths.map((width, index) => <col key={index} style={{ '--record-column-width': `${width}px`, '--record-column-mobile-width': `${mobileWidths[index]}px` }} />)}</colgroup>
                <caption className="sr-only">선택한 시즌의 연도별 기록과 통산 기록</caption>
                <thead><tr><th className="profile-year-cell" scope="col">연도</th><th className="profile-year-team-cell" scope="col">팀</th>{showAge && sortableHeading('나이', 'age', 'profile-year-age-cell')}{showPosition && sortableHeading('포지션', 'position', 'profile-year-position-cell')}{columns.map(([label,key]) => sortableHeading(label, key, key === 'position' ? 'profile-year-position-cell' : undefined))}</tr></thead>
                <tbody>{loading ? <tr aria-hidden="true"><td className="profile-year-loading-space" colSpan={columnCount} /></tr> : error || !data.rows.length ? <tr aria-hidden="true"><td className="profile-year-empty-space" colSpan={columnCount} /></tr> : displayedRows.map((row, index) => <Fragment key={isFielding ? `${row.year}-${row.team}-${index}` : row.year}>
                    {!sort && index > 0 && Math.abs(Number(row.year) - Number(displayedRows[index - 1].year)) > 1 && <tr aria-hidden="true" className="profile-year-gap"><td colSpan={columnCount} /></tr>}
                    {isFielding ? row.positions.map((stats, index) => <tr key={index}>{index === 0 && <><td className="profile-year-cell" rowSpan={row.positions.length}>{row.year}</td><td className="profile-year-team-cell" rowSpan={row.positions.length} style={{ color: teamColor(row.team) }}>{row.team}</td></>}{statCells(stats)}</tr>) : <><tr>{identityCells(row, true)}{statCells(row.stats)}</tr>{expanded[row.year] && row.teams.map(child => <tr className="profile-year-team-row" key={child.team}>{identityCells(child)}{statCells(child.stats)}</tr>)}</>}
                </Fragment>)}</tbody>
                <tfoot>{isFielding ? (!loading && !error ? [...(data.careerPositions || [])].sort(compareFieldingPositions) : []).map((stats, index, positions) => <tr key={stats.position}>{index === 0 && <th className="profile-year-career-cell" colSpan={2} rowSpan={positions.length} scope="rowgroup">통산</th>}{statCells(stats)}</tr>) : <tr><th className="profile-year-career-cell" colSpan={2} scope="row">통산</th>{showAge && <td />}{showPosition && <td className="profile-year-position-cell">{!loading && !error ? data.career?.position || '' : ''}</td>}{statCells(!loading && !error ? data.career : null)}</tr>}</tfoot>
            </table></div>
            {loading && <div className="profile-year-table-loading"><Spinner size="lg" className="fill-blue-600" aria-label="연도별 기록 불러오는 중" /></div>}
            {!loading && (error || !data.rows.length) && <p className="profile-games-empty profile-year-empty-message">{error || '기록이 없습니다.'}</p>}
        </div>
    </section>;
}
