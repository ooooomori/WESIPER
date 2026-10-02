import { useEffect, useRef, useState } from 'react';
import { loadProfileData } from './profileDataCache';
import { loadYearRecords } from './yearRecordsCache';
import './player-compare.css';

const MAX_PLAYERS = 5;
// 탭을 옮겨도 비교 설정이 유지되도록 선수(pid)별로 기억한다. 새로고침하면 초기화된다.
const savedCompareStates = new Map();
const ip = value => { // "68.2" 이닝 → 68.667
    if (value == null || value === '') return null;
    const [whole, outs = '0'] = String(value).split('.');
    return Number(whole) + Number(outs) / 3;
};
// [키, 라벨, 높을수록 좋은가(true)/낮을수록(false), 표시 형식]
const BATTER_STATS = [
    ['games', '경기', true], ['pa', '타석', true], ['avg', '타율', true, 'rate'], ['h', '안타', true], ['hr', '홈런', true],
    ['rbi', '타점', true], ['r', '득점', true], ['sb', '도루', true], ['bb', '볼넷', true], ['so', '삼진', false],
    ['obp', '출루율', true, 'rate'], ['slg', '장타율', true, 'rate'], ['ops', 'OPS', true, 'rate'], ['opsPlus', 'OPS+', true], ['woba', 'wOBA', true, 'rate'],
];
const PITCHER_STATS = [
    ['games', '경기', true], ['innings', '이닝', true, 'innings'], ['wins', '승리', true], ['losses', '패배', false], ['saves', '세이브', true],
    ['holds', '홀드', true], ['era', '평균자책점', false, 'era'], ['whip', 'WHIP', false, 'era'], ['so', '탈삼진', true], ['bb', '볼넷', false],
    ['qs', 'QS', true], ['k9', 'K/9', true, 'era'], ['eraPlus', 'ERA+', true], ['fip', 'FIP', false, 'era'], ['opponentAvg', '피안타율', false, 'rate'],
];
const AWARD_TYPES = ['MVP', '골든글러브', '수비상', '신인왕', '올스타', '월간 MVP', '우승'];
const numeric = (key, value) => key === 'innings' ? ip(value) : value == null || value === '' ? null : Number(value);
const display = (format, value) => {
    if (value == null || value === '') return '—';
    if (format === 'rate') return Number(value).toFixed(3).replace(/^0\./, '.');
    if (format === 'era') return Number(value).toFixed(2);
    if (format === 'innings') return String(value);
    return Number(value).toLocaleString('ko-KR');
};
const isPitcherPos = pos => String(pos || '').includes('투수');

function PlayerPhoto({ pid }) {
    const [step, setStep] = useState(0);
    useEffect(() => setStep(0), [pid]);
    if (step >= 2) return <span className="compare-photo-empty" aria-hidden="true" />;
    return <img src={`/assets/images/player/kbo/${encodeURIComponent(pid)}.${step === 0 ? 'jpg' : 'png'}`} alt="" onError={() => setStep(value => value + 1)} />;
}

function PlayerSearch({ onSelect, open, setOpen }) {
    const [keyword, setKeyword] = useState('');
    const [results, setResults] = useState({ status: 'idle', list: [] });
    const input = useRef(null);
    useEffect(() => { if (open) input.current?.focus(); }, [open]);
    useEffect(() => {
        const query = keyword.trim();
        if (query.length < 2 && !['홀', '필', '얀'].includes(query)) { setResults({ status: 'idle', list: [] }); return; }
        const controller = new AbortController();
        setResults(previous => ({ ...previous, status: 'loading' }));
        const timer = window.setTimeout(() => {
            fetch('/api/kbocandle/get_player_list.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: query }), signal: controller.signal })
                .then(response => response.json())
                .then(result => setResults({ status: 'ready', list: (Array.isArray(result) ? result : result.list || []).slice(0, 12) }))
                .catch(error => { if (error.name !== 'AbortError') setResults({ status: 'error', list: [] }); });
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [keyword]);
    const close = () => { setOpen(false); setKeyword(''); };
    if (!open) return null;
    return <div className="compare-search" onKeyDown={event => { if (event.key === 'Escape') close(); }}>
        <div className="compare-search-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3" /><path d="m15.6 15.6 4.4 4.4" /></svg>
            <input ref={input} type="search" value={keyword} onChange={event => setKeyword(event.target.value)} placeholder="비교할 선수 이름" aria-label="비교할 선수 이름" autoComplete="off" />
            <button type="button" onClick={close}>취소</button>
        </div>
        {results.status === 'loading' && <p className="compare-search-note">찾는 중…</p>}
        {results.status === 'error' && <p className="compare-search-note">검색에 실패했어요. 다시 입력해 주세요.</p>}
        {results.status === 'ready' && !results.list.length && <p className="compare-search-note">검색 결과가 없어요.</p>}
        {results.list.length > 0 && <ul className="compare-search-results">{results.list.map(player => {
            const active = player.IsActive !== false && player.Team !== '은퇴';
            return <li key={player.PlayerId}><button type="button" onClick={() => { onSelect(player); close(); }}>
                <span className="compare-search-photo"><PlayerPhoto pid={player.PlayerId} /></span>
                <strong>{player.Name}</strong>
                <small>{[active ? player.Team : player.FormerTeam ? `前 ${player.FormerTeam}` : '은퇴', player.MainPos || player.Pos].filter(Boolean).join(' · ')}</small>
            </button></li>;
        })}</ul>}
    </div>;
}

function CompareColumnHead({ entry, data, kind, mode, onYear, onRemove, getTeamLogo, getTeamColor }) {
    const profile = data.profile?.player;
    const rows = data.records?.[kind]?.rows?.filter(row => Number(row.stats?.games) > 0) || [];
    const years = [...new Set(rows.map(row => row.year))].sort((a, b) => b - a);
    const year = entry.year ?? years[0] ?? null;
    const season = rows.find(row => row.year === year);
    const team = mode === 'season' ? season?.team || profile?.Team : (profile?.IsKbodle === 0 || profile?.IsKbodle === '0' ? profile?.FormerTeam : profile?.Team);
    const logo = team ? getTeamLogo(team) : null;
    return <th scope="col" style={{ '--compare-color': getTeamColor(team) || '#60758c' }}>
        <div className="compare-head">
            {onRemove && <button type="button" className="compare-remove" aria-label={`${profile?.Name || '선수'} 비교에서 빼기`} onClick={onRemove}>×</button>}
            <span className="compare-head-photo"><PlayerPhoto pid={entry.pid} />{logo && <img className="compare-head-logo" src={logo} alt="" />}</span>
            <strong>{profile?.Name || '…'}</strong>
            <small>{team || (data.status === 'error' ? '불러오기 실패' : '')}</small>
            {mode === 'season' && <select value={year ?? ''} onChange={event => onYear(Number(event.target.value))} aria-label={`${profile?.Name || '선수'} 비교 연도`} disabled={!years.length}>
                {years.length ? years.map(value => <option key={value} value={value}>{value}년</option>) : <option value="">기록 없음</option>}
            </select>}
            {mode === 'career' && <span className="compare-head-tag">통산</span>}
        </div>
    </th>;
}

export default function PlayerCompare({ pid, player, getTeamLogo, getTeamColor }) {
    const saved = savedCompareStates.get(String(pid));
    const [entries, setEntries] = useState(() => saved?.entries || [{ id: 'base', pid: String(pid), year: null }]);
    const [mode, setMode] = useState(() => saved?.mode || 'season');
    const [kind, setKind] = useState(() => saved?.kind || (isPitcherPos(player?.Pos) ? 'pitcher' : 'batter'));
    useEffect(() => { savedCompareStates.set(String(pid), { entries, mode, kind }); }, [pid, entries, mode, kind]);
    const add = selected => setEntries(list => list.length >= MAX_PLAYERS ? list : [...list, { id: `${selected.PlayerId}-${Date.now()}`, pid: String(selected.PlayerId), year: null }]);
    const remove = id => setEntries(list => list.filter(entry => entry.id !== id));
    const setYear = (id, year) => setEntries(list => list.map(entry => entry.id === id ? { ...entry, year } : entry));
    const [searchOpen, setSearchOpen] = useState(false);
    const full = entries.length >= MAX_PLAYERS;
    const switches = <div className="profile-compare-switches">
        <div className={`profile-game-switch ${mode === 'career' ? 'is-detailed' : ''}`} role="group" aria-label="비교 기준">
            {[['season', '시즌'], ['career', '통산']].map(([id, label]) => <button key={id} type="button" className={mode === id ? 'active' : ''} aria-pressed={mode === id} onClick={() => setMode(id)}>{label}</button>)}
        </div>
        <div className={`profile-game-switch ${kind === 'pitcher' ? 'is-detailed' : ''}`} role="group" aria-label="기록 종류">
            {[['batter', '타자'], ['pitcher', '투수']].map(([id, label]) => <button key={id} type="button" className={kind === id ? 'active' : ''} aria-pressed={kind === id} onClick={() => setKind(id)}>{label}</button>)}
        </div>
    </div>;
    return <section className="profile-compare">
        <div className="profile-heading profile-compare-heading">
            <h2>선수 비교</h2>
            {switches}
        </div>
        <div className="profile-compare-actions">
            <button type="button" className="compare-add" disabled={full} aria-expanded={searchOpen} onClick={() => setSearchOpen(value => !value)} title={full ? `최대 ${MAX_PLAYERS}명까지 비교할 수 있어요` : undefined}>
                <span aria-hidden="true">+</span>{full ? `최대 ${MAX_PLAYERS}명` : '선수 추가'}
            </button>
        </div>
        <PlayerSearch onSelect={add} open={searchOpen && !full} setOpen={setSearchOpen} />
        <CompareTable entries={entries} mode={mode} kind={kind} onYear={setYear} onRemove={remove} getTeamLogo={getTeamLogo} getTeamColor={getTeamColor} />
    </section>;
}

function CompareTable({ entries, mode, kind, switches, onYear, onRemove, getTeamLogo, getTeamColor }) {
    // 선수 수가 바뀌어도 훅 순서를 지키도록 최대 인원만큼 고정 호출한다.
    const slots = Array.from({ length: MAX_PLAYERS }, (_, index) => entries[index]?.pid ?? null);
    const datas = slots.map(slotPid => useCompareDataOptional(slotPid)); // eslint-disable-line react-hooks/rules-of-hooks
    const columns = entries.map((entry, index) => {
        const data = datas[index];
        const rows = data.records?.[kind]?.rows?.filter(row => Number(row.stats?.games) > 0) || [];
        const year = entry.year ?? Math.max(...rows.map(row => row.year), -Infinity);
        const stats = mode === 'career' ? (data.records?.[kind]?.career && Number(data.records[kind].career.games) > 0 ? data.records[kind].career : null) : rows.find(row => row.year === year)?.stats || null;
        const career = data.profile?.career || [];
        const inScope = row => mode === 'career' || Number(row.year) === year;
        const awards = Object.fromEntries(AWARD_TYPES.map(type => [type, career.filter(row => row.category === 'award' && row.type === type && inScope(row)).length]));
        const national = career.filter(row => row.category === 'national' && inScope(row)).length;
        const profile = data.profile?.player;
        const retired = profile?.IsKbodle === 0 || profile?.IsKbodle === '0';
        const team = mode === 'season' ? rows.find(row => row.year === year)?.team || profile?.Team : (retired ? profile?.FormerTeam : profile?.Team);
        // 이긴 기록은 그 기록을 가진 선수의 팀 색으로 강조한다.
        const cellStyle = { '--cell-color': getTeamColor(team) || '#3a5f8f' };
        return { entry, data, stats, awards, national, cellStyle };
    });
    const statDefs = kind === 'pitcher' ? PITCHER_STATS : BATTER_STATS;
    const best = (values, higher) => {
        const valid = values.filter(value => value != null && Number.isFinite(value));
        if (columns.length < 2 || valid.length < 2) return null;
        const target = higher ? Math.max(...valid) : Math.min(...valid);
        return valid.every(value => value === target) ? null : target;
    };
    const awardRows = AWARD_TYPES.filter(type => columns.some(column => column.awards[type] > 0));
    const loading = columns.some(column => column.data.status === 'loading');
    return <div className="profile-compare-scroll" aria-busy={loading}>
        <table className="profile-compare-table" style={{ '--compare-cols': columns.length }}>
            <colgroup><col className="compare-col-label" />{columns.map(({ entry }) => <col key={entry.id} />)}</colgroup>
            <thead><tr>
                <th scope="col" className="compare-corner" aria-label="기록 항목" />
                {columns.map(({ entry, data }, index) => <CompareColumnHead key={entry.id} entry={entry} data={data} kind={kind} mode={mode} onYear={year => onYear(entry.id, year)} onRemove={index === 0 ? null : () => onRemove(entry.id)} getTeamLogo={getTeamLogo} getTeamColor={getTeamColor} />)}
            </tr></thead>
            <tbody>
                <tr className="compare-group"><th colSpan={columns.length + 1} scope="colgroup"><span>주요 기록</span></th></tr>
                {statDefs.map(([key, label, higher, format]) => {
                    const values = columns.map(column => numeric(key, column.stats?.[key]));
                    const target = best(values, higher);
                    return <tr key={key}><th scope="row">{label}</th>{columns.map((column, index) => <td key={column.entry.id} style={column.cellStyle} className={target != null && values[index] === target ? 'is-best' : ''}>
                        {column.data.status === 'loading' ? <span className="compare-skeleton" /> : display(format, column.stats?.[key])}
                    </td>)}</tr>;
                })}
                <tr className="compare-group"><th colSpan={columns.length + 1} scope="colgroup"><span>수상 경력</span></th></tr>
                {awardRows.length ? awardRows.map(type => {
                    const values = columns.map(column => column.awards[type]);
                    const target = best(values, true);
                    return <tr key={type}><th scope="row">{type}</th>{columns.map((column, index) => <td key={column.entry.id} style={column.cellStyle} className={target != null && values[index] === target ? 'is-best' : ''}>{values[index] ? (type === '우승' ? `V${values[index]}` : `${values[index]}회`) : '—'}</td>)}</tr>;
                }) : <tr><th scope="row">수상</th>{columns.map(column => <td key={column.entry.id}>—</td>)}</tr>}
                <tr className="compare-group"><th colSpan={columns.length + 1} scope="colgroup"><span>국가대표</span></th></tr>
                {(() => {
                    const values = columns.map(column => column.national);
                    const target = best(values, true);
                    return <tr><th scope="row">선발 횟수</th>{columns.map((column, index) => <td key={column.entry.id} style={column.cellStyle} className={target != null && values[index] === target ? 'is-best' : ''}>{values[index] ? `${values[index]}회` : '—'}</td>)}</tr>;
                })()}
            </tbody>
        </table>
    </div>;
}

function useCompareDataOptional(pid) {
    const [state, setState] = useState({ status: pid ? 'loading' : 'empty' });
    useEffect(() => {
        if (!pid) { setState({ status: 'empty' }); return undefined; }
        let active = true;
        setState({ status: 'loading' });
        Promise.all([
            loadProfileData(`/api/playerProfile.php?pid=${encodeURIComponent(pid)}&part=profile`, data => !!data.player),
            loadYearRecords(pid),
        ]).then(([profile, records]) => { if (active) setState({ status: 'ready', profile, records }); })
            .catch(() => { if (active) setState({ status: 'error' }); });
        return () => { active = false; };
    }, [pid]);
    return state;
}
