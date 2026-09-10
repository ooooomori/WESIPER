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
    return (
        <div className="max-w-5xl mx-auto px-2 py-4 sm:px-4">
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
                    className={`candle-mode-switch ${comparisonMode ? "active" : ""}`}
                    aria-pressed={comparisonMode}
                    onClick={() => setComparisonMode((value) => !value)}
                >
                    <span>{comparisonMode ? "개별 선수 보기" : "선수 비교하기"}</span>
                    <small>{comparisonMode ? "한 선수의 캔들 차트로 돌아갑니다" : "여러 선수의 기록을 한 차트에서 확인합니다"}</small>
                </button>
            </div>
            <div className="text-xs sm:text-base py-4 sm:py-6 sr-hide-screenshot px-4 text-center font-family-NaSqNe">
                    <span>
                        2026년 경기 데이터는 다음날 오전 2시에 일괄 업데이트됩니다.
                    </span>
                </div>
        </div>
    );
}
