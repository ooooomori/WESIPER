import React, { useState, useEffect } from "react";
import ReactApexChart from "react-apexcharts";
import PlayerImg from "./PlayerImg";

export default function KboCandlestickChart({ kboData }) {
    const [selectedMetric, setSelectedMetric] = useState("ops");
    const [timeframe, setTimeframe] = useState("daily");
    const [chartSeries, setChartSeries] = useState([]);

    // 💡 1. 전역 스코프로 분리하여 JSX와 useEffect 전체에서 안전하게 참조
    const isPlusMetric =
        selectedMetric === "ops_plus" || selectedMetric === "eff_ops_plus";

    const defaultYLimits = {
        avg: { min: 0.15, max: 0.4 },
        obp: { min: 0.25, max: 0.5 },
        slg: { min: 0.3, max: 0.7 },
        ops: { min: 0.5, max: 1.2 },
        eff_ops: { min: 0.5, max: 1.2 },
        ops_plus: { min: 50, max: 200 },
        eff_ops_plus: { min: 50, max: 200 },
    };

    const [yMin, setYMin] = useState(defaultYLimits["ops"].min);
    const [yMax, setYMax] = useState(defaultYLimits["ops"].max);
    const [hideEmptyDates, setHideEmptyDates] = useState(false);

    const extractValue = (item, metric, key = "close") => {
        if (!item || item[metric] === undefined) return 0;
        return typeof item[metric] === "number"
            ? item[metric]
            : Number(item[metric][key] || 0);
    };

    // 💡 2. Y축 자동 조절 엔진 (두 Plus 지표를 동시에 그릴 때 잘리지 않도록 양방향 탐색)
    useEffect(() => {
        if (
            kboData &&
            kboData.success &&
            Array.isArray(kboData.data) &&
            kboData.data.length > 0
        ) {
            if (isPlusMetric) {
                let highValues = [];
                let lowValues = [];
                kboData.data.forEach((item) => {
                    highValues.push(extractValue(item, "ops_plus", "close"));
                    highValues.push(extractValue(item, "eff_ops_plus", "close"));
                    lowValues.push(extractValue(item, "ops_plus", "close"));
                    lowValues.push(extractValue(item, "eff_ops_plus", "close"));
                });

                const dataMax = Math.max(...highValues);
                const dataMin = Math.min(...lowValues);
                const diff = dataMax - dataMin;
                const padding = diff === 0 ? 10 : diff * 0.1;

                setYMin(Math.floor((dataMin - padding) / 10) * 10);
                setYMax(Math.ceil((dataMax + padding) / 10) * 10);
            } else {
                // 전체 데이터를 정확히 반으로 갈라 후반부 배열 추출
                const midIndex = Math.floor(kboData.data.length / 2);
                const secondHalfData = kboData.data.slice(midIndex);

                // 후반부 구간의 순수 최고점/최저점 스캔
                const recentHighs = secondHalfData.map((item) =>
                    extractValue(item, selectedMetric, "high"),
                );
                const recentLows = secondHalfData.map((item) =>
                    extractValue(item, selectedMetric, "low"),
                );

                const recentMax = Math.max(...recentHighs);
                const recentMin = Math.min(...recentLows);
                const diff = recentMax - recentMin;
                
                // 편차 기반 상하단 여백 확보 (단일 값일 경우 0.05 고정)
                const padding = diff === 0 ? 0.05 : diff * 0.5;

                // 음수 방지 및 소수점 3자리 포맷팅 적용
                setYMin(Math.max(0, (Math.round((recentMin - padding) * 100) / 100)).toFixed(3));
                setYMax((Math.round((recentMax + padding)* 100) / 100).toFixed(3));
            }
        } else {
            setYMin(defaultYLimits[selectedMetric]?.min || 0);
            setYMax(defaultYLimits[selectedMetric]?.max || 1);
        }
    }, [kboData, selectedMetric, isPlusMetric]);

    // 💡 3. OHLC 병합 및 Plus 지표 듀얼 렌더링 세팅
    useEffect(() => {
        if (
            kboData &&
            kboData.success &&
            Array.isArray(kboData.data) &&
            kboData.data.length > 0
        ) {
            const dailyDataWithMA = kboData.data.map((item, index, arr) => {
                let ma7 = null,
                    ma30 = null;
                if (!isPlusMetric) {
                    if (index >= 6) {
                        let sum = 0;
                        for (let i = 0; i < 7; i++)
                            sum += extractValue(
                                arr[index - i],
                                selectedMetric,
                                "close",
                            );
                        ma7 = Number((sum / 7).toFixed(3));
                    }
                    if (index >= 29) {
                        let sum = 0;
                        for (let i = 0; i < 30; i++)
                            sum += extractValue(
                                arr[index - i],
                                selectedMetric,
                                "close",
                            );
                        ma30 = Number((sum / 30).toFixed(3));
                    }
                }
                return { ...item, ma7, ma30 };
            });

            const grouped = {};
            dailyDataWithMA.forEach((item) => {
                let groupKey = item.date;
                if (timeframe === "weekly")
                    groupKey = getStartOfWeek(item.date);
                else if (timeframe === "monthly")
                    groupKey = getStartOfMonth(item.date);

                if (!grouped[groupKey]) {
                    grouped[groupKey] = {
                        date: groupKey,
                        open: extractValue(item, selectedMetric, "open"),
                        high: extractValue(item, selectedMetric, "high"),
                        low: extractValue(item, selectedMetric, "low"),
                        close: extractValue(item, selectedMetric, "close"),
                        ops_plus: extractValue(item, "ops_plus", "close"),
                        eff_ops_plus: extractValue(
                            item,
                            "eff_ops_plus",
                            "close",
                        ),
                        ma7: item.ma7,
                        ma30: item.ma30,
                        pa_results: item.pa_results ? [...item.pa_results] : [],
                    };
                } else {
                    grouped[groupKey].high = Math.max(
                        grouped[groupKey].high,
                        extractValue(item, selectedMetric, "high"),
                    );
                    grouped[groupKey].low = Math.min(
                        grouped[groupKey].low,
                        extractValue(item, selectedMetric, "low"),
                    );
                    grouped[groupKey].close = extractValue(
                        item,
                        selectedMetric,
                        "close",
                    );
                    grouped[groupKey].ops_plus = extractValue(
                        item,
                        "ops_plus",
                        "close",
                    );
                    grouped[groupKey].eff_ops_plus = extractValue(
                        item,
                        "eff_ops_plus",
                        "close",
                    );
                    grouped[groupKey].ma7 = item.ma7;
                    grouped[groupKey].ma30 = item.ma30;
                }
            });

            const mappedCandle = [];
            const mappedMA7 = [];
            const mappedMA30 = [];
            const mappedOpsPlus = [];
            const mappedEffOpsPlus = [];

            Object.values(grouped).forEach((g) => {
                const xVal = hideEmptyDates
                    ? g.date
                    : new Date(
                          g.date.length === 7
                              ? `${g.date}-01T00:00:00`
                              : `${g.date}T00:00:00`,
                      ).getTime();

                if (!isPlusMetric) {
                    mappedCandle.push({
                        x: xVal,
                        y: [g.open, g.high, g.low, g.close],
                        pa_results: timeframe === "daily" ? g.pa_results : null,
                    });
                    mappedMA7.push({ x: xVal, y: g.ma7 });
                    mappedMA30.push({ x: xVal, y: g.ma30 });
                } else {
                    mappedOpsPlus.push({
                        x: xVal,
                        y: g.ops_plus,
                        pa_results: timeframe === "daily" ? g.pa_results : null,
                    });
                    mappedEffOpsPlus.push({
                        x: xVal,
                        y: g.eff_ops_plus,
                        pa_results: timeframe === "daily" ? g.pa_results : null,
                    });
                }
            });
            if (!isPlusMetric) {
                setChartSeries([
                    { name: "기본", type: "candlestick", data: mappedCandle },
                    { name: "7-이평선", type: "line", data: mappedMA7 },
                    { name: "30-이평선", type: "line", data: mappedMA30 },
                ]);
            } else {
                const isOpsPlusSelected = selectedMetric === "ops_plus";
                setChartSeries([
                    {
                        name: "OPS+",
                        type: "line",
                        data: mappedOpsPlus,
                        // 선택된 애한테 zIndex 2를 주어 무조건 앞으로 오게 함
                        zIndex: isOpsPlusSelected ? 2 : 1,
                    },
                    {
                        name: "실질OPS+",
                        type: "line",
                        data: mappedEffOpsPlus,
                        zIndex: !isOpsPlusSelected ? 2 : 1,
                    },
                ]);
            }
        } else {
            setChartSeries([]);
        }
    }, [kboData, selectedMetric, timeframe, hideEmptyDates, isPlusMetric]);

    // 💡 4. 최고점/최저점 마커 계산 로직
    let customAnnotations = { points: [] };
    if (chartSeries.length > 0 && chartSeries[0].data.length > 0) {
        let maxVal = -Infinity;
        let minVal = Infinity;
        let maxPoint = null;
        let minPoint = null;

        // Series[0] (메인 지표)를 기준으로 고점과 저점을 스캔
        chartSeries[+(selectedMetric === "eff_ops_plus")].data.forEach((d) => {
            if (!d || d.y == null) return;
            const high = Array.isArray(d.y) ? d.y[1] : d.y;
            const low = Array.isArray(d.y) ? d.y[2] : d.y;

            if (high > maxVal) {
                maxVal = high;
                // 실제 값을 realY에 보존
                maxPoint = { x: d.x, y: high, realY: high };
            }
            if (low < minVal) {
                minVal = low;
                minPoint = { x: d.x, y: low, realY: low };
            }
        });

        if (maxPoint && minPoint) {
            const totalPoints = chartSeries[0].data.length;
            const maxIndex = chartSeries[0].data.findIndex((d) => d.x === maxPoint.x);
            const minIndex = chartSeries[0].data.findIndex((d) => d.x === minPoint.x);

            const maxOffsetX = maxIndex > totalPoints * 0.8 ? -45 : 45;
            const minOffsetX = minIndex > totalPoints * 0.8 ? -45 : 45;

            // 💡 [핵심] Y축 범위를 벗어난 좌표를 화면 경계선에 강제 고정
            const numYMax = Number(yMax);
            const numYMin = Number(yMin);
            const isMaxClipped = maxPoint.y > numYMax;
            const isMinClipped = minPoint.y < numYMin;

            // 렌더링용 Y좌표 (잘렸다면 최대/최소값으로 대체)
            const renderMaxY = isMaxClipped ? numYMax : maxPoint.y;
            const renderMinY = isMinClipped ? numYMin : minPoint.y;
            customAnnotations.points = [
                {
                    x: maxPoint.x,
                    y: renderMaxY, // 강제 고정된 렌더링 좌표
                    marker: {
                        size: 4,
                        fillColor: "#fff",
                        strokeColor: "#ef4444",
                        strokeWidth: 2,
                        radius: 2,
                    },
                    label: {
                        // 텍스트는 보존해둔 실제 값(realY) 출력, 잘림 발생 시 화살표 추가
                        text: `${isMaxClipped ? '▲ ' : ''}최고 ${maxPoint.realY.toFixed(isPlusMetric ? 1 : 3)}`,
                        offsetY: isMaxClipped ? 12 : 0, // 천장에 붙었을 때 텍스트가 위로 잘리지 않도록 강제 하강
                        offsetX: maxOffsetX,
                        style: {
                            background: "#ef4444",
                            color: "#fff",
                            fontSize: "11px",
                            fontWeight: "bold",
                        },
                    },
                },
                {
                    x: minPoint.x,
                    y: renderMinY, 
                    marker: {
                        size: 4,
                        fillColor: "#fff",
                        strokeColor: "#3b82f6",
                        strokeWidth: 2,
                        radius: 2,
                    },
                    label: {
                        text: `${isMinClipped ? '▼ ' : ''}최저 ${minPoint.realY.toFixed(isPlusMetric ? 1 : 3)}`,
                        offsetY: isMinClipped ? -12 : 0, // 바닥에 붙었을 때 텍스트가 아래로 잘리지 않도록 강제 상승
                        offsetX: minOffsetX,
                        style: {
                            background: "#3b82f6",
                            color: "#fff",
                            fontSize: "11px",
                            fontWeight: "bold",
                        },
                    },
                },
            ];

            console.log(customAnnotations.points)
        }
    }

    const tabs = [
        { id: "avg", name: "타율" },
        { id: "obp", name: "출루율" },
        { id: "slg", name: "장타율" },
        { id: "ops", name: "OPS" },
        { id: "eff_ops", name: "실질OPS" },
        { id: "ops_plus", name: "OPS+" },
        { id: "eff_ops_plus", name: "실질OPS+" },
    ];

    const getStartOfWeek = (dateStr) => {
        const d = new Date(`${dateStr}T00:00:00`);
        const day = d.getDay();
        const diff = d.getDate() - day + (day === 0 ? -6 : 1);
        const monday = new Date(d.setDate(diff));
        return `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, "0")}-${String(monday.getDate()).padStart(2, "0")}`;
    };

    const getStartOfMonth = (dateStr) => dateStr.substring(0, 7);

    const hasData =
        kboData &&
        kboData.success &&
        Array.isArray(kboData.data) &&
        kboData.data.length > 0;
    const playerName = kboData?.name || "선수명";
    const playerId = kboData?.player_id || "";

    let currentPrice = 0;
    let priceChange = 0;
    let priceChangePercent = 0;
    let changeStatus = "neutral";
    let metricName = tabs.find((t) => t.id === selectedMetric)?.name || "";

    if (hasData) {
        const dataLen = kboData.data.length;
        const lastItem = kboData.data[dataLen - 1];

        currentPrice = extractValue(lastItem, selectedMetric, "close");

        if (dataLen > 1) {
            const prevPrice = extractValue(
                kboData.data[dataLen - 2],
                selectedMetric,
                "close",
            );
            priceChange = currentPrice - prevPrice;
            priceChangePercent =
                prevPrice !== 0 ? (priceChange / prevPrice) * 100 : 0;

            if (priceChange > 0) changeStatus = "up";
            else if (priceChange < 0) changeStatus = "down";
        }
    }

    const options = {
        chart: {
            type: "line",
            height: 500,
            fontFamily: "inherit",
            toolbar: {
                show: true,
                tools: {
                    download: true,
                    selection: true,
                    zoom: true,
                    zoomin: true,
                    zoomout: true,
                    pan: true,
                    reset: true,
                },
                autoSelected: "pan",
            },
            animations: {
                enabled: true,
                easing: "easeinout",
                speed: 800,
                dynamicAnimation: { enabled: true, speed: 350 },
            },
        },
        // 메인 캔들/라인은 짙은 색, 두 번째 라인(보조)은 에메랄드 색
        colors: isPlusMetric
            ? selectedMetric === "ops_plus"
                ? ["#111827", "#a0a0a0"]
                : ["#a0a0a0", "#111827"]
            : ["#111827", "#10b981", "#8b5cf6"],
        stroke: {
            width: isPlusMetric ? [2, 2, 2] : [1, 1, 1],
            curve: "smooth",
        },
        annotations: customAnnotations, // 최고/최저점 마커 주입
        xaxis: {
            type: hideEmptyDates ? "category" : "datetime",
            labels: {
                show: !hideEmptyDates,
                datetimeUTC: false,
                format: timeframe === "monthly" ? "yyyy/MM" : "MM/dd",
            },
            tooltip: { enabled: false },
        },
        yaxis: {
            min: yMin !== "" && !isNaN(yMin) ? Number(yMin) : undefined,
            max: yMax !== "" && !isNaN(yMax) ? Number(yMax) : undefined,
            tooltip: { enabled: false },
            labels: {
                formatter: (value) =>
                    typeof value === "number"
                        ? value.toFixed(isPlusMetric ? 0 : 3)
                        : value,
            },
        },
        plotOptions: {
            candlestick: {
                colors: { upward: "#ef4444", downward: "#3b82f6" },
                wick: { useFillColor: true },
            },
        },
        grid: { borderColor: "#f3f4f6", strokeDashArray: 4 },

        // 💡 5. 툴팁에 듀얼 Line 렌더링 지원 추가
        tooltip: {
            shared: true,
            intersect: false,
            custom: function ({ seriesIndex, dataPointIndex, w }) {
                const candleData0 =
                    w.globals.initialSeries[0]?.data?.[dataPointIndex];
                const candleData1 =
                    w.globals.initialSeries[1]?.data?.[dataPointIndex];
                if (!candleData0) return "";

                const d = new Date(candleData0.x);
                const y = d.getFullYear();
                const m = d.getMonth() + 1;
                const dayDate = d.getDate();

                let dateStr = "";
                if (timeframe === "monthly") {
                    dateStr = `${y}년 ${m}월`;
                } else if (timeframe === "weekly") {
                    const firstDay = new Date(y, m - 1, 1).getDay();
                    const weekNum = Math.ceil((dayDate + firstDay) / 7);
                    dateStr = `${y}년 ${m}월 ${weekNum}주`;
                } else {
                    dateStr = `${y}년 ${m}월 ${dayDate}일`;
                }

                let valueHtml = "";
                if (isPlusMetric) {
                    const val0 = candleData0.y.toFixed(1);
                    const name0 = w.globals.initialSeries[0].name;
                    const val1 = candleData1 ? candleData1.y.toFixed(1) : "";
                    const name1 = candleData1
                        ? w.globals.initialSeries[1].name
                        : "";

                    valueHtml = `
                        <div class="flex justify-between gap-5 items-center"><span class="text-gray-900 font-black">${name0}</span><span class="font-bold text-gray-900">${val0}</span></div>
                        ${candleData1 ? `<div class="flex justify-between gap-5 items-center"><span class="text-gray-500 font-bold">${name1}</span><span class="font-bold text-gray-600">${val1}</span></div>` : ""}
                    `;
                } else {
                    const yData = candleData0.y;
                    const o = yData[0].toFixed(3);
                    const h = yData[1].toFixed(3);
                    const l = yData[2].toFixed(3);
                    const c = yData[3].toFixed(3);

                    valueHtml = `
                        <div class="flex justify-between gap-5"><span class="text-gray-500 font-bold">시작</span><span class="font-bold text-gray-900">${o}</span></div>
                        <div class="flex justify-between gap-5"><span class="text-gray-500 font-bold">최고</span><span class="font-extrabold text-red-500">${h}</span></div>
                        <div class="flex justify-between gap-5"><span class="text-gray-500 font-bold">최저</span><span class="font-extrabold text-blue-500">${l}</span></div>
                        <div class="flex justify-between gap-5"><span class="text-gray-500 font-bold">마지막</span><span class="font-bold text-gray-900">${c}</span></div>
                    `;
                }

                const onBaseKeywords = [
                    "볼넷",
                    "고4",
                    "사구",
                    "1루타",
                    "2루타",
                    "3루타",
                    "홈런",
                ];
                const paHtml =
                    candleData0.pa_results && candleData0.pa_results.length > 0
                        ? `<div class="mt-3 pt-3 border-t border-gray-100 text-xs font-bold text-center leading-relaxed break-keep">
                            ${candleData0.pa_results
                                .map((pa) => {
                                    const splitIdx = pa.indexOf("(");
                                    const baseText =
                                        splitIdx !== -1
                                            ? pa.substring(0, splitIdx)
                                            : pa;
                                    const tailText =
                                        splitIdx !== -1
                                            ? pa.substring(splitIdx)
                                            : "";
                                    const isOnBase =
                                        onBaseKeywords.includes(baseText);

                                    const baseSpan = isOnBase
                                        ? `<span class="text-green-600">${baseText}</span>`
                                        : `<span class="text-gray-800">${baseText}</span>`;
                                    const tailSpan = tailText
                                        ? `<span class="text-gray-800">${tailText}</span>`
                                        : "";
                                    return baseSpan + tailSpan;
                                })
                                .join(" ")}
                           </div>`
                        : "";

                return `
                  <div class="p-4 bg-white/95 backdrop-blur-sm border border-gray-200 rounded-xl shadow-2xl font-sans min-w-[150px]">
                    <div class="text-xs font-black text-gray-500 mb-3 pb-2 border-b border-gray-100 text-center tracking-tight">${dateStr}</div>
                    <div class="flex flex-col gap-1.5 text-sm">
                      ${valueHtml}
                    </div>
                    ${paHtml}
                  </div>
                `;
            },
        },
    };

    return (
        <div className="w-full max-w-5xl mx-auto p-4 bg-white rounded-2xl shadow-xl border border-gray-100 mt-8 font-family-NaSqNe">
            {hasData && (
                <div className="flex items-center justify-between bg-gray-50 px-3 md:px-5 py-3 rounded-xl mb-6 border border-gray-200">
                    <div className="flex items-center gap-2 md:gap-4">
                        <div className="w-12 h-12 md:w-16 md:h-16 shrink-0 rounded-full bg-white overflow-hidden border-2 border-gray-300 shadow-sm flex items-center justify-center">
                            <PlayerImg
                                p_no={playerId}
                                p_img={kboData?.img || ""}
                            />
                        </div>
                        <div>
                            <div className="text-sm font-bold text-gray-500 px-1 mb-1 bg-gray-200 rounded-md hover:underline">
                                <a
                                    href={`https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId=${playerId}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    #{playerId}
                                </a>
                            </div>
                            <div className="text-xl font-black text-gray-900 tracking-tight">
                                {playerName}
                            </div>
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="text-sm font-bold text-gray-500 mb-1 flex items-center justify-end gap-1.5">
                            <span>
                                {kboData.year} {metricName}
                            </span>
                            {(selectedMetric === "eff_ops" || isPlusMetric) && (
                                <div className="relative group inline-flex items-center justify-center">
                                    <span className="w-4 h-4 text-xs font-bold text-gray-400 bg-gray-200 rounded-full flex items-center justify-center cursor-help hover:bg-gray-300 hover:text-gray-600 transition-colors">
                                        ?
                                    </span>
                                    <div className="absolute right-0 bottom-full mb-2 hidden group-hover:block w-56 p-3 bg-gray-900 text-white text-xs font-medium rounded-lg shadow-xl z-50 text-left leading-relaxed">
                                        <p className="font-bold text-emerald-400 mb-1">
                                            {selectedMetric === "eff_ops"
                                                ? "실질OPS란?"
                                                : "OPS+, 실질OPS+란?"}
                                        </p>
                                        {selectedMetric === "eff_ops" ? (
                                            <>
                                                - 실질 OPS와 OPS의 차이를 나타낸
                                                가상 지표입니다.
                                                <br />- 양수라면 좋은 도루
                                                능력으로 타격 생산성을
                                                보강했음을, 음수라면 도루 시도로
                                                타격 생산성을 저하시켰음을
                                                의미합니다.
                                                <br />- 실제로 통용되는 기록은
                                                아니니 재미로 봐주세요!
                                            </>
                                        ) : (
                                            <>
                                                - 리그 평균 대비 OPS, 실질OPS를
                                                나타냅니다.
                                                <br />
                                                {
                                                    "- 계산식: 100*{(출루율/리그출루율)+(장타율/리그장타율)-1}"
                                                }
                                                <br />- 파크 팩터의 경우
                                                고려하지 않습니다.
                                                <br />- 실제 기록과 오차가 있을
                                                수 있으며 실질OPS+는 실제로
                                                통용되는 기록은 아니니 재미로
                                                봐주세요!
                                            </>
                                        )}
                                        <div className="absolute top-full right-2 -mt-1 border-4 border-transparent border-t-gray-900"></div>
                                    </div>
                                </div>
                            )}
                        </div>
                        <div className="text-3xl font-black text-red-500 tracking-tighter mb-1">
                            {currentPrice.toFixed(isPlusMetric ? 1 : 3)}
                        </div>
                        {hasData && kboData.data.length > 1 && (
                            <div
                                className={`text-sm font-bold ${changeStatus === "up" ? "text-red-500" : changeStatus === "down" ? "text-blue-500" : "text-gray-400"}`}
                            >
                                {changeStatus === "up" && "▲ "}
                                {changeStatus === "down" && "▼ "}
                                {changeStatus === "neutral" && "- "}
                                {Math.abs(priceChange).toFixed(
                                    isPlusMetric ? 1 : 3,
                                )}
                                <span className="ml-1 opacity-80">
                                    ({priceChange > 0 ? "+" : ""}
                                    {priceChangePercent.toFixed(2)}%)
                                </span>
                            </div>
                        )}
                    </div>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                {tabs.map((tab) => (
                    <button
                        key={tab.id}
                        onClick={() => setSelectedMetric(tab.id)}
                        className={`px-4 py-1.5 rounded-lg font-bold text-sm transition-all duration-200 ${
                            selectedMetric === tab.id
                                ? "bg-gray-900 text-white shadow-md scale-105"
                                : "bg-gray-100 text-gray-500 hover:bg-gray-200 hover:text-gray-900"
                        }`}
                    >
                        {tab.name}
                    </button>
                ))}
            </div>
            <div className="w-full h-[400px] md:h-[500px]">
                {hasData ? (
                    <ReactApexChart
                        key={`chart-axis-${hideEmptyDates ? "category" : "datetime"}`}
                        options={options}
                        series={chartSeries}
                        type="line"
                        height="100%"
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center text-gray-400 font-medium">
                        데이터가 없습니다.
                    </div>
                )}
            </div>
            <div className="flex flex-col justify-between gap-4 mt-6 border-b border-gray-200 pb-4">
                <div className="flex flex-row gap-4 items-center">
                    <div className="flex bg-gray-100 p-1 rounded-lg">
                        {["daily", "weekly", "monthly"].map((t) => (
                            <button
                                key={t}
                                onClick={() => setTimeframe(t)}
                                className={`px-3 py-1.5 rounded-md font-bold text-sm transition-all ${
                                    timeframe === t
                                        ? "bg-white text-gray-900 shadow"
                                        : "text-gray-500 hover:text-gray-900"
                                }`}
                            >
                                {t === "daily"
                                    ? "일"
                                    : t === "weekly"
                                      ? "주"
                                      : "월"}
                            </button>
                        ))}
                    </div>
                    <label className="flex items-center gap-2 cursor-pointer bg-gray-50 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-100 transition-colors">
                        <input
                            type="checkbox"
                            checked={hideEmptyDates}
                            onChange={(e) =>
                                setHideEmptyDates(e.target.checked)
                            }
                            className="w-4 h-4 text-gray-900 border-gray-300 rounded focus:ring-gray-900 focus:ring-2 cursor-pointer"
                        />
                        <span className="text-sm font-bold text-gray-700 select-none">
                            공백 날짜 무시
                        </span>
                    </label>
                </div>
                <div className="flex items-center gap-2 bg-gray-50 px-4 py-2 rounded-lg border border-gray-200">
                    <span className="text-sm font-bold text-gray-700 whitespace-nowrap">
                        Y축:
                    </span>
                    <input
                        type="number"
                        step="0.010"
                        value={yMin}
                        onChange={(e) => setYMin(e.target.value)}
                        placeholder="하한"
                        className="w-16 text-center text-sm font-medium border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none p-1"
                    />
                    <span className="text-gray-400 font-bold">-</span>
                    <input
                        type="number"
                        step="0.010"
                        value={yMax}
                        onChange={(e) => setYMax(e.target.value)}
                        placeholder="상한"
                        className="w-16 text-center text-sm font-medium border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none p-1"
                    />
                </div>
            </div>
        </div>
    );
}
