export function predictionVisible(data) {
    return data?.success === true && String(data.year) === "2026" && data.season === "regular"
        && data.date_preset === "whole" && data.prediction_eligible === true;
}

export function predictionRows(prediction) {
    if (prediction?.status !== "ready") return [];
    const entries = ["avg", "obp", "slg", "ops"].map(metric => [metric, prediction.probabilities?.[metric]]);
    if (entries.some(([, p]) => !p || [p.up, p.down, p.flat].some(x => !Number.isFinite(x) || x < 0 || x > 1)
        || Math.abs(p.up + p.down + p.flat - 1) > 1e-6)) return [];
    const metricLabels = { avg: "타율", obp: "출루율", slg: "장타율", ops: "OPS" };
    return entries.map(([metric, p]) => {
        const parts = [p.up, p.flat, p.down].map(value => value * 1000);
        const tenths = parts.map(Math.floor);
        const remainder = 1000 - tenths.reduce((sum, value) => sum + value, 0);
        const order = [0, 1, 2].sort((a, b) => (parts[b] - tenths[b]) - (parts[a] - tenths[a]));
        for (let i = 0; i < remainder; i++) tenths[order[i % order.length]]++;
        return { metric: metricLabels[metric], up: (tenths[0] / 10).toFixed(1),
            flat: (tenths[1] / 10).toFixed(1), down: (tenths[2] / 10).toFixed(1) };
    });
}

export function predictionEvents(prediction) {
    if (prediction?.status !== "ready") return null;
    const { hit, home_run: homeRun } = prediction.probabilities || {};
    if (![hit, homeRun].every(value => Number.isFinite(value) && value >= 0 && value <= 1)
        || homeRun > hit + 1e-9) return null;
    return { hit: (Math.round(hit * 1000) / 10).toFixed(1),
        homeRun: (Math.round(homeRun * 1000) / 10).toFixed(1) };
}
