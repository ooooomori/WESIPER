import React from "react";
import { predictionVisible, predictionRows, predictionEvents } from "./predictionData";

const messages = {
    pending: "예측 데이터를 준비하고 있습니다.",
    unavailable: "예측 데이터를 불러올 수 없습니다.",
    updating: "최신 경기 기록을 반영하고 있습니다.",
    stale: "예측 업데이트가 지연되고 있습니다.",
    insufficient_data: "예측에 필요한 기록이 부족합니다. (과거 5경기·20타석 이상)",
};

export default function PredictionCard({ data }) {
    if (!predictionVisible(data)) return null;
    const prediction = data.prediction;
    const rows = predictionRows(prediction);
    const events = predictionEvents(prediction);
    return <section className="candle-prediction" aria-label="다음 경기 예측">
        <div className="candle-prediction-heading"><strong>다음 경기 예측</strong><span className="candle-prediction-help" tabIndex={0} aria-label="예측 안내" aria-describedby="candle-prediction-tooltip">?<span className="candle-prediction-tooltip" id="candle-prediction-tooltip" role="tooltip">타자의 과거 타석 데이터를 바탕으로 다음 경기 기록을 예측합니다. 정확도는 보장하지 않으니 재미로 즐겨주세요!</span></span><span className="candle-prediction-beta">BETA</span></div>
        {rows.length && events ? <>
            <div className="candle-prediction-body">
                <div className="candle-prediction-grid">{rows.map(row => <div className="candle-prediction-row" key={row.metric}>
                    <span className="candle-prediction-metric">{row.metric}</span><span className="up">↑ {row.up}%</span><span className="neutral">― {row.flat}%</span><span className="down">↓ {row.down}%</span>
                </div>)}</div>
                <div className="candle-prediction-events"><div className="candle-prediction-hit"><span className="candle-prediction-card-accent" aria-hidden="true" /><span>안타 칠 확률</span><span className="candle-prediction-event-value">{events.hit}%</span></div><div className="candle-prediction-home-run"><span className="candle-prediction-card-accent" aria-hidden="true" /><span>홈런 칠 확률</span><span className="candle-prediction-event-value">{events.homeRun}%</span></div><p className="candle-prediction-caption">{prediction.as_of_date} 경기까지 반영한 예측값입니다.</p></div>
            </div>
        </> : <p className="candle-prediction-status" role="status">{messages[prediction?.status] || messages.unavailable}</p>}
    </section>;
}
