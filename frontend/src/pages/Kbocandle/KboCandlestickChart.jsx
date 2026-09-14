import React, { useEffect, useMemo, useRef, useState } from "react";
import { createChart, createImageWatermark, CandlestickSeries, LineSeries, ColorType, CrosshairMode } from "lightweight-charts";
import PlayerImg from "./PlayerImg";
import MetricHelp from "./MetricHelp";
import { METRICS, buildBars, metricValue, withCalendarGaps } from "./chartData";
import { downloadChartCardPng, downloadCsv, exportFileStem } from "./chartExport";
import "./candle.css";

const UP = "#ef4452", DOWN = "#3485f6";
const defaultTeamLogoFiles = import.meta.glob("../../assets/images/logos/*-logo.svg", { eager: true, query: "?url", import: "default" });
const smallTeamLogoFiles = import.meta.glob("../../assets/images/s-logos/*-small-logo.svg", { eager: true, query: "?url", import: "default" });
const teamLogoNames = { OB: "doo", HH: "han", LG: "lg", HT: "kia", SS: "sam", LT: "lot", SK: "ssg", NC: "nc", KT: "kt", WO: "kiw", NX: "kiw" };
const teamColors = { doo: "#2c2e44", han: "#ff703a", lg: "#e03461", kia: "#ea0029", sam: "#3572bc", lot: "#343d71", ssg: "#f94d4d", nc: "#274c82", kt: "#555555", kiw: "#ad2d5e" };
const timeKey = time => typeof time === "string" ? time : time && typeof time === "object"
    ? `${time.year}-${String(time.month).padStart(2, "0")}-${String(time.day).padStart(2, "0")}` : "";
const displayPaResult = result => result.replace(/고4/g, "고의사구").replace(/희번/g, "희생번트");
const derivePeriodStats = data => {
    const rows = Array.isArray(data) ? data : [];
    const latest = rows.at(-1);
    const plateAppearances = rows.flatMap(row => Array.isArray(row.pa_results) ? row.pa_results : []);
    const walks = plateAppearances.filter(result => /^(볼넷|고4)/.test(result)).length;
    const strikeouts = plateAppearances.filter(result => /삼진/.test(result)).length;
    const stolenBases = plateAppearances.reduce((total, result) => {
        const matches = [...result.matchAll(/(\d*)도루(?!자)/g)];
        return total + matches.reduce((sum, match) => sum + (match[1] ? Number(match[1]) : 1), 0);
    }, 0);
    return {
        avg: metricValue(latest, "avg"), obp: metricValue(latest, "obp"),
        slg: metricValue(latest, "slg"), ops: metricValue(latest, "ops"),
        hits: plateAppearances.filter(result => /^(1루타|2루타|3루타|홈런)/.test(result)).length,
        home_runs: plateAppearances.filter(result => /^홈런/.test(result)).length,
        stolen_bases: stolenBases,
        bb_per_k: strikeouts ? walks / strikeouts : null,
    };
};

export default function KboCandlestickChart({ kboData, dark, setDark }) {
    const [metric, setMetric] = useState("ops");
    const [timeframe, setTimeframe] = useState("daily");
    const [showMA, setShowMA] = useState(true);
    const [calendarGaps, setCalendarGaps] = useState(false);
    const [selected, setSelected] = useState(null);
    const [rangeInfo, setRangeInfo] = useState(null);
    const [extremaLabels, setExtremaLabels] = useState([]);
    const host = useRef(null);
    const api = useRef(null);
    const plus = metric === "ops_plus";
    const metricSource = plus ? "ops" : metric;
    const bars = useMemo(() => buildBars(kboData?.success ? kboData.data : [], metricSource, timeframe), [kboData, metricSource, timeframe]);
    const latest = bars.at(-1);
    const active = selected || latest;
    const format = value => Number.isFinite(value) ? value.toFixed(plus ? 1 : 3) : "—";
    const metricName = METRICS.find(([id]) => id === metric)?.[1];
    const seasonLabels = { regular: "정규시즌", preseason: "시범경기", postseason: "포스트시즌" };
    const summaryTitle = kboData?.date_preset === "custom"
        ? "조회 기간 성적"
        : /^\d+$/.test(kboData?.date_preset || "")
            ? `최근 ${kboData.date_preset}경기 성적`
            : `${seasonLabels[kboData?.season] || "시즌"} 성적`;
    const formatDate = value => value ? value.replace(/-/g, ". ").replace(/\.\s(\d{2})$/, ". $1") : "";
    const periodLabel = kboData?.date_preset && kboData.date_preset !== "whole"
        ? `${formatDate(kboData.start_date)} ~ ${formatDate(kboData.end_date)}`
        : `${kboData?.year || ""} ${seasonLabels[kboData?.season] || ""}`;
    const periodStats = useMemo(() => kboData?.success
        ? (kboData.period_stats || derivePeriodStats(kboData.data)) : null, [kboData]);
    const summaryStats = [
        ["타율", periodStats?.avg, "rate"], ["출루율", periodStats?.obp, "rate"],
        ["장타율", periodStats?.slg, "rate"], ["OPS", periodStats?.ops, "rate"],
        ["안타", periodStats?.hits, "count"], ["홈런", periodStats?.home_runs, "count"],
        ["도루", periodStats?.stolen_bases, "count"], ["BB/K", periodStats?.bb_per_k, "rate"],
    ];
    const formatSummary = (value, type) => value === null || value === undefined || !Number.isFinite(Number(value))
        ? "—" : type === "count" ? Number(value).toLocaleString("ko-KR") : Number(value).toFixed(3);
    const current = latest?.[plus ? metric : "close"];
    const previous = bars.at(-2)?.[plus ? metric : "close"];
    const change = Number.isFinite(current) && Number.isFinite(previous) ? current - previous : null;
    const direction = change > 0 ? "up" : change < 0 ? "down" : "neutral";
    const isWholeSeason = !kboData?.date_preset || kboData.date_preset === "whole";
    const seasonRanking = kboData?.rankings?.[isWholeSeason ? "season" : "period"];
    const seasonRank = seasonRanking?.ranks?.[metric];
    const seasonTopThree = seasonRanking?.qualified && seasonRank >= 1 && seasonRank <= 3;
    const rankMedal = rank => ["🥇", "🥈", "🥉"][rank - 1];
    const rankBadgeClass = rank => `candle-rank-top-five ${rank <= 3 ? `candle-rank-medal-${rank}` : "candle-rank-finalist"}`;
    const teamCode = kboData?.rankings?.period?.team_code || kboData?.rankings?.season?.team_code;
    const playerPosition = kboData?.player?.Pos || "";
    const isMobileDevice = typeof window !== "undefined" && (window.matchMedia?.("(max-width: 767px)").matches || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent));
    const kboPlayerUrl = isMobileDevice
        ? `https://m.koreabaseball.com/Kbo/Player/${playerPosition.includes("투") ? "Pitcher" : "Hitter"}.aspx?playerId=${kboData?.player_id || ""}`
        : `https://www.koreabaseball.com/Record/Player/${playerPosition.includes("투") ? "PitcherDetail" : "HitterDetail"}/Total.aspx?playerId=${kboData?.player_id || ""}`;
    const teamLogoName = teamLogoNames[teamCode];
    const teamLogo = teamLogoName ? defaultTeamLogoFiles[`../../assets/images/logos/${teamLogoName}-logo.svg`] : null;
    const chartTeamLogo = teamLogoName ? smallTeamLogoFiles[`../../assets/images/s-logos/${teamLogoName}-small-logo.svg`] : null;
    const seasonRankText = seasonRanking?.qualified && seasonRank ? `${isWholeSeason ? "시즌" : "조회 기간"} ${seasonRank}위` : "";
    const summaryRankKeys = { "타율": "avg", "출루율": "obp", "장타율": "slg", "OPS": "ops", "안타": "hits", "홈런": "home_runs", "도루": "stolen_bases", "BB/K": "bb_per_k" };

    useEffect(() => {
        if (!host.current || !bars.length) { setRangeInfo(null); setSelected(null); setExtremaLabels([]); return; }
        const chart = createChart(host.current, {
            autoSize: true,
            layout: { background: { type: ColorType.Solid, color: dark ? "#101722" : "#f8fbff" }, textColor: dark ? "#8593a8" : "#44546a", fontFamily: "NanumSquareNeo, Arial, sans-serif", fontSize: 11, attributionLogo: true },
            grid: { vertLines: { color: dark ? "#1b2533" : "#dce4ee" }, horzLines: { color: dark ? "#1f2a38" : "#d7e0eb" } },
            rightPriceScale: { autoScale: true, borderColor: dark ? "#283344" : "#c7d2e0", scaleMargins: { top: 0.13, bottom: 0.12 } },
            timeScale: { borderColor: dark ? "#283344" : "#c7d2e0", rightOffset: 3, barSpacing: 10, minBarSpacing: 3, fixLeftEdge: true, fixRightEdge: true },
            crosshair: { mode: CrosshairMode.Magnet, vertLine: { color: "#64748b", labelBackgroundColor: "#35455e" }, horzLine: { color: "#64748b", labelBackgroundColor: "#35455e" } },
            handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
            handleScale: { mouseWheel: true, pinch: true, axisPressedMouseMove: { time: true, price: false }, axisDoubleClickReset: true },
            kineticScroll: { touch: true, mouse: true },
            localization: { locale: "ko-KR", dateFormat: "yyyy-MM-dd" },
        });
        const priceFormat = { type: "price", precision: plus ? 1 : 3, minMove: plus ? 0.1 : 0.001 };
        const calendar = points => withCalendarGaps(points, calendarGaps, timeframe);
        let main;
        if (plus) {
            // Add the selected metric last so it remains visually prominent.
            [metric === "ops_plus" ? "eff_ops_plus" : "ops_plus", metric].forEach(key => {
                const series = chart.addSeries(LineSeries, { color: key === metric ? "#0c9f82" : (dark ? "#7e8baf" : "#99a3b3"), lineWidth: key === metric ? 2 : 1, priceFormat, lastValueVisible: key === metric, priceLineVisible: key === metric });
                series.setData(calendar(bars.map(bar => Number.isFinite(bar[key]) ? { time: bar.time, value: bar[key] } : { time: bar.time })));
                if (key === metric) main = series;
            });
        } else {
            main = chart.addSeries(CandlestickSeries, { upColor: UP, downColor: DOWN, wickUpColor: UP, wickDownColor: DOWN, borderVisible: false, priceFormat });
            main.setData(calendar(bars.map(bar => [bar.open, bar.high, bar.low, bar.close].every(Number.isFinite)
                ? { time: bar.time, open: bar.open, high: Math.max(bar.high, bar.open, bar.close), low: Math.min(bar.low, bar.open, bar.close), close: bar.close } : { time: bar.time })));
        }
        const moving = plus ? [] : ["ma7", "ma30"].map((key, index) => {
            const series = chart.addSeries(LineSeries, { color: index ? "#b595ff" : "#f3bc5f", lineWidth: 1, priceFormat, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
            series.setData(calendar(bars.map(bar => Number.isFinite(bar[key]) ? { time: bar.time, value: bar[key] } : { time: bar.time })));
            return series;
        });
        if (chartTeamLogo) createImageWatermark(chart.panes()[0], chartTeamLogo, { maxWidth: 240, maxHeight: 200, padding: 32, alpha: dark ? 0.1 : 0.08 });
        const points = calendar(bars);
        const byTime = new Map(bars.map(bar => [bar.time, bar]));
        const onCrosshair = event => setSelected(byTime.get(timeKey(event.time)) || null);
        let lastRange = null;
        const onRange = range => {
            if (!range) return;
            lastRange = range;
            const visible = points.slice(Math.max(0, Math.ceil(range.from)), Math.min(points.length, Math.floor(range.to) + 1)).filter(bar => byTime.has(bar.time));
            const values = visible.flatMap(bar => plus ? [bar.ops_plus, bar.eff_ops_plus] : [bar.low, bar.high]).filter(Number.isFinite);
            const markerPoints = visible.map(bar => ({ bar, high: plus ? bar[metric] : bar.high, low: plus ? bar[metric] : bar.low }));
            const highPoint = markerPoints.filter(point => Number.isFinite(point.high)).reduce((best, point) => !best || point.high > best.high ? point : best, null);
            const lowPoint = markerPoints.filter(point => Number.isFinite(point.low)).reduce((best, point) => !best || point.low < best.low ? point : best, null);
            const width = chart.options().width;
            const makeLabel = (point, kind) => {
                if (!point) return null;
                const x = chart.timeScale().timeToCoordinate(point.bar.time);
                const y = main.priceToCoordinate(point[kind]);
                if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
                return { kind, x: Math.max(2, Math.min(width - 2, x)), y,
                    side: x < 80 ? "edge-left" : x > width - 80 ? "edge-right" : "",
                    text: `${kind === "high" ? "최고" : "최저"} ${format(point[kind])} (${point.bar.time.slice(5).replace("-", "/")})` };
            };
            const labels = [makeLabel(highPoint, "high"), makeLabel(lowPoint, "low")].filter(Boolean);
            setExtremaLabels(labels);
            setRangeInfo({ start: visible[0]?.time, end: visible.at(-1)?.time, count: visible.length,
                low: values.length ? Math.min(...values) : null, high: values.length ? Math.max(...values) : null });
            return labels;
        };
        const recent = () => {
            const count = host.current.clientWidth < 600 ? 36 : 60;
            chart.timeScale().setVisibleLogicalRange({ from: Math.max(-0.5, points.length - count), to: points.length + 2 });
            chart.priceScale("right").applyOptions({ autoScale: true });
        };
        const refreshExtrema = () => lastRange && onRange(lastRange);
        api.current = { chart, moving, recent, refreshExtrema, exportMarkers: () => onRange(chart.timeScale().getVisibleLogicalRange()) || [] };
        chart.subscribeCrosshairMove(onCrosshair);
        chart.timeScale().subscribeVisibleLogicalRangeChange(onRange);
        const resizeObserver = new ResizeObserver(() => lastRange && onRange(lastRange));
        resizeObserver.observe(host.current);
        setSelected(null);
        recent();
        return () => {
            resizeObserver.disconnect();
            chart.unsubscribeCrosshairMove(onCrosshair);
            chart.timeScale().unsubscribeVisibleLogicalRangeChange(onRange);
            api.current = null;
            chart.remove();
        };
    }, [bars, plus, metric, timeframe, calendarGaps, dark, chartTeamLogo]);

    useEffect(() => { api.current?.moving.forEach(series => series.applyOptions({ visible: showMA })); }, [showMA, bars, calendarGaps]);

    const zoom = factor => {
        const scale = api.current?.chart.timeScale();
        const range = scale?.getVisibleLogicalRange();
        if (!range) return;
        const width = Math.max(8, (range.to - range.from) * factor);
        scale.setVisibleLogicalRange({ from: range.to - width, to: range.to });
    };
    const move = direction => {
        const scale = api.current?.chart.timeScale();
        const range = scale?.getVisibleLogicalRange();
        if (!range) return;
        const step = (range.to - range.from) * 0.65 * direction;
        scale.setVisibleLogicalRange({ from: range.from + step, to: range.to + step });
    };
    const exportStem = exportFileStem("KBO_CANDLE", kboData?.name || kboData?.player_id, metricName, timeframe, periodLabel);
    const fullRangeValues = bars.flatMap(bar => plus ? [bar.ops_plus, bar.eff_ops_plus] : [bar.low, bar.high]).filter(Number.isFinite);
    const exportRangeText = bars.length ? {
        dates: `${bars[0].time} — ${bars.at(-1).time}`,
        summary: `전체 최저 ${format(Math.min(...fullRangeValues))} 최고 ${format(Math.max(...fullRangeValues))}`,
    } : null;
    const currentRangeText = rangeInfo?.start ? {
        dates: `${rangeInfo.start} — ${rangeInfo.end}`,
        summary: `현재 최저 ${format(rangeInfo.low)} 최고 ${format(rangeInfo.high)}`,
    } : exportRangeText;
    const exportCsv = () => {
        if (!bars.length) return;
        if (plus) {
            downloadCsv(`${exportStem}.csv`, ["날짜", "OPS+", "실질OPS+"], bars.map(bar => [bar.time, bar.ops_plus, bar.eff_ops_plus]));
            return;
        }
        const movingAverageHeaders = showMA ? ["MA 7", "MA 30"] : [];
        downloadCsv(`${exportStem}.csv`, ["날짜", `${metricName} 시작`, `${metricName} 최고`, `${metricName} 최저`, `${metricName} 마감`, ...movingAverageHeaders],
            bars.map(bar => [bar.time, bar.open, bar.high, bar.low, bar.close, ...(showMA ? [bar.ma7, bar.ma30] : [])]));
    };

    return <section className={`candle-terminal font-family-NaSqNe ${dark ? "theme-dark" : "theme-light"}`} aria-label="KBO 선수 기록 차트">
        <div className="candle-topline"><span><i /> <span className="font-family-kbo">KBO CANDLE</span> <b>선수 기록 차트</b></span><span>{periodLabel || "SEASON"}</span></div>
        <header className={`candle-quote ${teamLogo ? "candle-quote-team" : ""}`} style={teamLogo ? { "--candle-team-logo": `url("${teamLogo}")`, "--candle-team-tint": `${teamColors[teamLogoName]}${dark ? "3d" : "1f"}` } : undefined}>
            <div className="candle-player">
                {latest && <div className="candle-avatar"><PlayerImg p_no={kboData.player_id} p_img={kboData.img || ""} /></div>}
                <div><div className="candle-eyebrow">{latest && <><span><a className="candle-player-id-link" href={kboPlayerUrl} target="_blank" rel="noreferrer">{`#${kboData.player_id}`}</a>{` · ${metricName}`}</span><MetricHelp metric={metric} /></>}</div><h2>{kboData?.name || "선수를 선택해주세요"}</h2></div>
            </div>
            <div className={`candle-price ${direction}`}>
                <div className="candle-price-main">{seasonRankText && <small className={`candle-price-rank ${seasonRank <= 5 ? rankBadgeClass(seasonRank) : ""}`}>{seasonTopThree && rankMedal(seasonRank)}{seasonRankText}</small>}<strong>{format(current)}</strong></div>
                {change !== null && <span className="candle-price-change">{`${change > 0 ? "▲" : change < 0 ? "▼" : "−"} ${format(Math.abs(change))}${previous ? ` (${change > 0 ? "+" : ""}${(change / Math.abs(previous) * 100).toFixed(2)}%)` : ""}`}</span>}
            </div>
        </header>
        <nav className="candle-metrics" aria-label="기록 지표">{METRICS.map(([id, name]) => <button key={id} aria-pressed={metric === id} className={metric === id ? "active" : ""} onClick={() => setMetric(id)}>{name}</button>)}</nav>
        <div className="candle-toolbar">
            <div className="candle-periods">{[["daily", "일"], ["weekly", "주"], ["monthly", "월"]].map(([id, label]) => <button key={id} aria-pressed={timeframe === id} className={timeframe === id ? "active" : ""} onClick={() => setTimeframe(id)}>{label}</button>)}</div>
            <div className="candle-options">{!plus && <button aria-pressed={showMA} className={showMA ? "enabled" : ""} onClick={() => setShowMA(value => !value)}>이동평균</button>}<button disabled={timeframe !== "daily"} aria-pressed={calendarGaps} onClick={() => setCalendarGaps(value => !value)}>{calendarGaps ? "빈 날짜 표시" : "경기일만"}</button></div>
        </div>
        <div className="candle-legend">{plus ? <><span className="mint">● {metricName}</span><span>● {metric === "ops_plus" ? "실질OPS+" : "OPS+"}</span></> : <><span>캔들 · {metricName}</span>{showMA && <><span className="gold">― MA 7</span><span className="purple">― MA 30</span></>}</>}<span className="candle-visible">{rangeInfo?.count || 0}개 표시</span></div>
        <div className="candle-plot-wrap"><div className="candle-plot" ref={host} role="img" aria-label={`${metricName} 차트. 좌우로 이동하거나 확대해 기록을 탐색하세요.`} />{extremaLabels.map(label => <div key={label.kind} className={`candle-extrema-label ${label.kind} ${label.side || ""}`} style={{ left: label.x, top: label.y }} aria-hidden="true">{label.kind === "low" && <span className="candle-extrema-arrow">↑</span>}<span className="candle-extrema-text">{label.text}</span>{label.kind === "high" && <span className="candle-extrema-arrow">↓</span>}</div>)}</div>
        {!latest && <div className="candle-empty"><i className="bi bi-bar-chart-line-fill" aria-hidden="true" /><span>{kboData?.success === false
            ? <>선수 기록이 없습니다.<br />시즌이나 조회 기간을 조정해보세요.</>
            : <>선수를 선택하고 조회하기를 눌러보세요!</>}</span></div>}
        <div className="candle-navigation">
<div><button className="candle-nav-icon" disabled={!latest} onClick={() => move(-1)} aria-label="이전 구간"><i className="bi bi-chevron-left" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={!latest} onClick={() => move(1)} aria-label="다음 구간"><i className="bi bi-chevron-right" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={!latest} onClick={() => zoom(1.3)} aria-label="차트 축소"><i className="bi bi-dash-lg" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={!latest} onClick={() => zoom(0.75)} aria-label="차트 확대"><i className="bi bi-plus-lg" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={!latest} onClick={() => api.current?.recent()} aria-label="차트 초기화" title="초기화"><i className="bi bi-arrow-counterclockwise" aria-hidden="true" /></button></div>
            <div className="candle-navigation-actions"><details className={`candle-export-menu ${latest ? "" : "disabled"}`}><summary aria-label="차트 저장 메뉴" onClick={event => !latest && event.preventDefault()}><i className="bi bi-floppy-fill" aria-hidden="true" />저장</summary><div className="candle-export-options"><button disabled={!latest} onClick={event => { event.currentTarget.closest("details").open = false; exportCsv(); }}><i className="bi bi-filetype-csv" aria-hidden="true" />CSV</button><button disabled={!latest} onClick={event => { event.currentTarget.closest("details").open = false; downloadChartCardPng({ element: event.currentTarget.closest(".candle-terminal"), chart: api.current?.chart, filename: `${exportStem}_visible.png`, omitSelectors: [".candle-detail"], rangeText: currentRangeText, getMarkers: () => api.current?.exportMarkers() || [], afterRestore: () => api.current?.refreshExtrema(), fitContent: false }); }}><i className="bi bi-filetype-png" aria-hidden="true" />PNG (현재 화면)</button><button disabled={!latest} onClick={event => { event.currentTarget.closest("details").open = false; downloadChartCardPng({ element: event.currentTarget.closest(".candle-terminal"), chart: api.current?.chart, filename: `${exportStem}_full.png`, omitSelectors: [".candle-detail"], rangeText: exportRangeText, getMarkers: () => api.current?.exportMarkers() || [], afterRestore: () => api.current?.refreshExtrema(), fitContent: true }); }}><i className="bi bi-filetype-png" aria-hidden="true" />PNG (전체 차트)</button></div></details></div>
        </div>
        <div className="candle-range"><span>{rangeInfo?.start ? `${rangeInfo.start} — ${rangeInfo.end}` : "조회된 기록 없음"}</span><span>구간 최저 <b className="down">{format(rangeInfo?.low)}</b> 최고 <b className="up">{format(rangeInfo?.high)}</b></span></div>
        {latest && <div className="candle-detail">
            <div className="candle-detail-heading"><strong>{active?.time || "경기 기록"}{timeframe === "weekly" ? " 주" : timeframe === "monthly" ? " 월" : ""}</strong><span>{metricName}</span></div>
            <div className="candle-values">{(plus ? [["OPS+", active?.ops_plus], ["실질OPS+", active?.eff_ops_plus]] : [["시작", active?.open], ["최고", active?.high], ["최저", active?.low], ["마지막", active?.close]]).map(([label, value]) => <div key={label}><span>{label}</span><strong className={label === "최고" ? "up" : label === "최저" ? "down" : ""}>{format(value)}</strong></div>)}</div>
            {timeframe === "daily" && <div className="candle-atbats"><span>타석 결과</span><div>{active?.pa_results?.length ? active.pa_results.map((result, index) => <span className={/^(볼넷|고4|사구|1루타|2루타|3루타|홈런)/.test(result) ? "on-base" : ""} key={index}>{displayPaResult(result)}</span>) : <span>기록 없음</span>}</div></div>}
        </div>}
        {latest && <div className="candle-period-summary">
            <div className="candle-summary-heading"><strong>{summaryTitle}</strong><span>{periodLabel || "기간 미지정"}</span></div>
            <div className="candle-summary-values">{summaryStats.map(([label, value, type]) => {
                const rank = kboData?.rankings?.period?.ranks?.[summaryRankKeys[label]];
                return <div key={label}><span className="candle-summary-stat-label">{label}</span><strong>{formatSummary(value, type)}{rank && rank <= 20 ? <small className={`candle-stat-rank ${rank <= 5 ? rankBadgeClass(rank) : ""}`}>{rank <= 3 && rankMedal(rank)}{rank}위</small> : null}</strong></div>;
            })}</div>
        </div>}
        <footer className="candle-footnote"><div className="candle-footnote-copy">{plus && <span>OPS+ 계열은 리그 평균 대비 지표이며 파크 팩터는 반영하지 않습니다.</span>}<span>기록별 순위는 상위 20위까지 노출됩니다.</span><span className="candle-update-note">2026년 경기 데이터는 다음날 오전 2시에 일괄 업데이트됩니다.</span><a href="https://www.tradingview.com/" target="_blank" rel="noreferrer">TradingView Lightweight Charts™ · Copyright (с) 2025 TradingView, Inc.</a></div><button className="theme-toggle" onClick={() => setDark(value => !value)} aria-label={`${dark ? "라이트" : "다크"} 테마로 변경`}>{dark ? "☼ 라이트" : "☾ 다크"}</button></footer>
    </section>;
}
