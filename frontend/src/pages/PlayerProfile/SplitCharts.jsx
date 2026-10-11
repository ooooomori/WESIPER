import { useEffect, useMemo, useState } from 'react';
import GameLogFilter from './GameLogFilter';
import ProfileLoading from './ProfileLoading';
import { getCachedProfileData, loadProfileData } from './profileDataCache';
import { buildInningRows, buildSplits } from './splitStats';
import SplitModal from './SplitModal';

const metricsByKind = {
    batter: [{ key: 'avg', label: '타율', format: value => value.toFixed(3).replace(/^0\./, '.') }, { key: 'ops', label: 'OPS', format: value => value.toFixed(3).replace(/^0\./, '.') }],
    pitcher: [{ key: 'era', label: 'ERA', lowerBetter: true, format: value => value.toFixed(2) }, { key: 'whip', label: 'WHIP', lowerBetter: true, format: value => value.toFixed(2) }],
};
const WIDTH = 340, LABEL = 58, VALUE = 44, BAR = 13, GAP = 3, PAD = 9;
const splitTabs = [['month', '월별'], ['opponent', '상대별'], ['inning', '이닝별'], ['venue', '홈/원정']];

// 구분을 위에서 아래로 늘어놓고 막대는 가로로 그린다. 지표마다 단위가 달라 각자의 최댓값 기준으로 길이를 잡는다.
function SplitBarChart({ rows, metrics, hidden = [], animationKey, labelColor, onSelect }) {
    const maxes = Object.fromEntries(metrics.map(metric => [metric.key, Math.max(...rows.map(row => row[metric.key] ?? 0), 0) || 1]));
    const rowHeight = PAD * 2 + metrics.length * BAR + (metrics.length - 1) * GAP;
    const trackWidth = WIDTH - LABEL - VALUE;
    // 구분 안에서 가장 높은 값과 가장 낮은 값을 지표마다 강조한다. 값이 모두 같거나 한 줄뿐이면 강조하지 않는다.
    const extremes = Object.fromEntries(metrics.map(metric => {
        const values = rows.map(row => row[metric.key]).filter(value => value != null);
        const high = Math.max(...values), low = Math.min(...values);
        // 빨강은 좋은 기록, 파랑은 나쁜 기록이라 ERA·WHIP은 낮은 값이 빨강이다.
        return [metric.key, values.length > 1 && high > low ? (metric.lowerBetter ? { high: low, low: high } : { high, low }) : null];
    }));
    return <svg className="profile-split-svg" key={animationKey} viewBox={`0 0 ${WIDTH} ${rowHeight * rows.length}`} role="img" aria-label={`${metrics.map(metric => metric.label).join('·')} 막대차트`}>
        <line className="profile-split-axis" x1={LABEL - .5} x2={LABEL - .5} y1="0" y2={rowHeight * rows.length} />
        {rows.map((row, index) => {
            const top = index * rowHeight;
            const color = labelColor?.(row);
            return <g key={row.key}>
                {index > 0 && <line className="profile-split-divider" x1="0" x2={WIDTH} y1={top} y2={top} />}
                <text className="profile-split-label" x={LABEL - 10} y={top + rowHeight / 2 - 1} textAnchor="end" style={color ? { fill: color } : undefined}>{row.label}</text>
                <text className="profile-split-games" x={LABEL - 10} y={top + rowHeight / 2 + 11} textAnchor="end">{row.games}{row.unit || '경기'}</text>
                {metrics.map((metric, slot) => {
                    const value = row[metric.key];
                    // 숨긴 지표도 자리는 그대로 두어 남은 막대가 움직이지 않게 한다.
                    if (value == null || hidden.includes(metric.key)) return null;
                    const y = top + PAD + slot * (BAR + GAP);
                    const length = Math.max(value / maxes[metric.key] * trackWidth, 1);
                    const delay = `${index * 45 + slot * 60}ms`;
                    // 세로선에 붙는 왼쪽은 각지게, 값 쪽 오른쪽만 둥글게 한다.
                    const radius = Math.min(3, length / 2);
                    const extreme = extremes[metric.key];
                    const mark = extreme && value === extreme.high ? 'is-high' : extreme && value === extreme.low ? 'is-low' : '';
                    return <g key={metric.key}>
                        <path className={`profile-split-bar is-${metric.order}`} d={`M${LABEL} ${y}H${LABEL + length - radius}a${radius} ${radius} 0 0 1 ${radius} ${radius}V${y + BAR - radius}a${radius} ${radius} 0 0 1 ${-radius} ${radius}H${LABEL}Z`} style={{ animationDelay: delay }} />
                        <text className={`profile-split-value ${mark}`} x={LABEL + length + 5} y={y + BAR / 2 + 3.5} style={{ animationDelay: delay }}>{metric.format(value)}</text>
                    </g>;
                })}
                <rect className="profile-split-hit" x="0" y={top} width={WIDTH} height={rowHeight} tabIndex="0" role="button" aria-label={`${row.label} 기록 보기`}
                    onClick={() => onSelect?.(row)} onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); onSelect?.(row); } }} />
            </g>;
        })}
    </svg>;
}

export default function SplitCharts({ pid, pitcher, active = true, getTeamColor }) {
    const [selection, setSelection] = useState({ year: '', season: '' });
    const [log, setLog] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [split, setSplit] = useState('month');
    const [hiddenMetrics, setHiddenMetrics] = useState([]);
    const [picked, setPicked] = useState(null);
    const kind = pitcher ? 'pitcher' : 'batter';

    useEffect(() => {
        let current = true;
        const query = new URLSearchParams({ pid, part: 'games', type: kind });
        if (selection.season) query.set('season', selection.season);
        if (selection.year) query.set('year', selection.year);
        const url = `/api/playerProfile.php?${query}`;
        const cached = getCachedProfileData(url);
        setError('');
        if (cached) { setLog(cached); setLoading(false); return undefined; }
        setLoading(true);
        loadProfileData(url, data => Array.isArray(data.games) && Array.isArray(data.years))
            .then(data => { if (current) setLog(data); })
            .catch(() => { if (current) setError('경기 기록을 불러오지 못했습니다.'); })
            .finally(() => { if (current) setLoading(false); });
        return () => { current = false; };
    }, [pid, kind, selection.year, selection.season, retry]);

    // 이닝별은 타석 단위 기록이 필요해 따로 불러온다(타자만).
    const [innings, setInnings] = useState({ key: '', rows: null, error: false });
    const inningKey = log ? `${pid}-${log.year}-${log.season}` : '';
    useEffect(() => {
        if (split !== 'inning' || pitcher || !log?.year || innings.key === inningKey) return undefined;
        let current = true;
        const query = new URLSearchParams({ pid, part: 'innings', year: log.year, season: log.season });
        loadProfileData(`/api/playerProfile.php?${query}`, data => Array.isArray(data.rows))
            .then(data => { if (current) setInnings({ key: inningKey, rows: data.rows, error: false }); })
            .catch(() => { if (current) setInnings({ key: inningKey, rows: [], error: true }); });
        return () => { current = false; };
    }, [split, pitcher, pid, log?.year, log?.season, inningKey, innings.key]);
    const inningLoading = split === 'inning' && innings.key !== inningKey;
    const tabs = splitTabs.filter(([id]) => id !== 'inning' || !pitcher);
    const splits = useMemo(() => log ? buildSplits(log.games, pitcher) : null, [log, pitcher]);
    const metrics = metricsByKind[kind].map((metric, order) => ({ ...metric, order }));
    const shown = metrics.filter(metric => !hiddenMetrics.includes(metric.key));
    // 지표를 모두 숨기면 차트가 비므로 마지막 하나는 남긴다.
    const toggleMetric = key => setHiddenMetrics(list => list.includes(key) ? list.filter(item => item !== key) : shown.length > 1 ? [...list, key] : list);
    const hasData = splits?.groups.some(group => group.rows.length);
    // 통산은 어느 해든 있는 시즌 종류를 모두 고를 수 있다.
    const availableSeasons = { ...(log?.availableSeasons || {}), all: [...new Set(Object.values(log?.availableSeasons || {}).flat())] };
    const filter = <GameLogFilter years={log?.years || []} year={selection.year || log?.year} season={selection.season || log?.season || 'regular'} availableSeasons={availableSeasons} includeAll
        onYearChange={year => { const available = availableSeasons[year] || []; const current = selection.season || log?.season; setSelection({ year, season: available.includes(current) ? current : available[0] || '' }); }}
        onSeasonChange={season => setSelection({ year: String(selection.year || log?.year || ''), season })} />;

    const group = split === 'inning' ? { rows: innings.key === inningKey ? buildInningRows(innings.rows) : [] } : splits?.groups.find(item => item.id === split);
    return <section className="profile-split-section" aria-busy={loading} hidden={!active}>
        <div className="profile-heading profile-candle-heading"><h2>시즌 스플릿 차트</h2>{filter}</div>
        {loading ? <ProfileLoading>기록을 불러오는 중이에요.</ProfileLoading>
            : error ? <div className="profile-candle-status" role="alert"><p>{error}</p><button type="button" onClick={() => setRetry(value => value + 1)}>다시 시도</button></div>
            : !hasData ? <p className="profile-games-empty">차트는 2001년 이후 기록부터 제공되어요.</p>
            : <div className="profile-split-card">
                <div className="profile-split-toolbar">
                    <div className="profile-split-tabs" role="group" aria-label="스플릿 구분">{tabs.map(([id, label]) => <button key={id} type="button" aria-pressed={split === id} onClick={() => setSplit(id)}>{label}</button>)}</div>
                    <div className="profile-split-legend" role="group" aria-label="지표 표시">{metrics.map(metric => <button key={metric.key} type="button" className={`is-${metric.order}`} aria-pressed={!hiddenMetrics.includes(metric.key)} onClick={() => toggleMetric(metric.key)}><i aria-hidden="true" />{metric.label}{splits.total[metric.key] != null && <b>{metric.format(splits.total[metric.key])}</b>}</button>)}</div>
                </div>
                {inningLoading ? <ProfileLoading>기록을 불러오는 중이에요.</ProfileLoading> : innings.error && split === 'inning' ? <p className="profile-games-empty">이닝별 기록을 불러오지 못했습니다.</p> : group?.rows.length ? <SplitBarChart rows={group.rows} metrics={metrics} hidden={hiddenMetrics} animationKey={`${split}-${log?.year}-${log?.season}`} labelColor={split === 'opponent' && getTeamColor ? row => getTeamColor(row.label) : undefined} onSelect={row => setPicked({ row, split })} /> : <p className="profile-games-empty">표시할 기록이 없습니다.</p>}
            </div>}
        {picked && <SplitModal key={`${picked.split}-${picked.row.key}`} row={picked.row} split={picked.split} pitcher={pitcher} year={log?.year} season={log?.season} fipConstants={log?.fipConstants} onClose={() => setPicked(null)} />}
    </section>;
}
