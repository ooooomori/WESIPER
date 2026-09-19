import React, { useState } from "react";

const VIEWS = { period: "기간별", month: "월별", inning: "이닝별" };
const COLUMNS = [
    ["avg", "타율", "rate"], ["obp", "출루율", "rate"], ["slg", "장타율", "rate"],
    ["at_bats", "타수", "count"], ["hits", "안타", "count"],
    ["home_runs", "홈런", "count"], ["stolen_bases", "도루", "count"],
    ["walks", "볼넷", "count"], ["strikeouts", "삼진", "count"],
    ["ops", "OPS", "rate"], ["eff_ops", "실질OPS", "rate"], ["ops_plus", "OPS+", "plus"],
];
const COUNT_COLUMNS = [["games", "경기", "count"], ["plate_appearances", "타석", "count"]];

const displayValue = (value, type) => value === null || value === undefined || !Number.isFinite(Number(value))
    ? "—" : type === "count" ? Number(value).toLocaleString("ko-KR") : Number(value).toFixed(type === "plus" ? 1 : 3);

export default function CandleBreakdownTable({ year, breakdown }) {
    const [view, setView] = useState("period");
    const rows = breakdown?.[view] || [];
    const columns = [...(view === "month" ? COUNT_COLUMNS : COUNT_COLUMNS.slice(1)), ...COLUMNS];
    return <div className="candle-breakdown-section">
        <div className="candle-breakdown-heading">
            <strong>{year} {VIEWS[view]} 성적</strong>
            <span className="candle-breakdown-select">
                <select value={view} onChange={event => setView(event.target.value)} aria-label="성적 구분 선택">
                    {Object.entries(VIEWS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                </select>
            </span>
        </div>
        {breakdown == null ? <p className="candle-breakdown-empty">성적표 데이터를 불러올 수 없습니다.</p> : rows.length ? <div className="candle-breakdown-scroll" tabIndex={0} aria-label={`${VIEWS[view]} 성적 표, 좌우로 스크롤 가능`}>
            <table className="candle-breakdown-table">
                <thead><tr><th scope="col">구분</th>{columns.map(([key, label]) => <th scope="col" key={key}>{label}</th>)}</tr></thead>
                <tbody>{rows.map(row => <tr key={row.key}><th scope="row">{row.label}</th>{columns.map(([key, , type]) => <td key={key}>{displayValue(row.stats?.[key], type)}</td>)}</tr>)}</tbody>
            </table>
        </div> : <p className="candle-breakdown-empty">표시할 기록이 없습니다.</p>}
    </div>;
}
