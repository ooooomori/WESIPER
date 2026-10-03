import { useEffect, useState } from 'react';
import KboCandlestickChart from '../Kbocandle/KboCandlestickChart';
import GameLogFilter from './GameLogFilter';
import { METRICS } from '../Kbocandle/chartData';
import MetricHelp from '../Kbocandle/MetricHelp';
import { loadYearRecords } from './yearRecordsCache';
import ProfileLoading from './ProfileLoading';
import { getCachedProfileData, loadProfileData } from './profileDataCache';
import './profile-candle.css';

const seasons = ['regular', 'preseason', 'postseason', 'futures'];

export default function ProfileCandleChart({ pid, player, active = true }) {
    if ((player.Pos || '').includes('투수')) {
        return <section className="profile-candle-section" hidden={!active}>
            <div className="profile-heading"><h2>시즌 차트</h2></div>
            <p className="profile-games-empty profile-pitcher-chart-message">투수 차트는 준비중이에요!</p>
        </section>;
    }
    return <BatterCandleChart pid={pid} player={player} active={active} />;
}

function BatterCandleChart({ pid, player, active }) {
    const [catalog, setCatalog] = useState(null);
    const [selection, setSelection] = useState(null);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [metricId, setMetricId] = useState("ops");
    const metric = selection?.season === "futures" && metricId === "ops_plus" ? "ops" : metricId;
    const metricName = METRICS.find(([id]) => id === metric)?.[1];

    useEffect(() => {
        let active = true;

        setLoading(true);
        setError('');
        Promise.all([
            loadYearRecords(pid),
            loadProfileData('/api/kbocandle/get_schedule.php'),
        ]).then(([records, schedule]) => {
            if (!active) return;
            const available = {};
            for (const season of seasons) {
                const rows = (season === 'regular' ? records : records.seasons?.[season])?.batter?.rows || [];
                for (const row of rows) {
                    if (season !== 'futures' && (!schedule[row.year]?.[season]?.[0] || !schedule[row.year]?.[season]?.[1])) continue;
                    if (season === 'preseason' && [2008, 2009, 2010, 2012].includes(Number(row.year))) continue;
                    (available[row.year] ||= []).push(season);
                }
            }
            const years = Object.keys(available).sort((a, b) => Number(b) - Number(a));
            setCatalog({ years, available, schedule });
            setSelection(previous => previous && available[previous.year]?.includes(previous.season)
                ? previous : years.length ? { year: years[0], season: available[years[0]][0] } : null);
            if (!years.length) setLoading(false);
        }).catch(() => {
            if (active) { setError('차트의 시즌 정보를 불러오지 못했습니다.'); setLoading(false); }
        });
        return () => { active = false; };
    }, [pid, retry]);

    useEffect(() => {
        if (!selection || !catalog) return;

        const { year, season } = selection;
        const [start, end] = season === 'futures' ? [`${year}-01-01`, `${year}-12-31`] : catalog.schedule[year][season];
        const query = new URLSearchParams({ player_id: pid, year, season,
            start_date: start, end_date: end, date_preset: 'whole',
            img: player.Img || '', include_rankings: season === 'futures' ? '0' : '1', include_breakdown: '0' });
        let current = true;
        const url = `/api/kbocandle/get_data.php?${query}`;
        const applyResult = result => setData({ ...result, player, year, season, player_id: pid });
        const cached = getCachedProfileData(url);
        setError('');
        if (cached) { applyResult(cached); setLoading(false); return; }
        setLoading(true);
        setData(null);
        loadProfileData(url, result => !result.error && typeof result.success === 'boolean'
            && (!result.success || Array.isArray(result.data)))
            .then(result => { if (current) applyResult(result); })
            .catch(() => { if (current) setError('차트 기록을 불러오지 못했습니다.'); })
            .finally(() => { if (current) setLoading(false); });
        return () => { current = false; };
    }, [pid, catalog, selection, player, retry]);

    const heading = <div className="profile-heading profile-candle-heading"><h2>시즌 {metricName} 차트</h2>
        <GameLogFilter years={catalog?.years || []} year={selection?.year} season={selection?.season || 'regular'}
            availableSeasons={catalog?.available || {}}
            onYearChange={year => setSelection(previous => ({ year, season: catalog.available[year].includes(previous.season) ? previous.season : catalog.available[year][0] }))}
            onSeasonChange={season => setSelection(previous => ({ ...previous, season }))} />
        <MetricHelp metric={metric} />
    </div>;
    const hasChart = !loading && !error && data?.success && data.data.length > 0;
    return <section className="profile-candle-section" aria-busy={loading} hidden={!active}>
        {!hasChart && heading}
        {loading ? <ProfileLoading><span>차트를 만드는 중이에요!<br />조금 시간이 걸릴 수 있어요.</span></ProfileLoading>
            : error ? <div className="profile-candle-status" role="alert"><p>{error}</p><button type="button" onClick={() => setRetry(value => value + 1)}>다시 시도</button></div>
            : !data?.success || !data.data.length ? <div className="profile-candle-status"><p>이 시즌에는 차트로 표시할 타격 기록이 없습니다.</p><span>타석별 기록이 있는 시즌에서 캔들 차트를 볼 수 있습니다.</span></div>
            : <KboCandlestickChart kboData={data} dark={false} profile profileHeading={heading} selectedMetric={metric} onMetricChange={setMetricId} />}
    </section>;
}
