import { useEffect, useMemo, useState } from 'react';
import GameLogFilter from './GameLogFilter';
import ProfileLoading from './ProfileLoading';
import { getCachedProfileData, loadProfileData } from './profileDataCache';
import { buildPaRows, buildSplits } from './splitStats';
import SplitModal from './SplitModal';

const rate = value => value.toFixed(3).replace(/^0\./, '.');
const metricsByKind = {
    batter: [{ key: 'avg', label: '타율', format: rate }, { key: 'ops', label: 'OPS', format: rate }],
    pitcher: [{ key: 'era', label: 'ERA', lowerBetter: true, format: value => value.toFixed(2) }, { key: 'whip', label: 'WHIP', lowerBetter: true, format: value => value.toFixed(2) }],
    faced: [{ key: 'avg', label: '피안타율', lowerBetter: true, format: rate }, { key: 'ops', label: '피OPS', lowerBetter: true, format: rate }],
};
const splitTabs = {
    batter: [['month', '월별'], ['opponent', '상대별'], ['inning', '이닝별'], ['order', '타순별'], ['venue', '홈/원정']],
    pitcher: [['month', '월별'], ['opponent', '상대별'], ['oppOrder', '상대 타순별'], ['venue', '홈/원정']],
};
// 타석 단위 기록이 필요해 따로 불러오는 스플릿. 10회 이후는 연장으로 합쳐서 온다.
const paSplits = {
    inning: { by: 'inning', side: 'batter', label: key => key >= 10 ? '연장' : `${key}회` },
    order: { by: 'order', side: 'batter', label: key => `${key}번` },
    oppOrder: { by: 'order', side: 'pitcher', label: key => `${key}번` },
};
// 기준선이 막대 구간의 이 비율보다 왼쪽으로 가지 않게 한다. 한 구분만 크게 튀어도 나머지 막대가 납작해지지 않는다.
const MIN_BASE = .45;
// 색이 가장 진해지는 기준 대비 차이의 하한과 상한. 차이가 작은 구분끼리 색만 과장되지 않게 하고, 한 구분만 크게 튀어도 나머지가 옅어지지 않게 한다.
const MIN_SPREAD = .1, MAX_SPREAD = 1;

// 막대 길이는 값 그대로(0부터)이고, 세로 점선이 기준(시즌 기록)이다. 기준보다 좋으면 빨강, 나쁘면 파랑이고 기준에서 멀수록 색이 진하다.
function SplitRows({ rows, metrics, total, baseLabel, animationKey, logoOf, onSelect }) {
    // 표본이 다른 구분의 1/3에도 못 미치면 흐리게 그리고 막대 길이 기준에서도 뺀다.
    const samples = rows.filter(row => !row.section).map(row => row.sample).sort((a, b) => a - b);
    const median = samples.length ? samples[Math.floor(samples.length / 2)] : 0;
    const thin = row => !row.section && row.sample < median / 3;
    const scales = Object.fromEntries(metrics.map(metric => {
        const base = total?.[metric.key] ?? null;
        const top = Math.max(base ?? 0, ...rows.filter(row => !thin(row)).map(row => row[metric.key] ?? 0));
        const domain = (base ? Math.min(top, base / MIN_BASE) : top) || 1;
        const gapOf = row => row[metric.key] == null || !base ? 0 : (metric.lowerBetter ? base - row[metric.key] : row[metric.key] - base) / base;
        // 좋은 쪽과 나쁜 쪽의 진하기를 따로 잡는다. ERA처럼 나쁜 쪽만 크게 벌어져도 좋은 기록의 색이 옅어지지 않는다.
        const gaps = rows.filter(row => !thin(row)).map(gapOf);
        const spread = { up: Math.min(Math.max(MIN_SPREAD, ...gaps), MAX_SPREAD), down: Math.min(Math.max(MIN_SPREAD, ...gaps.map(gap => -gap)), MAX_SPREAD) };
        return [metric.key, { base, domain, gapOf, spread }];
    }));
    return <div className="profile-split-chart" key={animationKey} style={{ '--split-metrics': metrics.length }}>
        <div className="profile-split-head">
            <span />
            {metrics.map(metric => {
                const { base, domain } = scales[metric.key];
                return <span key={metric.key} className="profile-split-cell">
                    <span className="profile-split-base">{base != null && <small style={{ left: `${base / domain * 100}%` }}>{baseLabel} {metric.format(base)}</small>}</span>
                </span>;
            })}
        </div>
        {rows.map((row, index) => {
            const logo = logoOf?.(row);
            const sectionStart = index > 0 && row.section !== rows[index - 1].section;
            return <button key={row.key} type="button" className={`profile-split-row${thin(row) ? ' is-thin' : ''}${sectionStart ? ' is-section-start' : ''}`} onClick={() => onSelect?.(row)}
                aria-label={`${row.label} ${row.games}${row.unit || '경기'}, ${metrics.map(metric => `${metric.label} ${row[metric.key] == null ? '기록 없음' : metric.format(row[metric.key])}`).join(', ')}`}>
                <span className="profile-split-name"><b>{logo && <img src={logo} alt="" />}{row.label}</b><small>{row.games}{row.unit || '경기'}</small></span>
                {metrics.map((metric, slot) => {
                    const value = row[metric.key], { base, domain, gapOf, spread } = scales[metric.key];
                    const gap = gapOf(row);
                    const width = value == null ? 0 : Math.max(Math.min(value / domain, 1) * 100, 1);
                    const tone = `${Math.round(20 + Math.min(Math.abs(gap) / (gap >= 0 ? spread.up : spread.down), 1) * 80)}%`;
                    return <span key={metric.key} className="profile-split-cell">
                        <span className="profile-split-track" style={base != null ? { '--split-base': `${base / domain * 100}%` } : undefined}>
                            {value != null && <i className={!base ? '' : gap >= 0 ? 'is-up' : 'is-down'} style={{ '--split-tone': tone, width: `${width}%`, animationDelay: `${index * 35 + slot * 60}ms` }} />}
                            <span className="profile-split-value" style={{ left: `${width}%` }}>{value == null ? '—' : metric.format(value)}</span>
                        </span>
                    </span>;
                })}
                <svg className="profile-split-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
            </button>;
        })}
    </div>;
}

export default function SplitCharts({ pid, pitcher, active = true, getTeamLogo }) {
    const [selection, setSelection] = useState({ year: '', season: '' });
    const [log, setLog] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [split, setSplit] = useState('month');
    const [picked, setPicked] = useState(null);
    const [metricSlot, setMetricSlot] = useState(0);
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

    const paSplit = paSplits[split];
    const [pa, setPa] = useState({ key: '', rows: null, error: false });
    const paKey = log && paSplit ? `${split}-${pid}-${log.year}-${log.season}` : '';
    useEffect(() => {
        if (!paSplit || !log?.year || pa.key === paKey) return undefined;
        let current = true;
        const query = new URLSearchParams({ pid, part: 'innings', by: paSplit.by, side: paSplit.side, year: log.year, season: log.season });
        loadProfileData(`/api/playerProfile.php?${query}`, data => Array.isArray(data.rows))
            .then(data => { if (current) setPa({ key: paKey, rows: data.rows, error: false }); })
            .catch(() => { if (current) setPa({ key: paKey, rows: [], error: true }); });
        return () => { current = false; };
    }, [paSplit, pid, log?.year, log?.season, paKey, pa.key]);
    const paLoading = Boolean(paSplit) && pa.key !== paKey;
    const splits = useMemo(() => log ? buildSplits(log.games, pitcher, log.halfStarts) : null, [log, pitcher]);
    // 지표는 한 번에 하나만 그린다.
    const metricOptions = metricsByKind[split === 'oppOrder' ? 'faced' : kind];
    const metrics = [metricOptions[metricSlot]];
    const hasData = splits?.groups.some(group => group.rows.length);
    // 통산은 어느 해든 있는 시즌 종류를 모두 고를 수 있다.
    const availableSeasons = { ...(log?.availableSeasons || {}), all: [...new Set(Object.values(log?.availableSeasons || {}).flat())] };
    const filter = <GameLogFilter years={log?.years || []} year={selection.year || log?.year} season={selection.season || log?.season || 'regular'} availableSeasons={availableSeasons} includeAll
        onYearChange={year => { const available = availableSeasons[year] || []; const current = selection.season || log?.season; setSelection({ year, season: available.includes(current) ? current : available[0] || '' }); }}
        onSeasonChange={season => setSelection({ year: String(selection.year || log?.year || ''), season })} />;

    const group = paSplit ? (pa.key === paKey ? buildPaRows(pa.rows, paSplit.label) : null) : splits?.groups.find(item => item.id === split);
    return <section className="profile-split-section" aria-busy={loading} hidden={!active}>
        <div className="profile-heading profile-candle-heading"><h2>시즌 스플릿 차트</h2>{filter}</div>
        {loading ? <ProfileLoading>기록을 불러오는 중이에요.</ProfileLoading>
            : error ? <div className="profile-candle-status" role="alert"><p>{error}</p><button type="button" onClick={() => setRetry(value => value + 1)}>다시 시도</button></div>
            : !hasData ? <p className="profile-games-empty">차트는 2001년 이후 기록부터 제공되어요.</p>
            : <>
                <div className="profile-split-toolbar">
                    <div className="profile-split-tabs" role="group" aria-label="스플릿 구분">{splitTabs[kind].map(([id, label]) => <button key={id} type="button" aria-pressed={split === id} onClick={() => setSplit(id)}>{label}</button>)}</div>
                    <div className="profile-split-tabs is-metric" role="group" aria-label="지표">{metricOptions.map((metric, slot) => <button key={metric.key} type="button" aria-pressed={metricSlot === slot} onClick={() => setMetricSlot(slot)}>{metric.label}</button>)}</div>
                </div>
                {paLoading ? <ProfileLoading>기록을 불러오는 중이에요.</ProfileLoading>
                    : paSplit && pa.error ? <p className="profile-games-empty">기록을 불러오지 못했습니다.</p>
                    : group?.rows.length ? <SplitRows rows={group.rows} metrics={metrics} total={group.total} baseLabel={log?.year === 'all' ? '통산' : '시즌'} animationKey={`${split}-${metricSlot}-${log?.year}-${log?.season}`}
                        logoOf={split === 'opponent' && getTeamLogo ? row => getTeamLogo(row.label, log?.year === 'all' ? undefined : log?.year) : undefined} onSelect={row => setPicked({ row, split })} />
                    : <p className="profile-games-empty">표시할 기록이 없습니다.</p>}
            </>}
        {picked && <SplitModal key={`${picked.split}-${picked.row.key}`} row={picked.row} split={picked.split} pitcher={pitcher} year={log?.year} season={log?.season} fipConstants={log?.fipConstants} onClose={() => setPicked(null)} />}
    </section>;
}
