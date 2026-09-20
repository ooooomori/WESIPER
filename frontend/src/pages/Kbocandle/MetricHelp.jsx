import React from "react";

export default function MetricHelp({ metric }) {
    if (metric !== "eff_ops") return null;

    return <span className="candle-metric-help">
        <span
            className="candle-help-icon"
            aria-label="실질 OPS 계산 설명"
            aria-describedby="eff-ops-tooltip"
            role="img"
        >?</span>
        <span className="candle-help-tooltip" id="eff-ops-tooltip" role="tooltip">
            <strong>실질 OPS란?</strong>
            <span><b>실질 OPS = 실질 출루율 + 실질 장타율</b></span>
            <span>도루 성공은 추가로 진루한 베이스를 총루타에 반영하고, 도루 실패는 출루·안타 기여를 제거한 아웃으로 반영합니다.</span>
            <span>도루 능력이 타격 생산성에 미친 영향을 가정해 계산한 지표이며, 공식 기록이 아닌 재미용 지표입니다.</span>
        </span>
    </span>;
}
