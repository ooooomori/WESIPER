import "./kbodle.css";
import React, { useRef, useState, useEffect } from "react";
import axios from "axios";
import ResultModal from "./components/ResultModal.jsx";
import HelpModal from "./components/HelpModal.jsx";
import Playerlist from "./components/Playerlist.jsx";
import StatModal from "./components/StatModal.jsx";
import Button from "react-bootstrap/Button";
import ToggleButton from "react-bootstrap/ToggleButton";
import ButtonGroup from "react-bootstrap/ButtonGroup";
import { PlayerImg, PlayerName } from "./components/Player.jsx";
import RealisticModule from "react-canvas-confetti/dist/presets/realistic";
import CustomKbodle from "./components/CustomKbodle.jsx";
import ResultSpinner from "./components/ResultSpinner.jsx";
import { useSearchParams } from "react-router-dom";
import { Base64 } from "js-base64";

// CommonJS 프리셋이 빌드 환경에 따라 { default: Component }로 반환될 수 있다.
const Realistic = RealisticModule.default ?? RealisticModule;

const HeaderBtn = ({
    custom,
    setShowHelp,
    setShowStat,
    kbodleMode,
    setKbodleMode,
}) => {
    const [buttonList, setButtonList] = useState([]);
    const buttons = [
        {
            title: "도움말",
            icon: "bi-question-circle",
            onClick: () => {
                setShowHelp(true);
            },
        },
        {
            title: "통계",
            icon: "bi-bar-chart",
            onClick: () => {
                if (custom === "") {
                    setShowStat(true);
                } else {
                    alert("커스텀 크보들에서는 통계가 지원되지 않아요.");
                }
            },
        },
    ];
    //{title: "문의하기", icon: "bi-envelope",  onClick: () => window.location.href='mailto:godmascotvic@gmail.com'}

    useEffect(() => {
        if (kbodleMode === "make-kbodle" && buttons.length === 2) {
            buttons.unshift({
                title: "메인으로",
                icon: "bi-puzzle",
                onClick: () => setKbodleMode("default"),
            });
        } else if (kbodleMode === "default" && buttons.length === 2) {
            buttons.unshift({
                title: "나만의 크보들 만들기",
                icon: "bi-nut",
                onClick: () => setKbodleMode("make-kbodle"),
            });
        }
        setButtonList(
            buttons.map((x, index) => (
                <button
                    key={index}
                    type="button"
                    className="btn-top text-2xl"
                    title={x.title}
                    onClick={x.onClick}
                >
                    <i className={"bi " + x.icon}></i>
                </button>
            )),
        );
    }, [kbodleMode]);

    return (
        <div id="top-btn-group" className="flex justify-center items-center">
            {buttonList}
        </div>
    );
};
const Header = ({
    index,
    custom,
    showHelp,
    setShowHelp,
    showStat,
    setShowStat,
    kbodleMode,
    setKbodleMode,
}) => {
    const [subTitle, setSubTitle] = useState("");
    useEffect(() => {
        if (kbodleMode === "default") {
            if (custom !== "") {
                setSubTitle(`크보들 - ${custom}`);
            } else {
                setSubTitle(`오늘의 야구선수 맞추기 #${index}`);
            }
        } else if (kbodleMode === "make-kbodle") {
            setSubTitle("나만의 크보들 만들기");
        }
    }, [kbodleMode, index, custom]);
    return (
        <>
            <HelpModal show={showHelp} setShow={setShowHelp} />
            <StatModal show={showStat} setShow={setShowStat} />
            <div className="flex justify-evenly mt-5">
                <div>
                    <span className="font-family-kbo font-bold text-2xl">
                        KBODLE: 크보들
                    </span>
                    <br />
                    <span className="font-family-kbo text-gray-600">
                        {subTitle}
                    </span>
                </div>
                <HeaderBtn
                    showHelp={showHelp}
                    setShowHelp={setShowHelp}
                    setShowStat={setShowStat}
                    kbodleMode={kbodleMode}
                    setKbodleMode={setKbodleMode}
                    custom={custom}
                />
            </div>
        </>
    );
};

const Search = (props) => {
    const [searchList, setSearchList] = useState([]);
    const [debounceTimer, setDebounceTimer] = useState(null);
    const [inputValue, setInputValue] = useState("");
    const [randomLoading, setRandomLoading] = useState(false);
    const randomPending = useRef(false);

    const cancelTokenRef = useRef(null);

    const onSearch = (event) => {
        if (randomPending.current || props.mode.startsWith("finished")) return;
        const keyword = event.target.value.trim();
        props.setMode("search");
        clearTimeout(debounceTimer);
        cancelTokenRef.current?.cancel();
        // 두 글자 이상 또는 외자 이름 '홀'만 검색
        if (keyword.length >= 2 || keyword === "홀") {
            props.setMode("search-ing");
            // 디바운싱을 적용하여 일정 시간 후에 검색을 실행
            const newDebounceTimer = setTimeout(() => {
                if (cancelTokenRef.current !== null) {
                    cancelTokenRef.current.cancel();
                }
                cancelTokenRef.current = axios.CancelToken.source();
                playerSearch(keyword);
            }, 500); // 500ms 디바운싱 지연 시간
            setDebounceTimer(newDebounceTimer);
        } else {
            setSearchList([]);
        }
    };

    const selectPlayer = (player, firstOnly = false) => {
        props.setStatus((previous) => {
            if (previous.game.isFinished || (firstOnly && previous.game.count !== 0)) return previous;
            const board = [...previous.game.board];
            board[previous.game.count] = player;
            return { ...previous, game: { ...previous.game, board, count: previous.game.count + 1 } };
        });
        setInputValue("");
        setSearchList([]);
        props.setMode("main");
    };

    const randomStart = async () => {
        if (randomPending.current || props.status.game.count !== 0) return;
        randomPending.current = true;
        setRandomLoading(true);
        clearTimeout(debounceTimer);
        cancelTokenRef.current?.cancel();
        setSearchList([]);
        props.setMode("main");
        try {
            const { data } = await axios.post("/api/kbodle/get_random_player.php", {
                answer_id: props.status.game.answer.SporkId,
            });
            if (!data.success || !data.player || String(data.player.SporkId) === String(props.status.game.answer.SporkId)) {
                throw new Error("랜덤 선수를 불러오지 못했어요. 다시 시도해주세요.");
            }
            selectPlayer(data.player, true);
        } catch (error) {
            alert("랜덤 선수를 불러오지 못했어요. 다시 시도해주세요.");
        } finally {
            randomPending.current = false;
            setRandomLoading(false);
        }
    };

    const canRandomStart = props.status.game.count === 0 && !props.status.game.isFinished && props.kbodleMode !== "make-kbodle";

    const playerSearch = (keyword) => {
        axios
            .post(
                "/api/kbodle/get_player_list.php",
                { keyword: keyword },
                { cancelToken: cancelTokenRef.current.token },
            )
            .then((response) => {
                const result = response.data;
                if (result.success) {
                    props.setMode("search");
                    setSearchList(result.list);
                }
            })
            .catch((error) => {
                console.error("Error fetching player list:", error);
            });
    };

    let placeholder = "오늘의 선수는 누구일까요?";
    if (props.status.game.count !== 0)
        placeholder += ` (${props.status.game.count}/9)`;
    if (props.mode === "finished")
        placeholder = props.status.game.count + "번 만에 성공!";
    else if (props.mode === "finished-fail")
        placeholder = "내일 다시 도전해요!";

    return (
        <div
            className="search-bar"
            id="search-bar"
            style={props.mode.startsWith("search") ? { zIndex: "1050" } : {}}
        >
            <div className={`search-container${canRandomStart ? " has-random-start" : ""}`}>
                <i className="bi bi-search"></i>
                <input
                    id="input-player-search"
                    type="text"
                    className="form-control font-family-NaSqNe"
                    autoComplete="off"
                    spellCheck="false"
                    disabled={randomLoading}
                    placeholder={placeholder}
                    onKeyUp={onSearch}
                    value={inputValue}
                    onChange={(e) => {
                        setInputValue(e.target.value);
                    }}
                    readOnly={
                        props.mode.startsWith("finished") &&
                        props.kbodleMode != "make-kbodle"
                    }
                ></input>
                {canRandomStart && (
                    <button type="button" className="random-start-btn font-family-NaSqNe" onClick={randomStart} disabled={randomLoading}>
                        {randomLoading ? "뽑는 중…" : "랜덤 시작"}
                    </button>
                )}
            </div>
            {props.mode === "search-ing" && <ResultSpinner />}
            {searchList && props.mode === "search" && (
                <SearchResult
                    lists={searchList}
                    greened={props.greened}
                    status={props.status}
                    setStatus={props.setStatus}
                    mode={props.mode}
                    setMode={props.setMode}
                    setInputValue={setInputValue}
                    onSelectPlayer={selectPlayer}
                />
            )}
        </div>
    );
};

const SearchResult = (props) => {
    const selectPlayer = (index) => {
        props.onSelectPlayer(props.lists[index]);
    };

    const lis = [];
    const legend = ["Team", "Pos", "Pit", "Bat", "Age", "HS", "Draft"];
    for (let i = 0; i < props.lists.length; i++) {
        let player = props.lists[i];
        let divs = [];
        for (let j = 0; j < legend.length; j++) {
            let divClass = null;
            if (props.greened[j] !== player[legend[j]]) {
                divClass = "blank-div font-black";
            } else {
                divClass = "green-div";
            }
            divs.push(
                <div key={legend[j]} className={"legend-div " + divClass}>
                    <span key={legend[j]}>
                        {j === 5
                            ? player[legend[j]]?.slice(0, 4)
                            : player[legend[j]]}
                    </span>
                </div>,
            );
        }
        lis.push(
            <li
                key={i}
                className="my-1 list-none py-2 cursor-pointer hover:bg-gray-200"
                onClick={() => selectPlayer(i)}
                onKeyDown={(event) =>
                    event.key === "Enter" || event.key === " "
                        ? selectPlayer(i)
                        : ""
                }
                tabIndex="0"
            >
                <PlayerName
                    team={player.Team}
                    name={player.Name}
                    img={player.SporkId}
                    hand={
                        player.Pos === "선발" || player.Pos === "구원"
                            ? player.Pit
                            : player.Bat
                    }
                    pos={player.Pos}
                />
                <div
                    key={"list-div-" + i}
                    className="flex justify-center font-family-NaSqNe mx-2"
                >
                    {divs}
                </div>
            </li>,
        );
    }

    const resultList = (
        <div id="result" className="mt-5">
            <ul className="">{lis}</ul>
        </div>
    );
    return lis.length > 0 && resultList;
};

const KbodleTable = (props) => {
    const lis = [];
    for (let i = 0; i < 9; i++) {
        let isFlip =
            (i < 8 &&
                props.status.game.board[i + 1] === null &&
                props.status.game.board[i] !== null) ||
            i === 8;
        lis.push(
            <KbodleTableAnswer
                key={i}
                data-row={i}
                answer={props.status.game.answer}
                board={props.status.game.board[i]}
                status={props.status}
                setStatus={props.setStatus}
                flip={isFlip}
                tiles={props.tiles}
                setTiles={props.setTiles}
                hideText={props.hideText}
                custom={props.custom}
            />,
        );
    }
    return (
        <>
            <div
                className="flex justify-center font-family-NaSqNe mb-1"
                style={{ marginTop: "6rem" }}
            >
                <div key="Team" className="legend-div">
                    팀
                </div>
                <div key="Pos" className="legend-div">
                    포지션
                </div>
                <div key="Bat" className="legend-div">
                    투
                </div>
                <div key="Pit" className="legend-div">
                    타
                </div>
                <div key="Age" className="legend-div">
                    나이
                </div>
                <div key="HS" className="legend-div">
                    출신고
                </div>
                <div key="Draft" className="legend-div">
                    드래프트
                </div>
            </div>
            <ul
                className={
                    "list-unstyled" + (props.hideText ? " hide-text" : "")
                }
            >
                {lis}
            </ul>
        </>
    );
};

const KbodleTableAnswer = (props) => {
    const {
        flip,
        status,
        setStatus,
        tiles,
        setTiles,
        answer,
        board,
        hideText,
        custom,
    } = props;
    const legend = ["Team", "Pos", "Pit", "Bat", "Age", "HS", "Draft"];
    const [divList, setDivList] = useState(
        Array.from({ length: legend.length }, (_, i) => (
            <div key={i} className="legend-div blank-div"></div>
        )),
    );
    const [classList, setClassList] = useState([]);
    const [greened, setGreened] = useState(status.game.greened);
    const [index, setIndex] = useState(-1);
    const currentRow = props["data-row"];

    const compareDraft = (a, b) => {
        return (
            a.slice(-3).trim() === b.slice(-3).trim() ||
            (a.match(/(\d+)(?=R)/) &&
                b.match(/(\d+)(?=R)/) &&
                Math.abs(a.match(/(\d+)(?=R)/)[1] - b.match(/(\d+)(?=R)/)[1]) <=
                    2 &&
                a.split(" ").length === b.split(" ").length) ||
            (a === "부상 대체" && b === "자유선발") ||
            (a === "자유선발" && b === "부상 대체")
        );
    };

    const compareLeague = (a, b) => {
        const dream = ["두산", "SSG", "KT", "삼성", "롯데"];
        const nanum = ["NC", "한화", "KIA", "LG", "키움"];
        return (
            (dream.includes(a) && dream.includes(b)) ||
            (nanum.includes(a) && nanum.includes(b))
        );
    };

    // 클래스를 조합하여 반환하는 함수
    const getClass = (i) => {
        if (answer[legend[i]] === props.board[legend[i]]) {
            setGreened((prevGreened) => {
                const updatedGreened = [...prevGreened];
                updatedGreened[i] = answer[legend[i]];
                return updatedGreened;
            });
            setTiles((prevTiles) => {
                const updatedTiles = [...prevTiles];
                if (updatedTiles[currentRow].length < 7)
                    updatedTiles[currentRow].push("🟩");
                return updatedTiles;
            });
            return "green-div";
        } else if (
            (i === 0 && compareLeague(answer.Team, board.Team)) ||
            (i === 1 && answer.SubPos.includes(board.Pos)) ||
            (i === 2 && answer.Pit[0] === board.Pit[0]) ||
            (i === 3 && answer.Bat === "양타") ||
            (i === 4 && Math.abs(answer.Age - board.Age) <= 2) ||
            (i === 5 && answer.HSLoc === board.HSLoc) ||
            (i === 6 && compareDraft(answer.Draft, board.Draft))
        ) {
            setTiles((prevTiles) => {
                const updatedTiles = [...prevTiles];
                if (updatedTiles[currentRow].length < 7)
                    updatedTiles[currentRow].push("🟨");
                return updatedTiles;
            });
            return "orange-div";
        } else {
            setTiles((prevTiles) => {
                const updatedTiles = [...prevTiles];
                if (updatedTiles[currentRow].length < 7)
                    updatedTiles[currentRow].push("⬜️");
                return updatedTiles;
            });
            return "out-div";
        }
    };

    // 플레이어 이름 생성
    const playerName = board && !hideText && (
        <PlayerName
            team={board.Team}
            name={board.Name}
            img={board.SporkId}
            hand={
                board.Pos === "선발" || board.Pos === "구원"
                    ? board.Pit
                    : board.Bat
            }
            pos={board.Pos}
        />
    );

    // divList 업데이트 함수
    const updateDivList = (index, classList) => {
        const updatedDiv = [...divList];
        if (index === 7) {
            updatedDiv.forEach((x, idx) => {
                updatedDiv[idx] = (
                    <div
                        key={x.key}
                        className={`legend-div ${classList[idx].split(" ")[0]}`}
                    >
                        {x.props.children}
                    </div>
                );
            });
        } else {
            updatedDiv[index] = (
                <div key={index} className={`legend-div ${classList[index]}`}>
                    {index === 5
                        ? board[legend[index]]?.slice(0, 4)
                        : board[legend[index]]}
                </div>
            );
        }

        setDivList(updatedDiv);
    };

    // flip 상태 변경에 따른 부수 효과 처리
    useEffect(() => {
        let timerId;

        if (flip && board !== null && index < 7) {
            const newClassList = [...classList];
            for (let i = 0; i < legend.length; i++) {
                newClassList[i] = getClass(i) + " div-flip";
            }
            if (!props.tiles[currentRow].length) {
                props.setTiles(tiles);
            }
            setClassList(newClassList);
            timerId = setInterval(() => {
                setIndex((prevIndex) => {
                    const newIndex = prevIndex + 1;
                    if (newIndex >= legend.length) {
                        clearInterval(timerId);
                    }
                    return newIndex;
                });
            }, 500);
        } else if (!props.flip && props.board !== null) {
            const updatedDiv = [...divList];
            for (let i = 0; i < legend.length; i++) {
                updatedDiv[i] = (
                    <div key={i} className={`legend-div ${getClass(i)}`}>
                        {i === 5
                            ? board[legend[i]]?.slice(0, 4)
                            : board[legend[i]]}
                    </div>
                );
            }
            setDivList(updatedDiv);
            if (!props.tiles[currentRow].length) {
                props.setTiles(tiles);
            }
        }

        return () => clearInterval(timerId);
    }, [flip, board]);

    // 인덱스 변경에 따른 divList 업데이트
    useEffect(() => {
        if (props.board !== null && props.flip) {
            updateDivList(index, classList);
        }
    }, [index]);

    // flip 상태 변경에 따른 상태 업데이트
    useEffect(() => {
        if (flip) {
            let newStatus = { ...status };
            newStatus.game.greened = greened;
            setStatus(newStatus);
            if (custom === "")
                localStorage.setItem("kbodle", JSON.stringify(newStatus));
            else
                localStorage.setItem(
                    "kbodle_" + custom,
                    JSON.stringify(newStatus),
                );
        }
    }, [flip, greened]);

    return (
        <li className={board ? "py-2" : "py-1"}>
            {playerName}
            <div className="flex justify-center font-family-NaSqNe">
                {divList}
            </div>
        </li>
    );
};

const Overlay = ({ onClick }) => {
    return <div className="overlay" id="overlay" onClick={onClick}></div>;
};

function Kbodle() {
    const [mode, setMode] = useState("main");
    const [kbodleMode, setKbodleMode] = useState("default");
    const [status, setStatus] = useState({});
    const [showHelp, setShowHelp] = useState(false);
    const [showStat, setShowStat] = useState(false);
    const [showResult, setShowResult] = useState(false);
    const [showPlayerlist, setShowPlayerlist] = useState(false);
    const [hideText, setHideText] = useState(false);
    const [tiles, setTiles] = useState([[], [], [], [], [], [], [], [], []]);
    const [searchParams, setSearchParams] = useSearchParams();
    const [custom, setCustom] = useState("");
    const code = searchParams.get("code");

    const getYmd = (isToday) => {
        let currentDate = new Date();
        if (!isToday) currentDate.setDate(currentDate.getDate() - 1);
        // 연, 월, 일을 가져오기
        let year = currentDate.getFullYear();
        let month = String(currentDate.getMonth() + 1).padStart(2, "0"); // 월은 0부터 시작하므로 +1, 두 자릿수로 표시
        let day = String(currentDate.getDate()).padStart(2, "0"); // 두 자릿수로 표시

        // YYYYMMDD 형태로 조합
        return `${year}-${month}-${day}`;
    };

    const checkCustom = (code) => {
        const customStr = Base64.decode(code);
        let underbar = 0;
        for (let i = 0; i < customStr.length; i++) {
            if (customStr[i] === "_") {
                underbar++;
            }
        }

        if (underbar !== 1) return false;
        const custom = customStr.split("_");
        let newStatus = JSON.parse(localStorage.getItem("kbodle_" + custom[1]));
        if (
            newStatus === null ||
            newStatus.game.answer.SporkId !== Number(custom[0])
        ) {
            setShowHelp(true);
            newStatus = {
                date: getYmd(true),
                game: {
                    count: -1,
                    isFinished: false,
                    answer: {
                        ID: null,
                    },
                },
            };
            axios
                .post("/api/kbodle/get_custom_kbodle.php", { p_no: custom[0] })
                .then((response) => {
                    const result = response.data;
                    if (result.success) {
                        newStatus["game"]["answer"] = result;
                        newStatus["game"]["count"] = 0;
                        newStatus["game"]["board"] = [
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                        ];
                        newStatus["game"]["greened"] = [
                            false,
                            false,
                            false,
                            false,
                            false,
                            false,
                            false,
                        ];
                        newStatus.game.isFinished = false;

                        localStorage.setItem(
                            "kbodle_" + custom[1],
                            JSON.stringify(newStatus),
                        );

                        setStatus(newStatus);

                        setCustom(custom[1]);
                    } else {
                        alert(result.error);
                    }
                })
                .catch((error) => {
                    console.error("Error fetching player list:", error);
                });
        } else {
            setStatus(newStatus);
            setCustom(custom[1]);
        }
    };
    useEffect(() => {
        if (code !== null) {
            checkCustom(code);
        } else {
            let newStatus = JSON.parse(localStorage.getItem("kbodle"));
            const nowDate = getYmd(true);
            if (newStatus === null) {
                newStatus = {
                    date: nowDate,
                    game: {
                        count: -1,
                        isFinished: false,
                        answer: {
                            ID: null,
                        },
                    },
                };
            }
            let stats = JSON.parse(localStorage.getItem("kbodle-stats"));
            if (stats === null) {
                stats = {
                    playStreak: 0,
                    winStreak: 0,
                    played: 0,
                    lastPlayed: null,
                    lastWon: null,
                    record: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                };
                localStorage.setItem("kbodle-stats", JSON.stringify(stats));
            }

            if (
                newStatus["date"] !== nowDate ||
                newStatus["game"]["count"] === -1 ||
                newStatus["game"]["answer"]["error"] === "데이터 없음"
            ) {
                setShowResult(false);
                setShowHelp(true);
                newStatus["date"] = nowDate;
                newStatus["game"]["count"] = 0;
                newStatus["game"]["board"] = [
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                ];
                newStatus["game"]["greened"] = [
                    false,
                    false,
                    false,
                    false,
                    false,
                    false,
                    false,
                ];
                newStatus.game.isFinished = false;

                if (stats["lastPlayed"] !== getYmd(false)) {
                    stats.winStreak = 0;
                    stats["playStreak"] = 1;
                } else stats["playStreak"]++;
                stats["lastPlayed"] = getYmd(true);

                stats["played"]++;
                axios
                    .post("/api/kbodle/get_today_kbodle.php")
                    .then((response) => {
                        const result = response.data;
                        if (result.success) {
                            newStatus["game"]["answer"] = result;
                            localStorage.setItem(
                                "kbodle",
                                JSON.stringify(newStatus),
                            );
                            localStorage.setItem(
                                "kbodle-stats",
                                JSON.stringify(stats),
                            );
                            setStatus(newStatus);
                        } else {
                            alert(result.error);
                        }
                    })
                    .catch((error) => {
                        console.error("Error fetching player list:", error);
                    });
            } else {
                localStorage.setItem("kbodle", JSON.stringify(newStatus));
                setStatus(newStatus);
            }
        }
    }, []);

    useEffect(() => {
        if (
            status.game &&
            status.game.count > 0 &&
            status.game.answer.SporkId ==
                status.game.board[status.game.count - 1].SporkId
        ) {
            const newStatus = { ...status };
            if (!newStatus.game.isFinished) {
                if (custom === "") {
                    let stats = JSON.parse(
                        localStorage.getItem("kbodle-stats"),
                    );
                    stats.winStreak++;
                    stats.record[newStatus.game.count]++;
                    stats.lastWon = getYmd(true);
                    localStorage.setItem("kbodle-stats", JSON.stringify(stats));
                }

                newStatus.game.isFinished = true;

                if (custom === "")
                    localStorage.setItem("kbodle", JSON.stringify(newStatus));
                else
                    localStorage.setItem(
                        "kbodle_" + custom,
                        JSON.stringify(newStatus),
                    );

                setStatus(newStatus);
            }

            setMode("finished");

            const timeout = setTimeout(() => {
                setShowResult(true);
            }, 500 * 11);

            return () => clearTimeout(timeout);
        } else if (
            status.game &&
            status.game.count === 9 &&
            status.game.answer.SporkId !=
                status.game.board[status.game.count - 1].SporkId
        ) {
            const newStatus = { ...status };
            if (!newStatus.game.isFinished) {
                if (custom === "") {
                    let stats = JSON.parse(
                        localStorage.getItem("kbodle-stats"),
                    );
                    stats.record[0]++;
                    stats.winStreak = 0;
                    localStorage.setItem("kbodle-stats", JSON.stringify(stats));
                }

                newStatus.game.isFinished = true;
                if (custom === "")
                    localStorage.setItem("kbodle", JSON.stringify(newStatus));
                else
                    localStorage.setItem(
                        "kbodle_" + custom,
                        JSON.stringify(newStatus),
                    );
                setStatus(newStatus);
            }

            setMode("finished-fail");

            const timeout = setTimeout(() => {
                setShowResult(true);
            }, 500 * 11);

            return () => clearTimeout(timeout);
        }
    }, [status]);

    const handleOverlayClick = () => {
        setMode("main");
    };

    return (
        <div className="text-center">
            <div id="kbodle">
                <Header
                    index={status.game?.answer.index}
                    custom={custom}
                    showHelp={showHelp}
                    setShowHelp={setShowHelp}
                    showStat={showStat}
                    setShowStat={setShowStat}
                    kbodleMode={kbodleMode}
                    setKbodleMode={setKbodleMode}
                />

                {status["game"] && kbodleMode !== "make-kbodle" && (
                    <Search
                        kbodleMode={kbodleMode}
                        mode={mode}
                        setMode={setMode}
                        status={status}
                        setStatus={setStatus}
                        greened={status["game"]["greened"]}
                    />
                )}

                {status["game"] && kbodleMode !== "make-kbodle" && (
                    <KbodleTable
                        kbodleMode={kbodleMode}
                        status={status}
                        setStatus={setStatus}
                        tiles={tiles}
                        setTiles={setTiles}
                        hideText={hideText}
                        custom={custom}
                    />
                )}

                {kbodleMode === "make-kbodle" && (
                    <CustomKbodle
                        mode={mode}
                        setMode={setMode}
                        kbodleMode={kbodleMode}
                    />
                )}

                {mode.startsWith("search") && (
                    <Overlay onClick={handleOverlayClick} />
                )}
                <ResultModal
                    custom={custom}
                    setKbodleMode={setKbodleMode}
                    show={showResult}
                    setShowResult={setShowResult}
                    status={status}
                    tiles={tiles}
                    kbodleMode={kbodleMode}
                />
                <Playerlist show={showPlayerlist} setShow={setShowPlayerlist} />
                {mode === "finished" && kbodleMode !== "make-kbodle" && (
                    <Realistic
                        autorun={{ speed: 0.2, duration: 1, delay: 500 * 8 }}
                    />
                )}
                {!mode.startsWith("finished") && (
                    <div className="flex justify-evenly items-center">
                        <Button
                            variant="outline-primary"
                            className="font-family-NaSqNe !font-bold my-3 !px-10 !text-sm"
                            //onClick={() => setShowPlayerlist(true)}
                            onClick={() => setShowPlayerlist(true)}
                        >
                            선수 목록 확인하기
                        </Button>
                    </div>
                )}
                {mode.startsWith("finished") &&
                    kbodleMode !== "make-kbodle" && (
                        <div className="flex justify-evenly items-center">
                            <Button
                                variant="primary"
                                className="font-family-NaSqNe !font-bold my-3 px-5"
                                onClick={() => setShowResult(true)}
                            >
                                결과 보기
                            </Button>
                            <ButtonGroup>
                                <ToggleButton
                                    id="hide-text-btn"
                                    variant="outline-info"
                                    type="checkbox"
                                    className="font-family-NaSqNe !font-bold my-3 px-2"
                                    checked={hideText}
                                    onChange={() => setHideText(!hideText)}
                                >
                                    텍스트 가리기
                                </ToggleButton>
                            </ButtonGroup>
                        </div>
                    )}
            </div>
        </div>
    );
}

export default Kbodle;
