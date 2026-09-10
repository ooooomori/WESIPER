import React, { useState, useEffect } from "react";
import { Form, Card, Collapse } from "react-bootstrap";
import axios from "axios"; // 💡 axios 추가
import Searchbar from "./Search";
import PlayerList from "./PlayerList";

const getDaysInMonth = (month) => {
    if (["04", "06", "09", "11"].includes(month)) return 30;
    if (month === "02") return 29;
    return 31;
};

const SEASON_DATES = {
    2026: {
        preseason: ["2026-03-12", "2026-03-24"],
        regular: ["2026-03-28", "2026-12-31"],
        postseason: ["", ""],
    },
    2025: {
        preseason: ["2025-03-08", "2025-03-18"],
        regular: ["2025-03-22", "2025-10-04"],
        postseason: ["2025-10-06", "2025-10-31"],
    },
    2024: {
        preseason: ["2024-03-09", "2024-03-19"],
        regular: ["2024-03-23", "2024-10-01"],
        postseason: ["2024-10-02", "2024-10-28"],
    },
    2023: {
        preseason: ["2023-03-13", "2023-03-28"],
        regular: ["2023-04-01", "2023-10-17"],
        postseason: ["2023-10-19", "2023-11-13"],
    },
    2022: {
        preseason: ["2022-03-12", "2022-03-29"],
        regular: ["2022-04-02", "2022-10-11"],
        postseason: ["2022-10-13", "2022-11-08"],
    },
    2021: {
        preseason: ["2021-03-21", "2021-03-30"],
        regular: ["2021-04-03", "2021-10-31"],
        postseason: ["2021-11-01", "2021-11-18"],
    },
};

export default function PlayerSearchUI({ setKboData, comparisonMode = false, setComparisonData }) {
    const [seasonDates, setSeasonDates] = useState(null);
    const [searchPlayer, setSearchPlayer] = useState(null); // 선택된 선수 객체 (id 등 포함 가정)
    const [comparisonPlayers, setComparisonPlayers] = useState([]);
    const [year, setYear] = useState("2026");
    const [gameType, setGameType] = useState("regular");
    const [datePreset, setDatePreset] = useState("whole");
    const [startDate, setStartDate] = useState("2026-03-28"); // 시작일 상태 추가
    const [endDate, setEndDate] = useState("2026-12-31"); // 종료일 상태 추가
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const fetchSchedule = async () => {
            try {
                const response = await axios.get(
                    "/api/kbocandle/get_schedule.php",
                );
                setSeasonDates(response.data);
            } catch (error) {
                console.error("🔥 시즌 일정 데이터 통신 실패:", error);
                alert(
                    "시즌 일정을 서버에서 불러오지 못했습니다.\n새로고침 후에도 오류가 반복된다면 관리자에게 문의해주세요.",
                );
            }
        };

        fetchSchedule();
    }, []);

    useEffect(() => {
        if (!comparisonMode || !searchPlayer?.PlayerId) return;
        setComparisonPlayers((players) => players.some((player) => player.PlayerId === searchPlayer.PlayerId)
            ? players : [searchPlayer, ...players]);
    }, [comparisonMode, searchPlayer]);

    const getFormattedDate = (d) => {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    };

    const handleSearch = async (e) => {
        e.preventDefault();

        const targets = comparisonMode ? comparisonPlayers : [searchPlayer].filter(Boolean);
        if (targets.length < (comparisonMode ? 2 : 1)) {
            alert(comparisonMode ? "비교할 선수를 2명 이상 선택해주세요." : "선수를 먼저 선택해주세요.");
            return;
        }

        setIsLoading(true);
        try {
            const responses = await Promise.all(targets.map((player) => axios.get("/api/kbocandle/get_data.php", {
                params: {
                    season: gameType, year, player_id: player.PlayerId,
                    start_date: startDate, end_date: endDate,
                    date_preset: datePreset, img: player.Img,
                },
            })));
            const results = responses.map((response, index) => ({
                ...response.data,
                player: targets[index],
            })).filter((result) => result.success);

            if (comparisonMode) {
                setComparisonData?.(results);
                if (results.length < 2) alert("비교 가능한 기록이 있는 선수가 2명 미만입니다.");
            } else if (setKboData) {
                setKboData(results[0] || responses[0].data);
            }
        } catch (error) {
            console.error("데이터 조회 실패:", error);
            alert("데이터를 불러오는 중 오류가 발생했습니다.");
        } finally {
            setIsLoading(false);
        }
    };

    const handlePlayerSelect = (player) => {
        if (!comparisonMode) {
            setSearchPlayer(player);
            return;
        }
        setComparisonPlayers((players) => players.some((item) => item.PlayerId === player.PlayerId)
            ? players : [...players, player]);
    };

    return (
        <Card className="candle-search-panel font-family-NaSqNe mt-2 theme-light">
            <Form onSubmit={handleSearch} className="candle-search-form">
                <div className="flex flex-col items-start gap-2">
                    {/* 상단: 검색바 + 검색버튼 가로 배치 */}
                    <div className="candle-search-main flex items-center justify-between gap-2 w-full">
                        <Searchbar setSearchPlayer={handlePlayerSelect} clearOnSelect={comparisonMode} />
                        <button
                            type="submit"
                            className="candle-search-submit"
                            disabled={isLoading}
                        >
                            {isLoading ? "조회 중" : comparisonMode ? "비교하기" : "조회하기"}
                        </button>
                    </div>

                    {/* 하단: 상세 설정 버튼 좌측 정렬 */}
                    <div className="candle-search-subline flex items-center justify-between gap-4 w-full">
                        {/* 왼쪽 영역: searchPlayer가 있을 때만 공간을 차지하며 내부에서 유연하게 배치 */}
                        <div className="flex items-center min-w-0">
                            {!comparisonMode && searchPlayer && (
                                <div className="candle-selected-player flex items-center align-middle min-w-0">
                                    <div className="candle-selected-label text-sm font-bold mb-0 whitespace-nowrap mr-2 shrink-0">
                                        조회할 선수:
                                    </div>
                                    <div className="min-w-0">
                                        <PlayerList player={searchPlayer} />
                                    </div>
                                </div>
                            )}
                            {comparisonMode && (
                                <div className="candle-compare-selection-list">
                                    <span className="candle-selected-label">비교할 선수:</span>
                                    {comparisonPlayers.length ? comparisonPlayers.map((player) => (
                                        <div className="candle-compare-selection" key={player.PlayerId}>
                                            <PlayerList player={player} />
                                            <button
                                                type="button"
                                                aria-label={`${player.Name} 비교에서 제외`}
                                                onClick={() => setComparisonPlayers((players) => players.filter((item) => item.PlayerId !== player.PlayerId))}
                                            >×</button>
                                        </div>
                                    )) : null}
                                </div>
                            )}
                        </div>

                        {/* 우측 고정 영역: shrink-0을 주어 절대 위치나 크기가 변하지 않고 우측에 고정 */}
                        <button
                            type="button"
                            onClick={() => setShowAdvanced(!showAdvanced)}
                            className="candle-advanced-toggle text-sm font-bold flex items-center gap-1 shrink-0 whitespace-nowrap ml-auto"
                            aria-controls="advanced-options"
                            aria-expanded={showAdvanced}
                        >
                            상세 설정 {showAdvanced ? "▲" : "▼"}
                        </button>
                    </div>
                </div>
                {/* 중단: 상세 설정 (펼치기/접기 영역) */}
                <Collapse in={showAdvanced}>
                    <div id="advanced-options">
                        <div className="candle-advanced-panel">
                            <Form.Group className="flex items-center gap-3 mb-4">
                                <Form.Label className="text-sm font-bold text-gray-700 mb-0 whitespace-nowrap">
                                    연도 선택
                                </Form.Label>
                                <Form.Select
                                    value={year}
                                    onChange={(e) => {
                                        const selectedYear = e.target.value;
                                        setYear(selectedYear);

                                        // 💡 연도 변경 시 현재 선택된 시즌(gameType)의 기본 날짜로 조회 기간 자동 업데이트
                                        const defaultRange = SEASON_DATES[
                                            selectedYear
                                        ]?.[gameType] || ["", ""];
                                        setStartDate(defaultRange[0] || "");
                                        setEndDate(defaultRange[1] || "");
                                    }}
                                    className="candle-select font-medium w-auto cursor-pointer"
                                >
                                    {seasonDates &&
                                        Object.keys(seasonDates)
                                            // 객체 키(연도)를 추출해 내림차순(최신순)으로 강제 정렬한다
                                            .sort((a, b) => b - a)
                                            .map((yr) => (
                                                <option key={yr} value={yr}>
                                                    {yr}
                                                </option>
                                            ))}
                                </Form.Select>
                            </Form.Group>
                            {/* 시즌 종류 */}
                            <Form.Group className="mb-4">
                                <Form.Label className="text-sm font-bold text-gray-700 mb-2 block">
                                    시즌 종류
                                </Form.Label>
                                <div className="candle-radio-group flex gap-4">
                                    {[
                                        { id: "preseason", label: "시범경기" },
                                        { id: "regular", label: "정규시즌" },
                                        {
                                            id: "postseason",
                                            label: "포스트시즌",
                                        },
                                    ].map((item) => {
                                        // 💡 1. 받아온 스케줄 배열의 첫 번째 값이 비어있는지 얄짤없이 검열한다.
                                        const hasSchedule =
                                            seasonDates?.[year]?.[item.id]?.[0];

                                        return (
                                            <Form.Check
                                                key={item.id}
                                                type="radio"
                                                id={item.id}
                                                name="gameType"
                                                label={item.label}
                                                value={item.id}
                                                checked={gameType === item.id}
                                                disabled={!hasSchedule} // 데이터가 없으면 즉시 비활성화
                                                onChange={(e) => {
                                                    const selectedType =
                                                        e.target.value;
                                                    setGameType(selectedType);

                                                    const defaultRange =
                                                        seasonDates[year]?.[
                                                            selectedType
                                                        ] || ["", ""];
                                                    setStartDate(
                                                        defaultRange[0] || "",
                                                    );
                                                    setEndDate(
                                                        defaultRange[1] || "",
                                                    );
                                                }}
                                                // 비활성화 시 투명도를 낮추고 마우스 커서를 차단해 시각적으로도 완전히 죽여버린다.
                                                className="candle-radio font-medium"
                                            />
                                        );
                                    })}
                                </div>
                            </Form.Group>

                            {/* 조회 기간 */}
                            <Form.Group className="mb-0">
                                <Form.Label className="text-sm font-bold text-gray-700 mb-2 block">
                                    조회 기간
                                </Form.Label>

                                <div className="candle-radio-group flex flex-wrap gap-4 mb-3">
                                    {[
                                        { id: "whole", label: "전체 시즌" },
                                        { id: "7", label: "최근 7경기" },
                                        { id: "15", label: "최근 15경기" },
                                        { id: "30", label: "최근 30경기" },
                                        { id: "custom", label: "직접 지정" },
                                    ].map((preset) => (
                                        <Form.Check
                                            key={preset.id}
                                            type="radio"
                                            id={`preset-${preset.id}`}
                                            name="datePreset"
                                            label={preset.label}
                                            checked={datePreset === preset.id}
                                            onChange={() => {
                                                setDatePreset(preset.id);

                                                if (preset.id === "whole") {
                                                    // 💡 전체 시즌: 원본 일정으로 강제 복원
                                                    const defaultRange =
                                                        seasonDates?.[year]?.[
                                                            gameType
                                                        ] || ["", ""];
                                                    setStartDate(
                                                        defaultRange[0] || "",
                                                    );
                                                    setEndDate(
                                                        defaultRange[1] || "",
                                                    );
                                                } else if (
                                                    preset.id !== "custom"
                                                ) {
                                                    // 💡 최근 n경기: startDate 자체를 숫자로 박아버려 백엔드에 쿼리 지시를 내린다.
                                                    setStartDate(preset.id);
                                                }
                                                // custom일 경우: 값 변경 없이 라디오 시각적 포커스만 이동
                                            }}
                                            className="candle-radio text-sm cursor-pointer"
                                        />
                                    ))}
                                </div>

                                {/* 기존 직접 지정 셀렉트 영역 */}
                                <div className="candle-date-range flex items-center gap-1 md:gap-2">
                                    {/* 시작일 선택 */}
                                    <div className="flex items-center gap-1 w-full">
                                        <Form.Select
                                            size="sm"
                                            value={
                                                startDate
                                                    ? startDate.split("-")[1]
                                                    : ""
                                            }
                                            onChange={(e) => {
                                                setDatePreset("custom"); // 💡 건드리는 즉시 라디오 버튼을 '직접 지정'으로 돌려세운다.
                                                const newMonth = e.target.value;
                                                const currentYear = startDate
                                                    ? startDate.split("-")[0]
                                                    : year;
                                                let currentDay = startDate
                                                    ? startDate.split("-")[2]
                                                    : "01";
                                                const maxDay = getDaysInMonth(
                                                    currentYear,
                                                    newMonth,
                                                );
                                                if (Number(currentDay) > maxDay)
                                                    currentDay = String(maxDay);
                                                setStartDate(
                                                    `${currentYear}-${newMonth}-${currentDay}`,
                                                );
                                            }}
                                            className="candle-select w-full !text-[11px] sm:!text-sm !py-1 sm:!py-2 !px-1 sm:!px-2 !pr-5"
                                        >
                                            <option value="" disabled>
                                                월
                                            </option>
                                            {[...Array(12)].map((_, i) => {
                                                const m = String(
                                                    i + 1,
                                                ).padStart(2, "0");
                                                return (
                                                    <option key={m} value={m}>
                                                        {m}월
                                                    </option>
                                                );
                                            })}
                                        </Form.Select>

                                        <Form.Select
                                            size="sm"
                                            value={
                                                startDate
                                                    ? startDate.split("-")[2]
                                                    : ""
                                            }
                                            onChange={(e) => {
                                                setDatePreset("custom"); // 💡 포커스 강제 탈취
                                                const currentYear = startDate
                                                    ? startDate.split("-")[0]
                                                    : year;
                                                const currentMonth = startDate
                                                    ? startDate.split("-")[1]
                                                    : "03";
                                                setStartDate(
                                                    `${currentYear}-${currentMonth}-${e.target.value}`,
                                                );
                                            }}
                                            className="candle-select w-full !text-[11px] sm:!text-sm !py-1 sm:!py-2 !px-1 sm:!px-2 !pr-5"
                                        >
                                            <option value="" disabled>
                                                일
                                            </option>
                                            {[
                                                ...Array(
                                                    getDaysInMonth(
                                                        startDate
                                                            ? startDate.split(
                                                                  "-",
                                                              )[0]
                                                            : year,
                                                        startDate
                                                            ? startDate.split(
                                                                  "-",
                                                              )[1]
                                                            : "03",
                                                    ),
                                                ),
                                            ].map((_, i) => {
                                                const d = String(
                                                    i + 1,
                                                ).padStart(2, "0");
                                                return (
                                                    <option key={d} value={d}>
                                                        {d}일
                                                    </option>
                                                );
                                            })}
                                        </Form.Select>
                                    </div>

                                    <span className="candle-date-separator font-extrabold text-xs sm:text-base px-1">
                                        ~
                                    </span>

                                    {/* 종료일 선택 */}
                                    <div className="flex items-center gap-1 w-full">
                                        <Form.Select
                                            size="sm"
                                            value={
                                                endDate
                                                    ? endDate.split("-")[1]
                                                    : ""
                                            }
                                            onChange={(e) => {
                                                setDatePreset("custom"); // 💡 포커스 강제 탈취
                                                const newMonth = e.target.value;
                                                const currentYear = endDate
                                                    ? endDate.split("-")[0]
                                                    : year;
                                                let currentDay = endDate
                                                    ? endDate.split("-")[2]
                                                    : "01";
                                                const maxDay = getDaysInMonth(
                                                    currentYear,
                                                    newMonth,
                                                );
                                                if (Number(currentDay) > maxDay)
                                                    currentDay = String(maxDay);
                                                setEndDate(
                                                    `${currentYear}-${newMonth}-${currentDay}`,
                                                );
                                            }}
                                            className="candle-select w-full !text-[11px] sm:!text-sm !py-1 sm:!py-2 !px-1 sm:!px-2 !pr-5"
                                        >
                                            <option value="" disabled>
                                                월
                                            </option>
                                            {[...Array(12)].map((_, i) => {
                                                const m = String(
                                                    i + 1,
                                                ).padStart(2, "0");
                                                return (
                                                    <option key={m} value={m}>
                                                        {m}월
                                                    </option>
                                                );
                                            })}
                                        </Form.Select>

                                        <Form.Select
                                            size="sm"
                                            value={
                                                endDate
                                                    ? endDate.split("-")[2]
                                                    : ""
                                            }
                                            onChange={(e) => {
                                                setDatePreset("custom"); // 💡 포커스 강제 탈취
                                                const currentYear = endDate
                                                    ? endDate.split("-")[0]
                                                    : year;
                                                const currentMonth = endDate
                                                    ? endDate.split("-")[1]
                                                    : "10";
                                                setEndDate(
                                                    `${currentYear}-${currentMonth}-${e.target.value}`,
                                                );
                                            }}
                                            className="candle-select w-full !text-[11px] sm:!text-sm !py-1 sm:!py-2 !px-1 sm:!px-2 !pr-5"
                                        >
                                            <option value="" disabled>
                                                일
                                            </option>
                                            {[
                                                ...Array(
                                                    getDaysInMonth(
                                                        endDate
                                                            ? endDate.split(
                                                                  "-",
                                                              )[0]
                                                            : year,
                                                        endDate
                                                            ? endDate.split(
                                                                  "-",
                                                              )[1]
                                                            : "10",
                                                    ),
                                                ),
                                            ].map((_, i) => {
                                                const d = String(
                                                    i + 1,
                                                ).padStart(2, "0");
                                                return (
                                                    <option key={d} value={d}>
                                                        {d}일
                                                    </option>
                                                );
                                            })}
                                        </Form.Select>
                                    </div>
                                </div>
                            </Form.Group>
                        </div>
                    </div>
                </Collapse>
            </Form>
        </Card>
    );
}
