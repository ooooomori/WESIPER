import React, { useEffect, useMemo, useRef, useState } from "react";
import { Form } from "react-bootstrap";
import { ColorType, CrosshairMode, createChart, LineSeries } from "lightweight-charts";
import PlayerImg from "./PlayerImg";
import MetricHelp from "./MetricHelp";
import { COMPARISON_METRICS, metricValue } from "./chartData";
import { downloadChartCardPng, downloadCsv, exportFileStem } from "./chartExport";

const PLAYER_COLORS = ["#20c9a6", "#ff6577", "#4f8cff", "#f3bc5f", "#b595ff", "#ff8f4c", "#42c6dd", "#e66ac4"];
const SEASON_LABELS = { regular: "정규시즌", preseason: "시범경기", postseason: "포스트시즌" };

const countMatches = (results, pattern) => results.filter((result) => pattern.test(result)).length;
const countTagged = (results, pattern) => results.reduce((total, result) => {
    const matches = [...result.matchAll(pattern)];
    return total + matches.reduce((sum, match) => sum + (match[1] ? Number(match[1]) : 1), 0);
}, 0);

const deriveStats = (record) => {
    const rows = Array.isArray(record?.data) ? record.data : [];
    const latest = rows.at(-1);
    const appearances = rows.flatMap((row) => Array.isArray(row.pa_results) ? row.pa_results : []);
    return {
        games: rows.length,
        plate_appearances: appearances.length,
        hits: countMatches(appearances, /^(1루타|2루타|3루타|홈런)/),
        doubles: countMatches(appearances, /^2루타/),
        triples: countMatches(appearances, /^3루타/),
        home_runs: countMatches(appearances, /^홈런/),
        walks: countMatches(appearances, /^(볼넷|고4)/),
        stolen_bases: countTagged(appearances, /(\d*)도루(?!자)/g),
        caught_stealing: countTagged(appearances, /(\d*)도루자/g),
        avg: metricValue(latest, "avg"), obp: metricValue(latest, "obp"),
        slg: metricValue(latest, "slg"), ops: metricValue(latest, "ops"),
        eff_ops: metricValue(latest, "eff_ops"), ops_plus: metricValue(latest, "ops_plus"),
        eff_ops_plus: metricValue(latest, "eff_ops_plus"),
    };
};

const TABLE_ROWS = [
    ["games", "경기", "count"], ["plate_appearances", "타석", "count"],
    ["hits", "안타", "count"],
    ["doubles", "2루타", "count"], ["triples", "3루타", "count"],
    ["home_runs", "홈런", "count"], ["walks", "볼넷", "count"],
    ["stolen_bases", "도루", "count"], ["stolen_base_percentage", "도루 성공률", "percent"],
    ["avg", "타율", "rate"], ["obp", "출루율", "rate"],
    ["slg", "장타율", "rate"], ["ops", "OPS", "rate"],
    ["eff_ops", "실질OPS", "rate"], ["ops_plus", "OPS+", "plus"],
    ["eff_ops_plus", "실질OPS+", "plus"],
];

const formatValue = (value, type) => {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return "—";
    if (type === "count") return Number(value).toLocaleString("ko-KR");
    if (type === "percent") {
        const percent = Number(value) * 100;
        return `${percent === 100 ? "100" : percent.toFixed(1)}%`;
    }
    return Number(value).toFixed(type === "plus" ? 1 : 3);
};

export default function KboComparisonChart({ comparisonData, dark, setDark }) {
    const [metric, setMetric] = useState("ops");
    const [hoverValues, setHoverValues] = useState(null);
    const [visibleStats, setVisibleStats] = useState(() => TABLE_ROWS.map(([key]) => key));
    const [showRanks, setShowRanks] = useState(true);
    const [showBest, setShowBest] = useState(true);
    const [showWorst, setShowWorst] = useState(true);
    const settingsDialog = useRef(null);
    const host = useRef(null);
    const chartApi = useRef(null);
    const records = useMemo(() => (Array.isArray(comparisonData) ? comparisonData : []).filter((item) => item?.success && item.data?.length), [comparisonData]);
    const stats = useMemo(() => records.map((record) => {
        const merged = { ...deriveStats(record), ...(record.period_stats || {}) };
        const attempts = Number(merged.stolen_bases || 0) + Number(merged.caught_stealing || 0);
        return { ...merged, stolen_base_percentage: attempts ? Number(merged.stolen_bases || 0) / attempts : null };
    }), [records]);
    const isPlus = metric.endsWith("_plus");
    const metricName = COMPARISON_METRICS.find(([id]) => id === metric)?.[1] || "OPS";
    const first = records[0];
    const periodLabel = first ? (first.date_preset && first.date_preset !== "whole"
        ? `${first.start_date} ~ ${first.end_date}`
        : `${first.year} ${SEASON_LABELS[first.season] || ""}`) : "";
    const comparisonTitle = first?.date_preset === "custom"
        ? "조회 기간 성적 비교"
        : first?.date_preset && first.date_preset !== "whole"
            ? `최근 ${first.date_preset}경기 성적 비교`
            : "시즌 성적 비교";
    const leaderLabel = useMemo(() => {
        const ranked = records.map((record) => ({
            name: record.name || record.player?.Name,
            value: metricValue(record.data.at(-1), metric),
        })).filter((item) => Number.isFinite(item.value));
        if (!ranked.length) return "비교할 선수를 선택해주세요";
        const best = Math.max(...ranked.map((item) => item.value));
        const leaders = ranked.filter((item) => item.value === best).map((item) => item.name);
        const value = formatValue(best, isPlus ? "plus" : "rate");
        return leaders.length > 1 ? `🏆 공동 ${leaders.join(" · ")} - ${value}` : `🏆 ${leaders[0]} - ${value}`;
    }, [records, metric, isPlus]);

    useEffect(() => {
        if (!host.current || records.length < 2) return undefined;
        const chart = createChart(host.current, {
            autoSize: true,
            layout: { background: { type: ColorType.Solid, color: dark ? "#101722" : "#f8fbff" }, textColor: dark ? "#8593a8" : "#44546a", fontFamily: "NanumSquareNeo, Arial, sans-serif", fontSize: 11, attributionLogo: true },
            grid: { vertLines: { color: dark ? "#1b2533" : "#dce4ee" }, horzLines: { color: dark ? "#1f2a38" : "#d7e0eb" } },
            rightPriceScale: { autoScale: true, borderColor: dark ? "#283344" : "#c7d2e0", scaleMargins: { top: 0.12, bottom: 0.12 } },
            timeScale: { borderColor: dark ? "#283344" : "#c7d2e0", rightOffset: 2, barSpacing: 9, minBarSpacing: 3, fixLeftEdge: true, fixRightEdge: true },
            crosshair: { mode: CrosshairMode.Magnet, vertLine: { color: "#64748b", labelBackgroundColor: "#35455e" }, horzLine: { visible: false } },
            handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
            handleScale: { mouseWheel: true, pinch: true, axisPressedMouseMove: { time: true, price: false } },
            kineticScroll: { touch: true, mouse: true },
            localization: { locale: "ko-KR", dateFormat: "yyyy-MM-dd" },
        });
        const precision = isPlus ? 1 : 3;
        const seriesByPlayer = new Map();
        records.forEach((record, index) => {
            const series = chart.addSeries(LineSeries, {
                color: PLAYER_COLORS[index % PLAYER_COLORS.length], lineWidth: 2,
                priceFormat: { type: "price", precision, minMove: isPlus ? 0.1 : 0.001 },
                priceLineVisible: false, lastValueVisible: true, crosshairMarkerRadius: 4,
            });
            series.setData(record.data.map((row) => ({ time: row.date, value: metricValue(row, metric) })).filter((point) => Number.isFinite(point.value)));
            seriesByPlayer.set(record.player_id, series);
        });
        const onCrosshair = (event) => {
            if (!event.time) return setHoverValues(null);
            const values = {};
            records.forEach((record) => {
                const point = event.seriesData.get(seriesByPlayer.get(record.player_id));
                values[record.player_id] = point?.value;
            });
            setHoverValues({ date: event.time, values });
        };
        chart.subscribeCrosshairMove(onCrosshair);
        const recent = () => {
            const count = host.current.clientWidth < 600 ? 32 : 60;
            const totalDates = new Set(records.flatMap((record) => record.data.map((row) => row.date))).size;
            chart.timeScale().setVisibleLogicalRange({ from: Math.max(-0.5, totalDates - count), to: totalDates + 1 });
        };
        chartApi.current = { chart, recent };
        recent();
        return () => {
            chart.unsubscribeCrosshairMove(onCrosshair);
            chartApi.current = null;
            chart.remove();
        };
    }, [records, metric, dark, isPlus]);

    const zoom = (factor) => {
        const scale = chartApi.current?.chart.timeScale();
        const range = scale?.getVisibleLogicalRange();
        if (!range) return;
        const width = Math.max(8, (range.to - range.from) * factor);
        scale.setVisibleLogicalRange({ from: range.to - width, to: range.to });
    };
    const move = (direction) => {
        const scale = chartApi.current?.chart.timeScale();
        const range = scale?.getVisibleLogicalRange();
        if (!range) return;
        const step = (range.to - range.from) * 0.65 * direction;
        scale.setVisibleLogicalRange({ from: range.from + step, to: range.to + step });
    };
    const exportStem = exportFileStem("KBO_CANDLE_선수비교", metricName, periodLabel);
    const exportCsv = () => {
        if (records.length < 2) return;
        const dates = [...new Set(records.flatMap(record => record.data.map(row => row.date)))].sort();
        const values = records.map(record => new Map(record.data.map(row => [row.date, metricValue(row, metric)])));
        downloadCsv(`${exportStem}.csv`, ["날짜", ...records.map(record => record.name || record.player?.Name || record.player_id)],
            dates.map(date => [date, ...values.map(byDate => byDate.get(date) ?? "")]));
    };
    const rankClass = (rowIndex, playerIndex) => {
        const [key, , , negative] = TABLE_ROWS[rowIndex];
        const values = stats.map((item) => item[key])
            .filter((value) => value !== null && value !== undefined && value !== "")
            .map(Number).filter(Number.isFinite);
        const rawCurrent = stats[playerIndex]?.[key];
        const current = rawCurrent === null || rawCurrent === undefined || rawCurrent === "" ? NaN : Number(rawCurrent);
        if (!Number.isFinite(current) || values.length < 2 || Math.max(...values) === Math.min(...values)) return "";
        const best = negative ? Math.min(...values) : Math.max(...values);
        const worst = negative ? Math.max(...values) : Math.min(...values);
        return current === best && showBest ? "compare-best" : current === worst && records.length > 2 && showWorst ? "compare-worst" : "";
    };

    return <section className={`candle-terminal candle-comparison font-family-NaSqNe ${dark ? "theme-dark" : "theme-light"}`} aria-label="KBO 선수 비교 차트">
        <div className="candle-topline"><span><i /> <span className="font-family-kbo">KBO CANDLE</span> <b>선수 비교 차트</b></span><span>{periodLabel}</span></div>
        <header className="compare-header">
            <div><h2>{leaderLabel}</h2></div>
            <div className="compare-metric-title"><strong>{metricName}</strong><MetricHelp metric={metric} /></div>
        </header>
        <nav className="candle-metrics compare-metrics" aria-label="비교 기록 지표">{COMPARISON_METRICS.map(([id, name]) => <button key={id} aria-pressed={metric === id} className={metric === id ? "active" : ""} onClick={() => setMetric(id)}>{name}</button>)}</nav>
        <div className="compare-legend">{records.map((record, index) => {
            const latest = metricValue(record.data.at(-1), metric);
            const value = hoverValues?.values?.[record.player_id] ?? latest;
            return <div key={record.player_id}><span className="compare-color" style={{ background: PLAYER_COLORS[index % PLAYER_COLORS.length] }} /><div className="compare-legend-avatar"><PlayerImg p_no={record.player_id} p_img={record.img || record.player?.Img || ""} /></div><span>{record.name || record.player?.Name}</span><strong>{formatValue(value, isPlus ? "plus" : "rate")}</strong></div>;
        })}<span className="compare-date">{hoverValues?.date || "최근 기록"}</span></div>
        <div className="compare-plot-wrap">
            <div className="candle-plot compare-plot" ref={host} role="img" aria-label={`${metricName} 선수 비교 실선 차트`} />
            {records.length < 2 && <div className="compare-empty"><i className="bi bi-bar-chart-line-fill" aria-hidden="true" /><span>선수를 2명 이상 선택하고 비교하기를 눌러보세요!</span></div>}
        </div>
        <div className="candle-navigation">
            <div><button className="candle-nav-icon" disabled={records.length < 2} onClick={() => move(-1)} aria-label="이전 구간"><i className="bi bi-chevron-left" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={records.length < 2} onClick={() => move(1)} aria-label="다음 구간"><i className="bi bi-chevron-right" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={records.length < 2} onClick={() => zoom(1.3)} aria-label="차트 축소"><i className="bi bi-dash-lg" aria-hidden="true" /></button><button className="candle-nav-icon" disabled={records.length < 2} onClick={() => zoom(0.75)} aria-label="차트 확대"><i className="bi bi-plus-lg" aria-hidden="true" /></button></div>
            <div className="candle-navigation-actions"><details className={`candle-export-menu ${records.length >= 2 ? "" : "disabled"}`}><summary aria-label="비교 차트 저장 메뉴" onClick={event => records.length < 2 && event.preventDefault()}><i className="bi bi-floppy-fill" aria-hidden="true" />저장</summary><div className="candle-export-options"><button disabled={records.length < 2} onClick={event => { event.currentTarget.closest("details").open = false; exportCsv(); }}><i className="bi bi-filetype-csv" aria-hidden="true" />CSV</button><button disabled={records.length < 2} onClick={event => { event.currentTarget.closest("details").open = false; downloadChartCardPng({ element: event.currentTarget.closest(".candle-terminal"), chart: chartApi.current?.chart, filename: `${exportStem}_visible.png`, fitContent: false }); }}><i className="bi bi-filetype-png" aria-hidden="true" />PNG (현재 화면)</button><button disabled={records.length < 2} onClick={event => { event.currentTarget.closest("details").open = false; downloadChartCardPng({ element: event.currentTarget.closest(".candle-terminal"), chart: chartApi.current?.chart, filename: `${exportStem}_full.png`, fitContent: true }); }}><i className="bi bi-filetype-png" aria-hidden="true" />PNG (전체 차트)</button></div></details><button disabled={records.length < 2} className="candle-latest" onClick={() => chartApi.current?.recent()}>최근으로 ↗</button></div>
        </div>
        {!!records.length && <div className="compare-table-section">
            <div className="candle-summary-heading"><div className="compare-table-title"><strong>{comparisonTitle}</strong><button className="compare-settings-icon" aria-label="표에 표시할 기록 설정" aria-haspopup="dialog" onClick={() => settingsDialog.current?.showModal()}><i className="bi bi-gear" aria-hidden="true" /></button></div><span>{periodLabel}</span></div>
            <dialog ref={settingsDialog} className="compare-record-settings" aria-labelledby="compare-settings-title" onClick={event => { if (event.target === event.currentTarget) event.currentTarget.close(); }}>
                <div className="compare-settings-heading"><h3 id="compare-settings-title">표시할 기록</h3><button className="compare-settings-icon" aria-label="기록 설정 닫기" onClick={() => settingsDialog.current?.close()}><i className="bi bi-x-lg" aria-hidden="true" /></button></div>
                <div className="compare-settings-options">{TABLE_ROWS.map(([key, label]) => <Form.Check key={key} id={`compare-setting-${key}`} type="checkbox" label={label} checked={visibleStats.includes(key)} disabled={visibleStats.length === 1 && visibleStats.includes(key)} onChange={event => setVisibleStats(previous => event.target.checked ? [...previous, key] : previous.filter(item => item !== key))} />)}</div>
                <div className="compare-settings-display">
                    <Form.Check id="compare-setting-ranks" type="checkbox" label="순위 표시" checked={showRanks} onChange={event => setShowRanks(event.target.checked)} />
                    <Form.Check id="compare-setting-best" type="checkbox" label="최고 기록 강조" checked={showBest} onChange={event => setShowBest(event.target.checked)} />
                    <Form.Check id="compare-setting-worst" type="checkbox" label="최저 기록 강조" checked={showWorst} onChange={event => setShowWorst(event.target.checked)} />
                </div>
                <button className="compare-settings-done" onClick={() => settingsDialog.current?.close()}>완료</button>
            </dialog>
            <div className="compare-table-wrap"><table className="compare-table">
                <thead><tr><th scope="col">기록</th>{records.map((record, index) => <th scope="col" key={record.player_id}><div className="compare-player-head"><div className="compare-player-avatar"><PlayerImg p_no={record.player_id} p_img={record.img || record.player?.Img || ""} /></div><span>{record.name || record.player?.Name}</span><i style={{ background: PLAYER_COLORS[index % PLAYER_COLORS.length] }} /></div></th>)}</tr></thead>
                <tbody>{TABLE_ROWS.map(([key, label, type], rowIndex) => visibleStats.includes(key) ? <tr key={key}><th scope="row">{label}</th>{records.map((record, playerIndex) => {
                    const rank = record.rankings?.period?.ranks?.[key];
                    return <td key={record.player_id}><span className="compare-cell-record"><span className={rankClass(rowIndex, playerIndex)}>{formatValue(stats[playerIndex]?.[key], type)}</span>{showRanks && rank && rank <= 5 ? <small className={`candle-stat-rank ${rank <= 3 ? `compare-rank-medal-${rank}` : ""}`}>{rank}위</small> : null}</span></td>;
                })}</tr> : null)}</tbody>
            </table></div>
        </div>}
        <footer className="candle-footnote"><div className="candle-footnote-copy"><span>OPS+ 계열은 리그 평균 대비 지표이며 파크 팩터는 반영하지 않습니다.</span><span className="candle-update-note">2026년 경기 데이터는 다음날 오전 2시에 일괄 업데이트됩니다.</span><a href="https://www.tradingview.com/" target="_blank" rel="noreferrer">TradingView Lightweight Charts™ · Copyright (с) 2025 TradingView, Inc.</a></div><button className="theme-toggle" onClick={() => setDark((value) => !value)} aria-label={`${dark ? "라이트" : "다크"} 테마로 변경`}>{dark ? "☼ 라이트" : "☾ 다크"}</button></footer>
    </section>;
}
