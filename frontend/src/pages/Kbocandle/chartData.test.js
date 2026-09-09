import test from "node:test";
import assert from "node:assert/strict";
import { buildBars, metricValue, withCalendarGaps } from "./chartData.js";

const row = (date, open, high, low, close) => ({ date, ops: { open, high, low, close }, pa_results: ["1루타"] });
test("weekly aggregation keeps first open, extrema and last close across unsorted dates", () => {
    const bars = buildBars([row("2026-04-08", 2, 4, 1, 3), row("2026-04-06", 1, 2, 0.5, 2)], "ops", "weekly");
    assert.equal(bars.length, 1);
    assert.deepEqual([bars[0].time, bars[0].open, bars[0].high, bars[0].low, bars[0].close], ["2026-04-06", 1, 4, 0.5, 3]);
});
test("moving average includes history outside the recent viewport", () => {
    const data = Array.from({ length: 40 }, (_, i) => row(new Date(Date.UTC(2026, 3, i + 1)).toISOString().slice(0, 10), i, i + 1, i, i + 1));
    const bars = buildBars(data, "ops", "daily");
    assert.equal(bars[5].ma7, null);
    assert.equal(bars.at(-1).ma7, 37);
    assert.equal(bars.at(-1).ma30, 25.5);
});
test("calendar gaps are whitespace, and missing metrics do not become zero", () => {
    const points = [{ time: "2026-04-01", value: 1 }, { time: "2026-04-03", value: 2 }];
    assert.deepEqual(withCalendarGaps(points, true, "daily")[1], { time: "2026-04-02" });
    assert.equal(withCalendarGaps(points, false, "daily").length, 2);
    assert.equal(metricValue({}, "ops"), null);
    assert.equal(metricValue({ ops_plus: -25 }, "ops_plus"), -25);
    assert.deepEqual(buildBars([], "ops", "monthly"), []);
});
test("monthly bars cross year boundaries and duplicate days are replaced", () => {
    const bars = buildBars([row("2025-12-31", 1, 2, 1, 2), row("2026-01-01", 2, 3, 2, 3), row("2026-01-01", 2, 4, 2, 4)], "ops", "monthly");
    assert.deepEqual(bars.map(bar => [bar.time, bar.close]), [["2025-12-01", 2], ["2026-01-01", 4]]);
});
