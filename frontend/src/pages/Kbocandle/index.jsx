import React from "react";
import { createRoot } from "react-dom/client";
import KboCandlestickChart from "./KboCandlestickChart.jsx";
import KboComparisonChart from "./KboComparisonChart.jsx";
import PlayerSearchUI from "./PlayerSearchUI.jsx";

// PHP 백엔드가 던져줄 실제 응답 포맷을 그대로 구현한 모의 데이터
/**
const mockKboData = {
    season_record: "kbo_season_records",
    player_id: "player_001",
    data: [
        {
            date: "2025-04-01",
            avg: { open: 0.0, high: 0.333, low: 0.0, close: 0.333 },
            obp: { open: 0.0, high: 0.5, low: 0.0, close: 0.5 },
            slg: { open: 0.0, high: 0.667, low: 0.0, close: 0.667 },
            ops: { open: 0.0, high: 1.167, low: 0.0, close: 1.167 },
            eff_ops: { open: 0.0, high: 1.567, low: 0.0, close: 1.567 },
        },
        {
            date: "2025-04-02",
            avg: { open: 0.333, high: 0.4, low: 0.25, close: 0.4 },
            obp: { open: 0.5, high: 0.571, low: 0.428, close: 0.571 },
            slg: { open: 0.667, high: 0.8, low: 0.5, close: 0.8 },
            ops: { open: 1.167, high: 1.371, low: 0.928, close: 1.371 },
            eff_ops: { open: 1.567, high: 1.871, low: 1.228, close: 1.871 },
        },
        {
            date: "2025-04-03",
            avg: { open: 0.4, high: 0.4, low: 0.3, close: 0.333 },
            obp: { open: 0.571, high: 0.571, low: 0.4, close: 0.444 },
            slg: { open: 0.8, high: 0.8, low: 0.5, close: 0.556 },
            ops: { open: 1.371, high: 1.371, low: 0.9, close: 1.0 },
            eff_ops: { open: 1.871, high: 1.871, low: 1.1, close: 1.3 },
        },
        {
            date: "2025-04-04",
            avg: { open: 0.333, high: 0.384, low: 0.333, close: 0.384 },
            obp: { open: 0.444, high: 0.533, low: 0.444, close: 0.533 },
            slg: { open: 0.556, high: 0.846, low: 0.556, close: 0.846 },
            ops: { open: 1.0, high: 1.379, low: 1.0, close: 1.379 },
            eff_ops: { open: 1.3, high: 1.805, low: 1.3, close: 1.805 },
        },
    ],
};
*/

export default function Kbocandle() {
    const [kboData, setKboData] = React.useState({});
    const [comparisonData, setComparisonData] = React.useState([]);
    const [comparisonMode, setComparisonMode] = React.useState(false);
    const [dark, setDark] = React.useState(true);
    const localPredictionPreview = import.meta.env.DEV;

    React.useEffect(() => {
        if (!localPredictionPreview) return;
        let active = true;
        fetch("/.prediction-preview/fixture.json")
            .then((response) => {
                if (!response.ok) throw new Error("Local prediction fixture unavailable");
                return response.json();
            })
            .then((fixture) => { if (active) setKboData(fixture); })
            .catch((error) => console.error("로컬 예측 미리보기를 불러오지 못했습니다:", error));
        return () => { active = false; };
    }, [localPredictionPreview]);
    return (
        <div className="max-w-5xl mx-auto px-2 py-4 sm:px-4">
            {localPredictionPreview && <p className="candle-local-preview-note">로컬 예측 미리보기 · 2026-09-16 기록 스냅샷 · 검색하면 해당 선수의 실제 API 응답으로 전환됩니다.</p>}
            <PlayerSearchUI
                setKboData={setKboData}
                comparisonMode={comparisonMode}
                setComparisonData={setComparisonData}
            />
            {comparisonMode
                ? <KboComparisonChart comparisonData={comparisonData} dark={dark} setDark={setDark} />
                : <KboCandlestickChart kboData={kboData} dark={dark} setDark={setDark} />}
            <div className="candle-mode-switch-wrap sr-hide-screenshot">
                <button
                    type="button"
                    className={`candle-mode-switch font-family-NaSqNe ${comparisonMode ? "active" : ""}`}
                    aria-pressed={comparisonMode}
                    onClick={() => setComparisonMode((value) => !value)}
                >
                    <span className="candle-mode-switch-label">
                        <i className={`bi ${comparisonMode ? "bi-person-fill" : "bi-people-fill"}`} aria-hidden="true" />
                        {comparisonMode ? "개별 선수 보기" : "선수 비교하기"}
                    </span>
                </button>
            </div>
        </div>
    );
}
