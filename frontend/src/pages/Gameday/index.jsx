import axios from "axios";
import React, { useState, useEffect, forwardRef } from "react";
import Switch from "./components/Switch.jsx";
import Calendar from "./components/Calendar.tsx";
import { ThemeProvider, Collapse } from "react-bootstrap";
import "./gameday.css";

const ListSide = ({ x, type }) => {
    const [awayStatus, setAwayStatus] = useState(null);
    const [homeStatus, setHomeStatus] = useState(null);

    const teamCode = (id) => {
        switch (id) {
            case "삼성":
                return "sam";
            case "SSG":
                return "ssg";
            case "롯데":
                return "lot";
            case "두산":
                return "doo";
            case "KIA":
                return "kia";
            case "LG":
                return "lg";
            case "키움":
                return "kiw";
            case "NC":
                return "nc";
            case "한화":
                return "han";
            case "상무":
                return "sm";
            case "고양":
                return "goy";
            case "북부":
                return "fn";
            case "남부":
                return "fs";
            default:
                return "kt";
        }
    };

    useEffect(() => {
        if (Number(x.CANCEL_SC_ID)) {
        } else {
            if (Number(x.GAME_STATE_SC) === 1) {
                setAwayStatus(
                    <div>
                        <span className="rounded-full bg-orange-400 mr-1 p-0.5 sm:p-1 text-white">
                            투
                        </span>
                        {x.T_PIT_P_NM?.trim()}
                    </div>,
                );
                setHomeStatus(
                    <div>
                        {x.B_PIT_P_NM?.trim()}
                        <span className="rounded-full bg-orange-400 ml-1 p-0.5 sm:p-1 text-white">
                            투
                        </span>
                    </div>,
                );
            } else {
                if (Number(x.GAME_STATE_SC) === 3) {
                    if (Number(x.B_SCORE_CN) < Number(x.T_SCORE_CN)) {
                        setAwayStatus(
                            <div>
                                <span className="rounded-full bg-red-400 mr-1 p-0.5 sm:p-1 text-white">
                                    승
                                </span>
                                {x.W_PIT_P_NM}
                            </div>,
                        );
                        setHomeStatus(
                            <div>
                                {x.L_PIT_P_NM}
                                <span className="rounded-full bg-blue-400 ml-1 p-0.5 sm:p-1 text-white">
                                    패
                                </span>
                            </div>,
                        );
                    } else if (Number(x.B_SCORE_CN) > Number(x.T_SCORE_CN)) {
                        setAwayStatus(
                            <div>
                                <span className="rounded-full bg-blue-400 mr-1 p-0.5 sm:p-1 text-white">
                                    패
                                </span>
                                {x.L_PIT_P_NM}
                            </div>,
                        );
                        setHomeStatus(
                            <div>
                                {x.W_PIT_P_NM}
                                <span className="rounded-full bg-red-400 ml-1 p-0.5 sm:p-1 text-white">
                                    승
                                </span>
                            </div>,
                        );
                    } else {
                        setAwayStatus(
                            <div>
                                <span className="rounded-full bg-zinc-400 mr-1 p-0.5 sm:p-1 text-white">
                                    무
                                </span>
                                {x.T_D_PIT_P_NM}
                            </div>,
                        );
                        setHomeStatus(
                            <div>
                                {x.B_D_PIT_P_NM}
                                <span className="rounded-full bg-zinc-400 ml-1 p-0.5 sm:p-1 text-white">
                                    무
                                </span>
                            </div>,
                        );
                    }
                } else {
                    if (x.GAME_TB_SC_NM === "초") {
                        setAwayStatus(
                            <div>
                                <span className="rounded-full bg-green-400 mr-1 p-0.5 sm:p-1 text-white">
                                    타
                                </span>
                                {x.T_P_NM}
                            </div>,
                        );
                        setHomeStatus(
                            <div>
                                {x.B_P_NM}
                                <span className="rounded-full bg-orange-400 ml-1 p-0.5 sm:p-1 text-white">
                                    투
                                </span>
                            </div>,
                        );
                    } else {
                        setAwayStatus(
                            <div>
                                <span className="rounded-full bg-orange-400 mr-1 p-0.5 sm:p-1 text-white">
                                    투
                                </span>
                                {x.T_P_NM}
                            </div>,
                        );
                        setHomeStatus(
                            <div>
                                {x.B_P_NM}
                                <span className="rounded-full bg-green-400 ml-1 p-0.5 sm:p-1 text-white">
                                    타
                                </span>
                            </div>,
                        );
                    }
                }
            }
        }
    }, [x]);

    return (
        <div className="flex flex-col justify-center items-center w-20">
            <div className="h-12 sm:h-14">
                <img
                    src={require(
                        `../..//assets/images/logos/${
                            type === "away"
                                ? teamCode(x.AWAY_NM)
                                : teamCode(x.HOME_NM)
                        }-logo.svg`,
                    )}
                    className="h-12 w-12 sm:h-14 sm:w-14"
                    alt={type}
                />
            </div>

            {!Number(x.CANCEL_SC_ID) && (
                <div className="text-xs sm:text-sm h-5 sm:h-5.5">
                    {type === "away" && awayStatus}
                    {type === "home" && homeStatus}
                </div>
            )}
        </div>
    );
};

const ListCenter = ({ x }) => {
    const [awayStatus, setAwayStatus] = useState(null);
    const [homeStatus, setHomeStatus] = useState(null);
    const [gameStatus, setGameStatus] = useState(null);
    useEffect(() => {
        if (Number(x.CANCEL_SC_ID)) {
            setAwayStatus(null);
            setHomeStatus(null);
            setGameStatus(
                <span className="bg-zinc-400 text-white rounded-xl px-1.5 py-1 text-xs sm:text-sm">
                    {x.CANCEL_SC_NM}
                </span>,
            );
        } else {
            if (Number(x.GAME_STATE_SC) === 1) {
                setAwayStatus(null);
                setHomeStatus(null);
                setGameStatus(
                    <div className="flex flex-col justify-center items-center gap-0.5">
                        <div className="h-5 sm:h-6 flex items-center">
                            <div className="text-xs sm:text-sm font-bold">
                                {x.S_NM}
                            </div>
                        </div>
                        <span className="bg-zinc-400 text-white rounded-xl px-2.5 py-0.5 text-xs sm:text-sm">
                            {x.G_TM}
                        </span>
                        <div className="h-5 sm:h-6 flex items-center">
                            <div className="text-xs sm:text-sm">{x.TV_IF}</div>
                        </div>
                    </div>,
                );
            } else {
                setAwayStatus(
                    <span
                        className={
                            (Number(x.B_SCORE_CN) < Number(x.T_SCORE_CN)
                                ? "text-red-600"
                                : "") + " text-xl sm:text-2xl font-bold"
                        }
                    >
                        {x.T_SCORE_CN}
                    </span>,
                );
                setHomeStatus(
                    <span
                        className={
                            (Number(x.B_SCORE_CN) > Number(x.T_SCORE_CN)
                                ? "text-red-600"
                                : "") + " text-xl sm:text-2xl font-bold"
                        }
                    >
                        {x.B_SCORE_CN}
                    </span>,
                );
                if (Number(x.GAME_STATE_SC) === 3) {
                    setGameStatus(
                        <span className="bg-zinc-700 text-white rounded-xl px-2.5 py-1 text-xs sm:text-sm ">
                            종료
                        </span>,
                    );
                } else {
                    let base = "ground_base";
                    if (x.B1_BAT_ORDER_NO) base += "1";
                    if (x.B2_BAT_ORDER_NO) base += "2";
                    if (x.B3_BAT_ORDER_NO) base += "3";
                    if (base === "ground_base") base += "0";
                    setGameStatus(
                        <div className="flex flex-col justify-center items-center gap-1">
                            <div className="h-5 sm:h-6 mt-1">
                                <img
                                    src={require(
                                        `../../assets/images/gameday/${base}.png`,
                                    )}
                                    alt="base"
                                    className="h-full"
                                />
                            </div>
                            <span className="bg-emerald-500 text-white rounded-xl px-2.5 py-0.5 text-xs sm:text-sm ">
                                {x.GAME_INN_NO}회{x.GAME_TB_SC_NM}
                            </span>
                            <div className="h-5 sm:h-6 flex items-center">
                                <div className="text-xs font-bold">
                                    <span className="text-green-600">
                                        {x.BALL_CN}
                                    </span>
                                    B{" "}
                                    <span className="text-orange-400">
                                        {x.STRIKE_CN}
                                    </span>
                                    S{" "}
                                    <span className="text-red-600">
                                        {x.OUT_CN}
                                    </span>
                                    O
                                </div>
                            </div>
                        </div>,
                    );
                }
            }
        }
    }, [x]);

    return (
        <div className="flex flex-row items-center justify-center">
            <div className="w-5">{awayStatus}</div>
            <div className="w-20 sm:w-24 whitespace-nowrap">{gameStatus}</div>
            <div className="w-5">{homeStatus}</div>
        </div>
    );
};

const GameTable = (props) => {
    const { gameList, gameFilter, isRefresh } = props;

    return (
        <div className="grid">
            {gameList?.map(
                (x, index) =>
                    gameFilter?.[Number(x.GAME_STATE_SC) - 1] && (
                        <GameTableDiv key={index} x={x} isRefresh={isRefresh} />
                    ),
            )}
        </div>
    );
};

const GameTableDiv = ({ x, isRefresh }) => {
    const [open, setOpen] = useState(false);

    return (
        <div
            className={
                (Number(x.CANCEL_SC_ID) ? "bg-gray-200" : "bg-[#ffffff]") +
                " font-family-NaSqNe py-1 sm:py-2 w-80 sm:w-96 border rounded-xl mb-2 sm:mb-3"
            }
        >
            <button className="w-full h-full" onClick={() => setOpen(!open)}>
                <div className="flex justify-center items-center gap-2 sm:gap-3">
                    <ListSide x={x} type="away" />
                    <ListCenter x={x} />
                    <ListSide x={x} type="home" />
                </div>

                <GameTableDivDetail open={open} x={x} isRefresh={isRefresh} />
            </button>
        </div>
    );
};

const WPAtop = ({ players }) => {
    return (
        <div>
            {players.map((x) => (
                <div
                    key={x.P_ID}
                    className="text-xs flex items-center justify-center gap-1"
                >
                    <div className="flex items-center rounded-full border w-6 h-6 overflow-clip">
                        <img
                            src={`https://6ptotvmi5753.edge.naverncp.com/KBO_IMAGE/person/middle/2025/${x.P_ID}.jpg`}
                            className="w-full mt-1 scale-150"
                        />
                    </div>
                    <a
                        className="text-blue-600 hover:underline hover:underline-offset-2"
                        href={`https://www.koreabaseball.com/Record/Player/${x.POS}Detail/Basic.aspx?playerId=${x.P_ID}`}
                    >
                        {x.P_NM}
                    </a>
                    <span>+{x.WPA}%</span>
                </div>
            ))}
        </div>
    );
};
const GameTableDivDetail = ({ x, open, isRefresh }) => {
    const [keyPlayer, setKeyPlayer] = useState(null);
    useEffect(() => {
        if (
            open &&
            x &&
            Number(x.GAME_STATE_SC) > 1 &&
            Number(x.GAME_STATE_SC) < 4
        ) {
            axios
                .post("/api/gameday/get_game_detail.php", {
                    leId: x.LE_ID,
                    srId: x.SR_ID,
                    gameId: x.G_ID,
                })
                .then((response) => {
                    const result = response.data;
                    if (result.success) {
                        const awayKey = result.KeyPlayer.filter(
                            (e) => e.T_ID === x.AWAY_ID,
                        ).slice(0, 3);
                        const homeKey = result.KeyPlayer.filter(
                            (e) => e.T_ID === x.HOME_ID,
                        ).slice(0, 3);
                        setKeyPlayer(
                            <div className="mb-1">
                                <p className="text-sm font-bold mb-1">
                                    승리 기여 (WPA) TOP 3
                                </p>
                                <div className="flex justify-center gap-10">
                                    <WPAtop players={awayKey} />
                                    <WPAtop players={homeKey} />
                                </div>
                            </div>,
                        );
                    }
                })
                .catch((error) => {
                    console.error("오류:", error);
                });
        } else if (
            Number(x.GAME_STATE_SC) === 1 ||
            Number(x.GAME_STATE_SC) > 3
        ) {
            setKeyPlayer(null);
        }
    }, [open, isRefresh]);

    return (
        <Collapse in={open}>
            <div className="mb-1 mt-2">
                {x.LE_ID === 1 && keyPlayer}
                <div className="flex justify-center gap-2.5">
                    {!Number(x.CANCEL_SC_ID) && (
                        <a
                            className="bg-indigo-600 text-white px-3 py-1 hover:bg-indigo-800 rounded-xl text-xs sm:text-sm transition-colors duration-75"
                            href={`https://www.koreabaseball.com/Game/${
                                x.LE_ID === 1 ? "" : "Futures/"
                            }LiveText.aspx?leagueId=${x.LE_ID}&seriesId=${
                                x.SR_ID
                            }&gameId=${x.G_ID}&gyear=${x.G_DT.slice(0, 4)}`}
                        >
                            KBO 문자중계
                        </a>
                    )}
                    {x.LE_ID === 1 && (
                        <a
                            className="bg-green-500 text-white px-3 py-1 hover:bg-green-700 rounded-xl text-xs sm:text-sm transition-colors duration-75"
                            href={`https://m.sports.naver.com/game/${
                                x.G_ID
                            }${x.G_DT.slice(0, 4)}/relay`}
                        >
                            네이버 문자중계
                        </a>
                    )}
                </div>
            </div>
        </Collapse>
    );
};

const Filter = ({
    isChecked,
    setIsChecked,
    gameFilter,
    setGameFilter,
    gameDate,
    setGameDate,
    setRefresh,
}) => {
    const [open, setOpen] = useState(false);
    const [filterList, setFilterList] = useState([]);
    const gameStates = ["시작 전", "진행 중", "종료된 경기", "취소된 경기"];

    const [isSpinning, setIsSpinning] = useState(false);

    const handleClick = () => {
        setIsSpinning(true);
        setRefresh(true);
        // 1초 후에 스피닝 클래스를 해제합니다.
        setTimeout(() => {
            setIsSpinning(false);
        }, 1000);
    };

    useEffect(() => {
        setFilterList(
            gameStates.map((x, index) => (
                <div key={index}>
                    <input
                        id={`checkbox-item-${index}`}
                        type="checkbox"
                        value=""
                        className="peer hidden"
                        checked={gameFilter[index]}
                        onChange={() => {
                            const newFilter = [...gameFilter];
                            newFilter[index] = !gameFilter[index];
                            setGameFilter(newFilter);
                        }}
                    />
                    <label
                        htmlFor={`checkbox-item-${index}`}
                        className="select-none cursor-pointer rounded-xl py-0.5 px-2.5 text-sm whitespace-nowrap text-body-color transition-colors duration-200 ease-in-out peer-checked:bg-white peer-checked:text-[#0d6efd] peer-checked:shadow-[0px_1px_2px_rgba(0,0,0,0.12)]"
                    >
                        {x}
                    </label>
                </div>
            )),
        );
    }, [gameFilter]);

    return (
        <>
            <div className="flex flex-row gap-3.5 items-center">
                <Switch isChecked={isChecked} setIsChecked={setIsChecked} />
                <div
                    className={`rounded-full border w-10 h-10 ${
                        open ? "bg-[#ffffff]" : "bg-[#EFF3F8]"
                    } transition-colors duration-75`}
                >
                    <button
                        className="button-none w-full h-full "
                        type="button"
                        onClick={() => setOpen(!open)}
                        aria-controls="game-filter"
                        aria-expanded={open}
                    >
                        <i className="bi text-xl bi-funnel"></i>
                    </button>
                </div>
            </div>

            <Collapse in={open}>
                <div>
                    <div
                        id="game-filter"
                        className={`flex flex-row font-family-kbo bg-gray-200 border rounded-xl w-80 sm:w-96 h-10 items-center justify-center gap-1.5 sm:gap-3.5 overflow-x-scroll mb-1`}
                    >
                        {filterList}
                    </div>
                </div>
            </Collapse>
            <div className="flex justify-center items-center gap-10 w-full">
                <Calendar gameDate={gameDate} setGameDate={setGameDate} />
                <div className="rounded-full bg-white shadow-sm border grid place-items-center w-10 h-10">
                    <button
                        className={isSpinning ? "animate-spin-fast" : ""}
                        onClick={handleClick}
                        disabled={isSpinning}
                    >
                        <svg
                            className="h-6 w-6 scale-x-[-1] text-gray-700"
                            xmlns="https://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                            />
                        </svg>
                    </button>
                </div>
            </div>
        </>
    );
};

function Gameday() {
    const [gameListAll, setGameListAll] = useState([]);
    const [gameList, setGameList] = useState([]);
    const [isChecked, setIsChecked] = useState(false);
    const [gameFilter, setGameFilter] = useState([true, true, true, true]);
    const [isRefresh, setRefresh] = useState(false);

    const getYmd = (date) => {
        let currentDate = date;
        let year = currentDate.getFullYear();
        let month = String(currentDate.getMonth() + 1).padStart(2, "0"); // 월은 0부터 시작하므로 +1, 두 자릿수로 표시
        let day = String(currentDate.getDate()).padStart(2, "0"); // 두 자릿수로 표시

        // YYYYMMDD 형태로 조합
        return `${year}-${month}-${day}`;
    };

    const [gameDate, setGameDate] = useState(new Date());

    useEffect(() => {
        setRefresh(true);
    }, [gameDate]);
    useEffect(() => {
        if (isRefresh) {
            axios
                .post("/api/gameday/get_game_list.php", {
                    date: getYmd(gameDate),
                })
                .then((response) => {
                    const result = response.data;
                    if (result.success) {
                        setGameListAll(result);
                        setRefresh(false);
                    }
                })
                .catch((error) => {
                    console.error("경기 목록 탐지 오류:", error);
                });
        }
    }, [isRefresh]);

    useEffect(() => {
        if (gameListAll.futures) {
            if (isChecked) {
                setGameList(gameListAll.futures.game);
            } else {
                setGameList(gameListAll.kbo.game);
            }
        }
    }, [isChecked, gameListAll]);

    return (
        <>
            <div className="flex flex-col justify-center items-center mt-5 sm:mt-8">
                <div className="flex flex-col justify-center gap-3 items-center mb-3 w-80 sm:w-96">
                    <Filter
                        isChecked={isChecked}
                        setIsChecked={setIsChecked}
                        gameFilter={gameFilter}
                        setGameFilter={setGameFilter}
                        gameDate={gameDate}
                        setGameDate={setGameDate}
                        setRefresh={setRefresh}
                    />
                </div>
                <div className="flex justify-center flex-col items-center mb-3">
                    <GameTable
                        gameList={gameList}
                        gameFilter={gameFilter}
                        isRefresh={isRefresh}
                    />
                </div>
            </div>
        </>
    );
}

export default Gameday;
