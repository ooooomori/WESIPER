export const METRICS = [
    ["avg", "타율"], ["obp", "출루율"], ["slg", "장타율"],
    ["ops", "OPS"], ["eff_ops", "실질OPS"], ["ops_plus", "OPS+"],
];

export const COMPARISON_METRICS = [
    ["avg", "타율"], ["obp", "출루율"], ["slg", "장타율"],
    ["ops", "OPS"], ["eff_ops", "실질OPS"], ["ops_plus", "OPS+"],
    ["eff_ops_plus", "실질OPS+"],
];

export function metricValue(row, metric, key = "close") {
    const source = row?.[metric];
    const value = typeof source === "object" && source !== null ? source[key] : source;
    return value === null || value === undefined || value === "" || !Number.isFinite(Number(value))
        ? null : Number(value);
}

function period(date, timeframe) {
    if (timeframe === "monthly") return `${date.slice(0, 7)}-01`;
    if (timeframe !== "weekly") return date;
    const day = new Date(`${date}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - (day.getUTCDay() + 6) % 7);
    return day.toISOString().slice(0, 10);
}

// Moving averages retain the existing 7/30 recorded-game definition, including
// history before the visible window. Weekly/monthly bars use the last daily MA.
export function buildBars(data, metric, timeframe) {
    const sorted = [...new Map((Array.isArray(data) ? data : [])
        .filter(row => /^\d{4}-\d{2}-\d{2}$/.test(row.date))
        .map(row => [row.date, row])).values()].sort((a, b) => a.date.localeCompare(b.date));
    const grouped = new Map();
    sorted.forEach((row, index) => {
        const time = period(row.date, timeframe);
        const ohlc = Object.fromEntries(["open", "high", "low", "close"].map(key => [key, metricValue(row, metric, key)]));
        const average = count => {
            if (index < count - 1) return null;
            const values = sorted.slice(index - count + 1, index + 1).map(item => metricValue(item, metric));
            return values.some(value => value === null) ? null : values.reduce((a, b) => a + b, 0) / count;
        };
        const next = { time, ...ohlc, ops_plus: metricValue(row, "ops_plus"),
            eff_ops_plus: metricValue(row, "eff_ops_plus"), ma7: average(7), ma15: average(15), ma30: average(30),
            pa_results: Array.isArray(row.pa_results) ? row.pa_results : [] };
        const previous = grouped.get(time);
        if (previous) {
            next.open = previous.open ?? next.open;
            const highs = [previous.high, next.high].filter(value => value !== null);
            const lows = [previous.low, next.low].filter(value => value !== null);
            next.high = highs.length ? Math.max(...highs) : null;
            next.low = lows.length ? Math.min(...lows) : null;
            next.pa_results = [...previous.pa_results, ...next.pa_results];
        }
        grouped.set(time, next);
    });
    return [...grouped.values()];
}

export function withCalendarGaps(points, enabled, timeframe) {
    if (!enabled || timeframe !== "daily" || points.length < 2) return points;
    const byTime = new Map(points.map(point => [point.time, point]));
    const result = [];
    const end = new Date(`${points.at(-1).time}T00:00:00Z`);
    for (const date = new Date(`${points[0].time}T00:00:00Z`); date <= end; date.setUTCDate(date.getUTCDate() + 1)) {
        const time = date.toISOString().slice(0, 10);
        result.push(byTime.get(time) || { time });
    }
    return result;
}
