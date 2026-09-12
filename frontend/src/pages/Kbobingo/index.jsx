import { useState, useEffect } from "react";
import wspImg from "../../assets/images/wsp.png";
import Button from "react-bootstrap/Button";
import "./kbobingo.css";
import axios from "axios";
import ProgressBar from "react-bootstrap/ProgressBar";
import Search from "./components/Search.jsx";
import PlayerImg from "./components/PlayerImg.jsx";
import HelpModal from "./components/HelpModal.jsx";
import ResultModal from "./components/ResultModal/ResultModal.jsx";
import PrevBingo from "./components/PrevBingo.jsx";
import { Navigate, useSearchParams, useNavigate } from "react-router-dom";

const Grid = (props) => {
    const {
        date,
        status,
        setShowResult,
        setMode,
        selected,
        setSelected,
        setShowHelp,
        setShowPrev,
        score,
        updateScore,
    } = props;

    const items = [0, 1, 2, 3, 4, 5, 6, 7, 8];

    return (
        <div className="flex-shrink-0 flex-grow flex flex-col mt-3.5 font-family-NaSqNe">
            <div>
                <div className="flex items-center justify-center text-xs sm:text-base">
                    KBO BINGO{" "}
                    <span className="font-bold ml-1">
                        #{status[date]?.grid.index}
                    </span>
                    <span className="mx-1.5 sm:mx-3">·</span>
                    <a
                        className="text-blue-600 hover:underline hover:underline-offset-2"
                        href=""
                        onClick={(e) => {
                            e.preventDefault();
                            setShowPrev(true);
                        }}
                    >
                        이전 빙고 풀기
                    </a>
                    <span className="mx-1.5 sm:mx-3">·</span>
                    <a
                        className="text-blue-600 hover:underline hover:underline-offset-2"
                        href=""
                        onClick={(e) => {
                            e.preventDefault();
                            setShowHelp(true);
                        }}
                    >
                        도움말
                    </a>
                </div>
                <div className="flex justify-center flex-row items-center mt-3.5">
                    <div className="flex flex-col justify-center">
                        <div className="flex justify-center items-center h-14 sm:h-16 md:h-32">
                            <img
                                className="h-12 sm:h-16 md:h-24"
                                src={wspImg}
                                alt="WSP"
                            />
                        </div>
                        <GridCond type="row" cond={status[date]?.grid.row[0]} />
                        <GridCond type="row" cond={status[date]?.grid.row[1]} />
                        <GridCond type="row" cond={status[date]?.grid.row[2]} />
                    </div>
                    <div className="flex flex-col">
                        <div className="grid grid-cols-3 overflow-hidden  place-items-center">
                            <GridCond
                                type="col"
                                cond={status[date]?.grid.col[0]}
                            />
                            <GridCond
                                type="col"
                                cond={status[date]?.grid.col[1]}
                            />
                            <GridCond
                                type="col"
                                cond={status[date]?.grid.col[2]}
                            />
                        </div>
                        <div className="grid grid-cols-3 ">
                            {items.map((e) => (
                                <GridItem
                                    key={e}
                                    no={e}
                                    gridIndex={status[date]?.grid.index}
                                    selected={selected}
                                    setMode={setMode}
                                    setSelected={setSelected}
                                    chance={status[date]?.chance}
                                    item={
                                        status[date]?.correctAnswer[
                                            Math.floor(e / 3)
                                        ][e % 3] ?? (status[date] ? -1 : null)
                                    }
                                    score={score}
                                    updateScore={updateScore}
                                />
                            ))}
                        </div>
                    </div>

                    <GridEtc
                        type="large"
                        score={score}
                        status={status}
                        date={date}
                        setShowResult={setShowResult}
                        setMode={setMode}
                    />
                </div>

                <div className="grid place-items-center mt-3">
                    <ProgressBar
                        id="progress"
                        now={
                            (100 *
                                status[date]?.correctAnswer.reduce(
                                    (count, innerArray) => {
                                        return (
                                            count +
                                            innerArray.filter((e) => e !== null)
                                                .length
                                        );
                                    },
                                    0,
                                )) /
                            9
                        }
                        className="!h-2 sm:!h-3 w-80 sm:w-[30rem]"
                    />
                </div>

                <div className="text-xs sm:text-base py-4 sm:py-6 sr-hide-screenshot px-4 text-center">
                    <span>
                        모든 데이터는{" "}
                        <a
                            className="text-blue-600 hover:underline hover:underline-offset-2"
                            href="https://www.koreabaseball.com"
                        >
                            KBO 기록실
                        </a>
                        에 기반합니다.
                    </span>
                </div>
                <GridEtc
                    type="small"
                    status={status}
                    date={date}
                    score={score}
                    setShowResult={setShowResult}
                    setMode={setMode}
                />
            </div>
        </div>
    );
};

const GridItem = (props) => {
    const {
        gridIndex,
        no,
        item,
        chance,
        setMode,
        setSelected,
        selected,
        score,
        updateScore,
    } = props;

    const [rate, setRate] = useState(null);
    const [pImg, setpImg] = useState("2025_ssg_b_l");
    const [pRetire, setpRetire] = useState(2025);
    const [pName, setpName] = useState(null);
    const [bgColor, setBgColor] = useState("bg-white");
    useEffect(() => {
        if (item && item !== -1) {
            setBgColor("bg-[#4ade90]");

            const controller = new AbortController();
            axios
                .post(
                    "/api/kbobingo/pick_data.php",
                    {
                        index: gridIndex,
                        p_no: item["p_no"],
                        row: Math.floor(no / 3),
                        col: no % 3,
                        type: 0,
                    },
                    { signal: controller.signal },
                )
                .then((response) => {
                    const result = response.data;
                    if (result.code === 200) {
                        if (result.img) {
                            setpImg(result.img);
                        } else {
                            setpImg(item.p_img);
                        }
                        if (result.name) {
                            setpName(result.name);
                        } else {
                            setpName(item.p_name);
                        }
                        if (result.peYear) {
                            setpName(result.name);
                        } else {
                            setpName(item.p_name);
                        }
                        setRate(result.rate);
                        updateScore(no, result.score);
                    } else {
                        alert(result.error);
                    }
                })
                .catch((error) => {
                    console.error("KBO BINGO 데이터 받아오기 실패:", error);
                });
            return () => {
                controller.abort();
            };
        } else if (item && item === -1) {
            updateScore(no, 0);
        }
    }, [item, gridIndex]);

    useEffect(() => {
        if (chance > 0) {
            if (selected === no) {
                setBgColor("bg-yellow-100");
            } else {
                if (item === -1) setBgColor("bg-white hover:bg-gray-100");
            }
        }
    }, [chance, selected, item]);

    const applyBorder = () => {
        switch (no) {
            case 0:
                return "rounded-tl-xl border-t border-l";
            case 1:
                return "border-t border-l border-r";
            case 2:
                return "rounded-tr-xl border-t border-r";
            case 3:
                return "border-t border-l border-b";
            case 4:
                return "border-t border-l border-r border-b";
            case 5:
                return "border-t border-r border-b";
            case 6:
                return "rounded-bl-xl border-b border-l";
            case 7:
                return "border-b border-l border-r";
            case 8:
                return "rounded-br-xl border-b border-r";
        }
    };

    return (
        <div
            className={
                bgColor +
                " cursor-pointer flex items-center justify-center w-[5.5rem] sm:w-24 md:w-36 h-[5.5rem] sm:h-24 md:h-36 transition-colors duration-75 overflow-hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary focus-visible:z-10  " +
                applyBorder()
            }
        >
            {item && item !== -1 && pName && (
                <div
                    className="relative overflow flex justify-center items-center w-full h-full"
                    onClick={() => {
                        const isBatter = pImg?.split("_")[2] === "b";
                        const pageType = isBatter
                            ? "HitterDetail"
                            : "PitcherDetail";
                        window.location.href = `https://www.koreabaseball.com/Record/Player/${pageType}/Total.aspx?playerId=${item.p_no}`;
                    }}
                >
                    <div className="absolute top-0 right-0 text-white text-[0.6rem] md:text-xs bg-zinc-800 opacity-95 pl-1.5 pr-1 sm:pr-1.5 py-0.5 rounded-bl-lg">
                        {rate}%
                    </div>
                    <PlayerImg p_no={item.p_no} p_img={pImg} />
                    <div className="absolute left-0 right-0 bottom-0 bg-zinc-800 opacity-95 text-xs sm:text-xs md:text-sm py-px line-clamp-1 text-white text-center">
                        {pName}
                    </div>
                </div>
            )}
            {item && item === -1 && (
                <button
                    className="button-none w-full h-full"
                    onClick={() =>
                        chance > 0 && (setMode("search"), setSelected(no))
                    }
                ></button>
            )}
        </div>
    );
};

const resolveLogoUrl = (name) => {
    return new URL(
        `../../assets/images/logos/${name}-logo.svg`,
        import.meta.url,
    ).href;
};

const GridCond = (props) => {
    const [gridCond, setGridCond] = useState(null);
    const { cond, type } = props;
    useEffect(() => {
        if (cond) {
            const str = cond.split("-");
            if (str[0] === "team") {
                setGridCond(
                    <img
                        src={resolveLogoUrl(str[1])}
                        alt={str[1]}
                        className="w-16 sm:w-20 md:w-24 h-12 sm:h-16 md:h-24"
                    />,
                );
            } else if (str[0] === "award" || str[0] === "profile") {
                let text = "";
                let bigo = "";
                switch (str[1]) {
                    case "as":
                        text = <span className="text-black">KBO 올스타</span>;
                        break;
                    case "mvp":
                        text = "KBO MVP";
                        bigo = "정규시즌 + KS";
                        break;
                    case "gg":
                        text = <span className="text-black">KBO 골든글러브</span>;
                        break;
                    case "draft_1r":
                        text = "1차 · 1라운드 지명";
                        break;
                    case "one_club":
                        text = "원 클럽 플레이어";
                        break;
                    case "active_2025":
                        text = "2025년 이후 출장";
                        bigo = "1경기 이상";
                        break;
                    case "is_WBC":
                        text = (
                            <img
                                src={resolveLogoUrl("wbc")}
                                alt="WBC"
                                className="w-8 sm:w-12 md:w-16 h-8 sm:h-12 md:h-16 block mx-auto"
                            />
                        );
                        bigo = "WBC 대표팀";
                        break;
                    case "is_MLB":
                        text = (
                            <img
                                src={resolveLogoUrl("mlb")}
                                alt="MLB"
                                className="w-12 sm:w-16 md:w-20 h-8 sm:h-12 md:h-16 object-contain block mx-auto"
                            />
                        );
                        bigo = "MLB 1경기 이상";
                        break;
                }
                setGridCond(
                    <div className="px-1 break-keep text-sm md:text-xl font-bold">
                        {text}
                        <div className="text-gray-600 text-[0.625rem] md:text-base">
                            {bigo}
                        </div>
                    </div>,
                );
            } else if (str[0] === "stat") {
                const stat = str[1].split("_");
                let text = "";
                switch (stat[0]) {
                    case "war":
                        text = `${stat[1]}+ WAR`;
                        break;
                    case "pa":
                        text = `${stat[1]}+ 타석`;
                        break;
                    case "avg":
                        text = `타율 ${stat[1]}+`;
                        break;
                    case "obp":
                        text = `출루율 ${stat[1]}+`;
                        break;
                    case "slg":
                        text = `장타율 ${stat[1]}+`;
                        break;
                    case "ops":
                        text = `OPS ${stat[1]}+`;
                        break;
                    case "h":
                        text = `${stat[1]}+ 안타`;
                        break;
                    case "2b":
                        text = `${stat[1]}+ 2루타`;
                        break;
                    case "3b":
                        text = `${stat[1]}+ 3루타`;
                        break;
                    case "hr":
                        text = `${stat[1]}+ 홈런`;
                        break;
                    case "rbi":
                        text = `${stat[1]}+ 타점`;
                        break;
                    case "sb":
                        text = `${stat[1]}+ 도루`;
                        break;
                    case "ip":
                        text = `${stat[1]}+ 이닝`;
                        break;
                    case "era":
                        text = `ERA ${stat[1]}-`;
                        break;
                    case "so":
                        text = `${stat[1]}+ 탈삼진`;
                        break;
                    case "win":
                        text = `${stat[1]}+ 승`;
                        break;
                    case "hld":
                        text = `${stat[1]}+ 홀드`;
                        break;
                    case "sv":
                        text = `${stat[1]}+ 세이브`;
                        break;
                }
                setGridCond(
                    <div className="px-1 break-keep text-sm md:text-xl font-bold">
                        {text}
                        <div className="text-gray-600 text-[0.625rem] md:text-base">
                            {stat[2] === "season" ? "단일 시즌" : "커리어 통산"}
                        </div>
                    </div>,
                );
            } else if (str[0] === "pos") {
                const pos = str[1];
                let text = "";
                switch (pos) {
                    case "c":
                        text = "포수";
                        break;
                    case "1b":
                        text = "1루수";
                        break;
                    case "2b":
                        text = "2루수";
                        break;
                    case "3b":
                        text = "3루수";
                        break;
                    case "ss":
                        text = "유격수";
                        break;
                    case "lf":
                        text = "좌익수";
                        break;
                    case "cf":
                        text = "중견수";
                        break;
                    case "rf":
                        text = "우익수";
                        break;
                    case "of":
                        text = "외야수";
                        break;
                }
                setGridCond(
                    <div className="px-1 break-keep text-sm md:text-xl font-bold">
                        {text} 출장
                        <div className="text-gray-600 text-[0.625rem] md:text-base">
                            '01년 ~ '25년
                        </div>
                    </div>,
                );
            }
        }
    }, [cond]);
    return (
        <div
            className={
                (type === "col"
                    ? "h-16 sm:h-20 md:h-28"
                    : "h-[5.5rem] sm:h-24 md:h-36") +
                " flex items-center justify-center w-20 sm:w-24 md:w-36"
            }
        >
            {gridCond}
        </div>
    );
};

const GridEtc = (props) => {
    const { type, status, date, score, setShowResult, setMode } = props;

    //sm:w-36 md:w-48 h-full
    return (
        <div className="flex justify-center">
            <div
                className={
                    type === "large"
                        ? "hidden sm:block sm:w-24 md:w-36 h-full"
                        : "sm:hidden"
                }
            >
                <span>
                    {status[date]?.chance > 0 ? "남은 기회" : "내 점수"}
                </span>
                <div className="text-center text-4xl sm:py-1 font-semibold">
                    <span>
                        {status[date]?.chance > 0
                            ? status[date].chance
                            : score?.reduce((acc, cur) => {
                                  return acc + cur;
                              }, 0)}
                    </span>
                </div>
                <div className="mt-2">
                    {status[date]?.chance > 0 ? (
                        <Button
                            variant="outline-secondary"
                            size="sm"
                            className="!rounded-2xl"
                            onClick={() => {
                                if (window.confirm("정말 포기하시게요?")) {
                                    setMode("finished");
                                }
                            }}
                        >
                            포기하기
                        </Button>
                    ) : (
                        <Button
                            variant="primary"
                            size="sm"
                            className="!rounded-2xl !px-4"
                            onClick={() => setShowResult(true)}
                        >
                            결과 보기
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
};

function Kbobingo() {
    const navigate = useNavigate();
    const [status, setStatus] = useState({});
    const [mode, setMode] = useState("main");
    const [showHelp, setShowHelp] = useState(false);
    const [showResult, setShowResult] = useState(false);
    const [showPrev, setShowPrev] = useState(false);
    const [selected, setSelected] = useState(false);
    const [searchParams, setSearchParams] = useSearchParams();
    const [score, setScore] = useState([
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
    ]);

    let gridIndex = searchParams.get("index");
    if (gridIndex !== null && gridIndex !== "") {
        gridIndex = +gridIndex;
    } else {
        gridIndex = null;
    }

    const getYmd = (date) => {
        let currentDate = date;
        // 연, 월, 일을 가져오기
        let year = currentDate.getFullYear();
        let month = String(currentDate.getMonth() + 1).padStart(2, "0"); // 월은 0부터 시작하므로 +1, 두 자릿수로 표시
        let day = String(currentDate.getDate()).padStart(2, "0"); // 두 자릿수로 표시

        // YYYYMMDD 형태로 조합
        return `${year}-${month}-${day}`;
    };

    const [date, setDate] = useState(getYmd(new Date()));

    useEffect(() => {
        let uuid = localStorage.getItem("uuid");
        if (uuid === null) {
            if (typeof crypto.randomUUID === "function") {
                uuid = crypto.randomUUID();
            } else {
                uuid =
                    Date.now().toString(36) +
                    Math.random().toString(36).substring(2);
            }
            localStorage.setItem("uuid", uuid);
        }
    }, [navigate]);

    useEffect(() => {
        setShowPrev(false);
        let newStatus = JSON.parse(localStorage.getItem("kbobingo"));

        if (newStatus === null) {
            newStatus = {};
            setShowHelp(true);
        }
        axios
            .post("/api/kbobingo/get_grid.php", {
                index: gridIndex,
            })
            .then((response) => {
                const result = response.data;
                if (result.code === 200) {
                    setDate(result.date);

                    if (newStatus[result.date] === undefined) {
                        newStatus[result.date] = {};
                        newStatus[result.date].correctAnswer = [
                            [null, null, null],
                            [null, null, null],
                            [null, null, null],
                        ];
                        newStatus[result.date].wrongAnswer = [
                            [null, null, null],
                            [null, null, null],
                            [null, null, null],
                        ];
                        newStatus[result.date].chance = 9;
                    }
                    handleDateChange(newStatus, result.date, result.grid);
                } else {
                    alert(result.error);
                    setMode("incorrect");
                }
            })
            .catch((error) => {
                console.error("KBO BINGO 빙고판 받아오기 실패:", error);
            });
    }, [searchParams]);

    const handleDateChange = (newStatus, date, grid) => {
        newStatus[date].grid = grid;
        setStatus(newStatus);
        localStorage.setItem("kbobingo", JSON.stringify(newStatus));
    };

    useEffect(() => {
        if (mode === "finished") {
            setSelected(null);
            const newStatus = { ...status };
            newStatus[date].chance = 0;

            setStatus(newStatus);
            localStorage.setItem("kbobingo", JSON.stringify(newStatus));
            setShowResult(true);
        }
    }, [mode]);

    useEffect(() => {
        if (status[date]?.chance === 0 && score.every((e) => e !== null)) {
            const newStatus = { ...status };

            newStatus[date].score = score.reduce((acc, cur) => {
                return acc + cur;
            }, 0);

            localStorage.setItem("kbobingo", JSON.stringify(newStatus));
            setStatus(newStatus);
            setShowResult(true);
        }
    }, [score, status[date]?.chance]);

    const updateScore = (index, newValue) => {
        setScore((prevScore) => {
            const newScore = [...prevScore];
            newScore[index] = newValue;
            return newScore;
        });
    };

    return (
        <>
            {mode === "incorrect" ? (
                <>
                    {window.location.replace("/bingo")}
                    <Navigate replace to="/bingo" />
                </>
            ) : (
                <>
                    <HelpModal show={showHelp} setShow={setShowHelp} />
                    <ResultModal
                        show={showResult}
                        setShow={setShowResult}
                        setShowPrev={setShowPrev}
                        status={status}
                        date={date}
                        score={score}
                    />
                    <PrevBingo
                        show={showPrev}
                        setShow={setShowPrev}
                        status={status}
                        date={date}
                    />
                    <div className="mt-5 text-center">
                        <Grid
                            date={date}
                            status={status}
                            setShowResult={setShowResult}
                            mode={mode}
                            setMode={setMode}
                            selected={selected}
                            setSelected={setSelected}
                            setShowHelp={setShowHelp}
                            setShowPrev={setShowPrev}
                            score={score}
                            updateScore={updateScore}
                        />
                        <Search
                            date={date}
                            status={status}
                            setStatus={setStatus}
                            setMode={setMode}
                            mode={mode}
                            selected={selected}
                            setSelected={setSelected}
                        />
                    </div>
                </>
            )}
        </>
    );
}

export default Kbobingo;
