import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Spinner } from 'flowbite-react';
import '../PlayerProfile/player-profile.css';
import './records.css';
import useTableDrag from '../PlayerProfile/useTableDrag';
import useStickyTableHead from '../PlayerProfile/useStickyTableHead';
import { recordColumnWidth } from '../PlayerProfile/recordTableLayout';
import { fieldingPositionLabels, metricHints, yearRecordColumns } from '../PlayerProfile/YearRecords';
import { teamCodeByName, teamLogoByCode, teamSmallLogoByCode } from '../../lib/teamAssets';
import { GAME_LOG_FIRST_YEAR, MAX_OPPONENTS, customMinInnings, customMinText, defaultDirection, defaultFilter, defaultSort, fieldingMinOptions, franchises, handOptions, isDateSpan, loadRecordRoomMeta, loadRecordRoomPage, minOptions, modernOnly, positionOptions, recordRoomKey, resolveFilter, searchPlayers, seasonParts, seasonTypes, spanOptions, tableKind, versusColumns } from './recordRoomData';

const STORAGE_KEY = 'wesiper-record-room-v6';
const types = [['batter', '타자'], ['pitcher', '투수'], ['fielding', '수비']];
const viewTabs = kind => kind === 'batter' || kind === 'pitcher' ? [['basic', '기본'], ['advanced', '심화'], ['special', kind === 'pitcher' ? '이닝' : '주루']] : [];
const kindColumns = (kind, view) => kind === 'fielding' ? yearRecordColumns.batter.fielding : kind === 'versus' ? versusColumns : yearRecordColumns[kind][view] || yearRecordColumns[kind].basic;
const percentKeys = ['bbPct', 'kPct', 'sbPct', 'caughtStealingPct'];
const minUnit = kind => ({ batter: '타석', pitcher: '이닝', fielding: '경기', versus: '타석' })[kind];
const teamLogo = (team, year) => { const code = teamCodeByName(team, year); return code ? teamSmallLogoByCode(code) || teamLogoByCode(code) : null; };

// 선수 프로필에 다녀와도 보던 조건이 남도록 탭을 닫을 때까지 보관한다.
function readSaved(meta) {
    let saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY)); } catch { return null; }
    const filter = saved?.filter;
    if (!filter || !types.some(([type]) => type === saved.type) || !['basic', 'advanced', 'special'].includes(saved.view)) return null;
    if (!meta.years.includes(filter.season) || !meta.years.includes(filter.to) || !spanOptions.some(([span]) => span === filter.span)) return null;
    return { ...saved, filter: { ...defaultFilter(meta), ...filter, vs: Array.isArray(filter.vs) ? filter.vs : [], players: Array.isArray(filter.players) ? filter.players : [] } };
}

// 선수 여러 명 고르기(결과에 남길 선수, 상대 투수·상대 타자)
function OpponentPicker({ label, value, onChange }) {
    const [keyword, setKeyword] = useState('');
    const [list, setList] = useState([]);
    useEffect(() => {
        const text = keyword.trim();
        if (text.length < 2) { setList([]); return undefined; }
        const controller = new AbortController();
        const timer = setTimeout(() => searchPlayers(text, controller.signal).then(setList).catch(() => {}), 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [keyword]);
    return <div className="record-room-field record-room-opponent">
        <span>{label}</span>
        <div className="record-room-opponent-box">
            {value.map(player => <span key={player.id} className="record-room-opponent-chip">{player.name}<button type="button" aria-label={`${player.name} 빼기`} onClick={() => onChange(value.filter(item => item.id !== player.id))}>×</button></span>)}
            {value.length < MAX_OPPONENTS && <input type="search" value={keyword} placeholder={value.length ? '추가' : '선수 이름'} aria-label={`${label} 검색`} onChange={event => setKeyword(event.target.value)} />}
        </div>
        {list.length > 0 && <ul className="record-room-opponent-list">{list.filter(player => !value.some(item => item.id === player.PlayerId)).map(player => <li key={player.PlayerId}><button type="button" onClick={() => { onChange([...value, { id: player.PlayerId, name: player.Name }]); setKeyword(''); setList([]); }}>{player.Name}<small>{[player.Team || player.FormerTeam, player.Pos].filter(Boolean).join(' · ')}</small></button></li>)}</ul>}
    </div>;
}

export default function Records() {
    const [meta, setMeta] = useState(null);
    const [metaError, setMetaError] = useState('');
    const [type, setType] = useState('batter');
    const [view, setView] = useState('basic');
    const [draft, setDraft] = useState(null);
    const [applied, setApplied] = useState(null);
    const [sort, setSort] = useState(() => defaultSort('batter'));
    const [filterOpen, setFilterOpen] = useState(false);
    const [formError, setFormError] = useState('');
    const [result, setResult] = useState({ key: null, rows: [], total: 0, loading: true, loadingMore: false, error: '' });
    const scrollRef = useTableDrag();
    const stickyRef = useStickyTableHead();
    const viewsRef = useRef(null);
    const [indicator, setIndicator] = useState(null);

    useEffect(() => {
        let active = true;
        loadRecordRoomMeta().then(loaded => {
            if (!active) return;
            const saved = readSaved(loaded);
            const filter = saved?.filter || defaultFilter(loaded);
            if (saved) {
                const kind = tableKind(saved.type, filter);
                setType(saved.type); setView(saved.view);
                setSort(kindColumns(kind, saved.view).some(([, key]) => key === saved.sort?.key && key !== 'position') && ['asc', 'desc'].includes(saved.sort.direction) ? saved.sort : defaultSort(kind, saved.view));
            }
            setDraft(filter); setApplied(filter); setMeta(loaded);
        }).catch(error => { if (active) setMetaError(error.message); });
        return () => { active = false; };
    }, []);
    useEffect(() => {
        if (!meta || !applied) return;
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ type, view, sort, filter: applied })); } catch { /* 저장소를 못 쓰면 보관만 건너뛴다 */ }
    }, [meta, type, view, sort, applied]);

    const kind = tableKind(type, applied);
    const resolved = useMemo(() => meta && applied ? resolveFilter(applied, meta, type) : null, [meta, applied, type]);
    const params = resolved?.params;
    const queryKey = params ? recordRoomKey(type, params, sort) : null;
    useEffect(() => {
        if (!resolved) return undefined;
        if (!queryKey) { setResult({ key: null, rows: [], total: 0, loading: false, loadingMore: false, error: resolved.error }); return undefined; }
        let active = true;
        setResult(previous => ({ ...previous, key: queryKey, loading: true, loadingMore: false, error: '' }));
        loadRecordRoomPage(type, params, sort, 0)
            .then(page => { if (active) setResult({ key: queryKey, rows: page.rows, total: page.total, loading: false, loadingMore: false, error: '' }); })
            .catch(error => { if (active) setResult({ key: queryKey, rows: [], total: 0, loading: false, loadingMore: false, error: error.message }); });
        return () => { active = false; };
        // params·sort는 queryKey에 들어 있다.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [queryKey, resolved?.error]);
    const loadMore = () => {
        const key = queryKey, offset = result.rows.length;
        setResult(previous => ({ ...previous, loadingMore: true }));
        loadRecordRoomPage(type, params, sort, offset)
            .then(page => setResult(previous => previous.key === key && previous.rows.length === offset ? { ...previous, rows: [...previous.rows, ...page.rows], total: page.total, loadingMore: false } : previous))
            .catch(() => setResult(previous => previous.key === key ? { ...previous, loadingMore: false } : previous));
    };

    useEffect(() => {
        if (!filterOpen) return undefined;
        const onKey = event => { if (event.key === 'Escape') { setFilterOpen(false); setDraft(applied); setFormError(''); } };
        document.addEventListener('keydown', onKey);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; };
    }, [filterOpen, applied]);
    const tabs = viewTabs(kind);
    const columns = kindColumns(kind, view);
    useLayoutEffect(() => {
        const element = viewsRef.current;
        if (!element) { setIndicator(null); return undefined; }
        const update = () => {
            const selected = element.querySelector('button[aria-pressed="true"]');
            if (selected) setIndicator({ left: selected.offsetLeft, top: selected.offsetTop + selected.offsetHeight - 2, width: selected.offsetWidth });
        };
        update();
        const observer = new ResizeObserver(update);
        observer.observe(element);
        return () => observer.disconnect();
    }, [view, kind, meta]);

    // 타자·투수·수비 전환은 바로 반영한다. 기록 종류에 딸린 조건(상대 선수·포지션·타석/투구)은 지우고, 수비는 시즌 단위 기록만 있다.
    const changeType = next => {
        if (next === type) return;
        const adjust = filter => ({ ...filter, vs: [], pos: '', hand: '', award: false, min: filter.min === 'utility' ? 100 : filter.min, ...(next === 'fielding' ? { seasonType: 'regular', span: 'full', opp: '', stadium: '', season: Math.max(filter.season, GAME_LOG_FIRST_YEAR), to: Math.max(filter.to, GAME_LOG_FIRST_YEAR) } : {}) });
        setType(next); setView('basic'); setDraft(adjust); setApplied(adjust); setSort(defaultSort(next)); setFormError('');
    };
    const changeView = next => {
        setView(next);
        if (!kindColumns(kind, next).some(([, key]) => key === sort.key)) setSort(defaultSort(kind, next));
    };
    const changeSort = key => setSort(previous => previous.key === key ? { key, direction: previous.direction === 'desc' ? 'asc' : 'desc' } : { key, direction: defaultDirection(kind, key) });
    const update = patch => { setDraft(previous => ({ ...previous, ...patch })); setFormError(''); };
    const search = event => {
        event.preventDefault();
        const next = resolveFilter(draft, meta, type);
        if (next.error) { setFormError(next.error); return; }
        const nextKind = tableKind(type, draft);
        if (nextKind !== kind) { setView('basic'); setSort(defaultSort(nextKind)); }
        setFormError(''); setApplied({ ...draft }); setFilterOpen(false);
    };
    // 닫으면 적용하지 않은 변경은 버린다.
    const closeFilter = () => { setFilterOpen(false); setDraft(applied); setFormError(''); };

    if (metaError) return <main className="record-room font-family-NaSqNe"><div className="record-room-card"><p className="profile-games-empty">{metaError}</p></div></main>;
    if (!meta || !draft) return <main className="record-room font-family-NaSqNe"><div className="record-room-card record-room-booting"><Spinner size="lg" className="fill-blue-600" aria-label="기록실 불러오는 중" /></div></main>;

    const dirty = JSON.stringify(draft) !== JSON.stringify(applied);
    const gameLog = type !== 'fielding';
    const regularSeason = type === 'fielding' || draft.seasonType === 'regular';
    // 시즌 종류마다 기록이 있는 연도가 다르다(시범경기 2011년~, 퓨처스리그 2010년~ 등).
    const years = [...(regularSeason ? meta.years : meta.seasonYears[draft.seasonType])].reverse().filter(year => !modernOnly(type, draft) || year >= GAME_LOG_FIRST_YEAR);
    const changeSeasonType = seasonType => {
        const available = seasonType === 'regular' ? meta.years : meta.seasonYears[seasonType];
        const clamp = year => available.includes(year) ? year : available.reduce((best, value) => Math.abs(value - year) < Math.abs(best - year) ? value : best, available[available.length - 1]);
        update({ seasonType, season: clamp(draft.season), to: clamp(draft.to), ...(seasonType !== 'regular' && seasonParts.includes(draft.span) ? { span: 'full' } : {}) });
    };
    const dateSpan = isDateSpan(draft.span);
    const select = (field, label, options, { numeric = false, all = null, disabled = false, hideLabel = false } = {}) => <label className="record-room-field">{!hideLabel && <span>{label}</span>}<select aria-label={hideLabel ? label : undefined} value={draft[field]} disabled={disabled} onChange={event => {
        const value = numeric && event.target.value !== 'custom' && event.target.value !== 'utility' ? Number(event.target.value) : event.target.value;
        // 시작 시즌을 바꾸면 끝 시즌이 따라온다(한 시즌만 보던 중이면 그대로 한 시즌, 범위면 시작보다 앞서지 않게).
        // 통산을 켜 두었다면 끝 시즌은 그대로 두고, 시작보다 앞서게 될 때만 맞춘다.
        update(field === 'season' && typeof value === 'number' ? { season: value, to: draft.career ? Math.max(draft.to, value) : draft.season === draft.to ? value : Math.max(draft.to, value) } : { [field]: value });
    }}>{all !== null && <option value="">{all}</option>}{options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>;
    // 직접 입력: 이닝이면 6.1·6.2처럼 소수점 아래 0·1·2만, 타석·경기 수면 숫자만 들어간다. 단위를 바꾸면 맞지 않는 값은 비운다.
    const minInnings = customMinInnings(type, draft);
    const minValueInput = label => <input type="text" inputMode={minInnings ? 'decimal' : 'numeric'} aria-label={label} value={draft.minValue} onChange={event => { if (customMinText(event.target.value, minInnings)) update({ minValue: event.target.value }); }} />;
    const candidatesOnly = (draft.goldenGlove && regularSeason) || (draft.award && type === 'fielding') || draft.players.length > 0;
    const check = (field, label) => <label className="record-room-chip"><input type="checkbox" checked={draft[field]} onChange={event => update({ [field]: event.target.checked })} /><span>{label}</span></label>;
    const yearOptions = [...(years.includes(draft.season) ? [] : [[draft.season, draft.season]]), ...years.map(year => [year, year])];
    const showSeason = Boolean(params?.splitSeasons);
    const showPosition = kind === 'batter';
    // 타자의 주 포지션은 따로 열을 두지 않고 이름 옆에 붙인다.
    const leading = [40, (showSeason ? 112 : 88) + (showPosition ? 20 : 0)];
    const mobileLeading = [32, (showSeason ? 96 : 74) + (showPosition ? 18 : 0)];
    const widths = [...leading, ...columns.map(([label, key]) => recordColumnWidth(label, key))];
    const mobileWidths = [...mobileLeading, ...columns.map(([label, key]) => recordColumnWidth(label, key, true) + (sort.key === key ? 8 : 0))];
    const tableStyle = { '--record-table-width': `${widths.reduce((sum, width) => sum + width, 0)}px`, '--record-table-mobile-width': `${mobileWidths.reduce((sum, width) => sum + width, 0)}px` };
    const tableClass = `profile-season-games profile-year-records record-room-table${showSeason ? ' has-season' : ''}${showPosition ? ' has-position' : ''}`;
    const colgroup = <colgroup>{widths.map((width, index) => <col key={index} style={{ '--record-column-width': `${width}px`, '--record-column-mobile-width': `${mobileWidths[index]}px` }} />)}</colgroup>;
    const heading = (label, key) => {
        if (key === 'position') return <th key={key} scope="col" className="profile-year-position-cell">{label}</th>;
        const sorted = sort.key === key;
        return <th key={key} scope="col" title={metricHints[key]} className={sorted ? 'is-sorted' : undefined} aria-sort={sorted ? (sort.direction === 'desc' ? 'descending' : 'ascending') : 'none'}>
            <button type="button" className="profile-year-sort-heading" onClick={() => changeSort(key)} aria-label={`${label} 정렬`}>{label}{sorted && <span aria-hidden="true">{sort.direction === 'desc' ? '▾' : '▴'}</span>}</button>
        </th>;
    };
    const headRow = <tr><th className="profile-year-cell" scope="col">순위</th><th className="profile-year-team-cell" scope="col">선수</th>{columns.map(([label, key]) => heading(label, key))}</tr>;
    const columnCount = widths.length;
    const empty = !result.loading && !result.rows.length;
    const cellText = (key, value) => value === null || value === undefined ? '' : key === 'position' ? fieldingPositionLabels[value] || value : percentKeys.includes(key) ? `${value}%` : value;

    return <main className="record-room font-family-NaSqNe">
        <div className="record-room-card">
            <div className="profile-heading record-room-heading">
                <h2>선수 기록실</h2>
                <div className="record-room-types" role="group" aria-label="기록 종류 선택" style={{ '--type-index': types.findIndex(([value]) => value === type) }}>{types.map(([value, label]) => <button type="button" key={value} aria-pressed={type === value} onClick={() => changeType(value)}>{label}</button>)}</div>
            </div>
            <button type="button" className="record-room-filter-toggle" aria-haspopup="dialog" onClick={() => setFilterOpen(true)}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" /></svg>
                <span>검색 조건</span>
                {resolved?.label && <strong>{resolved.label}</strong>}
                <svg className="record-room-filter-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>
            </button>
            {filterOpen && <div className="record-room-modal-backdrop" onPointerDown={event => { if (event.target === event.currentTarget) closeFilter(); }}><form id="record-room-filter" className="record-room-modal" role="dialog" aria-modal="true" aria-labelledby="record-room-filter-title" onSubmit={search}>
                <div className="record-room-modal-head"><strong id="record-room-filter-title">검색 조건</strong><button type="button" aria-label="검색 조건 닫기" onClick={closeFilter}><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" /></svg></button></div>
                <div className="record-room-modal-body">
                    <section className="record-room-group">
                        <div className="record-room-fields">
                    {gameLog && <label className="record-room-field"><span>시즌 종류</span><select value={draft.seasonType} onChange={event => changeSeasonType(event.target.value)}>{seasonTypes.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>}
                    <div className="record-room-range">
                        {select('season', '시즌', yearOptions, { numeric: true, disabled: dateSpan })}
                        <span className="record-room-tilde" aria-hidden="true">~</span>
                        {select('to', '끝 시즌', years.map(year => [year, year]), { numeric: true, disabled: dateSpan, hideLabel: true })}
                        <label className="record-room-chip record-room-toggle"><input type="checkbox" checked={draft.career} onChange={event => update({ career: event.target.checked })} /><span>통산</span></label>
                    </div>
                    {gameLog && <span className="record-room-break" aria-hidden="true" />}
                    {gameLog && select('span', '기간', regularSeason ? spanOptions : spanOptions.filter(([value]) => !seasonParts.includes(value)))}
                    {draft.span === 'pickMonth' && select('month', '월', [3, 4, 5, 6, 7, 8, 9, 10, 11].map(month => [month, `${month}월`]), { numeric: true })}
                    {draft.span === 'custom' && <div className="record-room-range">
                        <label className="record-room-field"><span>시작일</span><input type="date" min={`${GAME_LOG_FIRST_YEAR}-01-01`} max={meta.today} value={draft.start} onChange={event => update({ start: event.target.value })} /></label>
                        <span className="record-room-tilde" aria-hidden="true">~</span>
                        <label className="record-room-field"><span>종료일</span><input type="date" min={`${GAME_LOG_FIRST_YEAR}-01-01`} max={meta.today} value={draft.end} onChange={event => update({ end: event.target.value })} /></label>
                    </div>}
                        </div>
                    </section>
                    <section className="record-room-group">
                        <div className="record-room-fields">
                    <OpponentPicker label="선수" value={draft.players} onChange={players => update({ players })} />
                    {select('team', '팀', franchises.map(team => [team, team]), { all: '전체' })}
                    {select('pos', '주 포지션', positionOptions[type], { all: '전체' })}
                    {handOptions[type].length > 0 && select('hand', type === 'pitcher' ? '투구' : '타석', handOptions[type], { all: '전체' })}
                    <div className="record-room-field record-room-age"><select aria-label="나이 조건 종류" value={draft.ageBy} onChange={event => update({ ageBy: event.target.value })}><option value="age">나이</option><option value="birth">출생 연도</option></select>{draft.ageBy === 'birth'
                        ? <div><input type="number" min="1900" max="2100" inputMode="numeric" aria-label="출생 연도 시작" value={draft.birthMin} onChange={event => update({ birthMin: event.target.value })} /><i aria-hidden="true">~</i><input type="number" min="1900" max="2100" inputMode="numeric" aria-label="출생 연도 끝" value={draft.birthMax} onChange={event => update({ birthMax: event.target.value })} /></div>
                        : <div><input type="number" min="10" max="60" inputMode="numeric" aria-label="최소 나이" value={draft.ageMin} onChange={event => update({ ageMin: event.target.value })} /><i aria-hidden="true">~</i><input type="number" min="10" max="60" inputMode="numeric" aria-label="최대 나이" value={draft.ageMax} onChange={event => update({ ageMax: event.target.value })} /></div>}</div>
                        </div>
                        <div className="record-room-fields">
                    <div className="record-room-min">
                    {candidatesOnly
                        ? <label className="record-room-field"><span>{type === 'fielding' ? '최소 조건' : `최소 ${minUnit(tableKind(type, draft))}`}</span><select value="0" disabled><option value="0">제한 없음</option></select></label>
                        : type === 'fielding'
                            ? select('min', '최소 조건', fieldingMinOptions, { numeric: true })
                            : select('min', `최소 ${minUnit(tableKind(type, draft))}`, minOptions.map(([value, text]) => [value, value === 100 ? (tableKind(type, draft) === 'pitcher' ? '규정이닝' : '규정타석') : text]), { numeric: true })}
                    <div className={`record-room-scope${draft.minScope === 'all' ? ' is-all' : ''}`} role="group" aria-label="최소 조건을 적용할 정렬">{[['rate', '비율 기록만'], ['all', '모든 기록']].map(([value, text]) => <button type="button" key={value} aria-pressed={draft.minScope === value} disabled={candidatesOnly || draft.min === 0} onClick={() => update({ minScope: value })}>{text}</button>)}</div>
                    </div>
                    {!candidatesOnly && draft.min === 'custom' && <span className="record-room-break" aria-hidden="true" />}
                    {!candidatesOnly && type === 'fielding' && draft.min === 'custom' && <div className="record-room-field record-room-age"><select aria-label="최소 조건 단위" value={draft.minUnit} onChange={event => update({ minUnit: event.target.value, minValue: event.target.value === 'games' ? String(draft.minValue).split('.')[0] : draft.minValue })}><option value="innings">이닝</option><option value="games">경기</option></select><div>{minValueInput('최소 값')}</div></div>}
                    {!candidatesOnly && type !== 'fielding' && draft.min === 'custom' && <label className="record-room-field record-room-number"><span>{minUnit(tableKind(type, draft))} 수</span>{minValueInput()}</label>}
                        </div>
                    </section>
                    {gameLog && <section className="record-room-group">
                        <div className="record-room-fields">
                    {select('opp', '상대팀', franchises.map(team => [team, team]), { all: '전체' })}
                    {select('stadium', '구장', meta.stadiums.map(name => [name, name]), { all: '전체' })}
                    <OpponentPicker label={type === 'pitcher' ? '상대 타자' : '상대 투수'} value={draft.vs} onChange={vs => update({ vs })} />
                        </div>
                    </section>}
                    <section className="record-room-group">
                        <div className="record-room-checks">
                            {check('active', '현역만')}
                            {check('foreign', '외국인 선수만')}
                            <span className="record-room-check-group">{check('prospect', '유망주만')}{meta.prospects?.source && <a className="record-room-source" href={meta.prospects.source} target="_blank" rel="noreferrer" title={`${meta.prospects.title} · ${meta.prospects.revised} 수정본 기준`}>명단<svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 5h11v11M19 5 6 18" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" /></svg></a>}</span>
                            {regularSeason && check('goldenGlove', '골든글러브 후보만')}
                            {type === 'fielding' && check('award', '수비상 후보만')}
                            {check('splitTeams', '이적한 선수 팀별 분리')}
                            {type === 'fielding' && check('mergePositions', '포지션 합쳐 보기')}
                        </div>
                    </section>
                    {formError && <p className="record-room-hint is-error" role="alert">{formError}</p>}
                </div>
                <div className="record-room-modal-foot">
                    <button type="button" className="record-room-reset" onClick={() => { setDraft(defaultFilter(meta)); setFormError(''); }}><svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2 8M3 4v6h6" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" /></svg>초기화</button>
                    <button type="submit" className={`record-room-search${dirty ? ' is-dirty' : ''}`}>검색</button>
                </div>
            </form></div>}
            {tabs.length > 0 && <div className="profile-year-views" ref={viewsRef} role="group" aria-label="기록 종류">{tabs.map(([key, label]) => <button key={key} type="button" aria-pressed={view === key} onClick={() => changeView(key)}>{label}</button>)}{indicator && <span className="profile-year-view-indicator" aria-hidden="true" style={indicator} />}</div>}
            <div className="profile-year-table-wrap">
                <div className="profile-table-sticky-head" ref={stickyRef} aria-hidden="true"><div><table className={tableClass} style={tableStyle}>{colgroup}<thead>{headRow}</thead></table></div></div>
                <div className="profile-game-scroll" ref={scrollRef}><table className={`${tableClass}${result.loading ? ' is-loading' : ''}`} aria-busy={result.loading} style={tableStyle}>
                    {colgroup}
                    <caption className="sr-only">{resolved?.label} {types.find(([value]) => value === type)[1]} 기록 순위</caption>
                    <thead>{headRow}</thead>
                    <tbody>{empty || (result.loading && !result.rows.length)
                        ? <tr aria-hidden="true"><td className={result.loading ? 'profile-year-loading-space' : 'profile-year-empty-space'} colSpan={columnCount} /></tr>
                        : result.rows.map(row => {
                            const logo = teamLogo(row.team, row.year);
                            return <tr key={`${row.id}|${row.season}|${row.team}|${row.stats?.position}`}>
                                <td className="profile-year-cell record-room-rank">{row.rank}</td>
                                <td className="profile-year-team-cell record-room-player"><Link to={`/?pid=${row.id}`} title={[row.name, row.team, row.teams > 1 ? `외 ${row.teams - 1}팀` : ''].filter(Boolean).join(' ')}>{logo ? <img src={logo} alt="" /> : <i aria-hidden="true" />}{row.season && <small>&rsquo;{String(row.season).slice(-2)}</small>}<span className={`record-room-name${[...row.name].length >= (row.season ? 4 : 5) ? ' is-marquee' : ''}`}><b>{row.name}</b><b aria-hidden="true">{row.name}</b></span>{showPosition && row.pos && <em>{row.pos}</em>}</Link></td>
                                {columns.map(([, key]) => <td key={key} className={key === 'position' ? 'profile-year-position-cell' : sort.key === key ? 'is-sorted' : undefined}>{cellText(key, row.stats?.[key])}</td>)}
                            </tr>;
                        })}</tbody>
                </table></div>
                {result.loading && <div className="profile-year-table-loading"><Spinner size="lg" className="fill-blue-600" aria-label="기록 불러오는 중" /></div>}
                {empty && <p className="profile-games-empty profile-year-empty-message">{result.error || '조건에 맞는 기록이 없습니다.'}</p>}
            </div>
            {!result.loading && result.rows.length < result.total && <button type="button" className="record-room-more" disabled={result.loadingMore} onClick={loadMore}>{result.loadingMore ? '불러오는 중…' : `더 보기 (${result.rows.length.toLocaleString()} / ${result.total.toLocaleString()})`}</button>}
        </div>
    </main>;
}
