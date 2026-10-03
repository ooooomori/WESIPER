import { useRef, useState, useEffect } from "react";
import axios from "axios";
import { Spinner } from "flowbite-react";

// 입력이 멈춘 뒤 검색을 보내기까지 기다리는 시간. 한글 조합 중 불필요한 요청만 걸러낼 정도로 짧게 둔다.
const SEARCH_DEBOUNCE_MS = 150;
// 같은 검색어의 결과를 다시 쓰는 시간(지웠다 다시 치는 경우 즉시 표시)
const SEARCH_CACHE_MS = 10 * 60 * 1000;
const recentSearches = new Map();

const Search = (props) => {
    const [fadeClass, setFadeClass] = useState(false);
    const [fadeInput, setFadeInput] = useState("opacity-0");
    const [searchList, setSearchList] = useState([]);
    const [debounceTimer, setDebounceTimer] = useState(null);
    const [inputValue, setInputValue] = useState("");

    const cancelTokenRef = useRef(null);
    // 방향키·Shift처럼 글자가 바뀌지 않는 키 입력으로는 다시 검색하지 않는다.
    const lastKeyword = useRef("");

    const onSearch = (event) => {
        const keyword = event.target.value.trim();
        if (keyword === lastKeyword.current) return;
        lastKeyword.current = keyword;
        clearTimeout(debounceTimer);
        cancelTokenRef.current?.cancel();
        // 두 글자 이상 또는 외자 이름 '홀'·'필'·'얀'만 검색
        if (keyword.length >= 2 || ["홀", "필", "얀"].includes(keyword)) {
            // 조금 전에 받은 결과는 서버에 다시 묻지 않고 바로 보여준다.
            const cached = recentSearches.get(keyword);
            if (cached && Date.now() - cached.at < SEARCH_CACHE_MS) {
                props.setMode("search");
                setSearchList(cached.list);
                return;
            }
            // 디바운싱을 적용하여 일정 시간 후에 검색을 실행
            const newDebounceTimer = setTimeout(() => {
                props.setMode("searching");
                setSearchList([]);
                if (cancelTokenRef.current !== null) {
                    cancelTokenRef.current.cancel();
                }

                cancelTokenRef.current = axios.CancelToken.source();

                playerSearch(keyword);
            }, SEARCH_DEBOUNCE_MS);
            setDebounceTimer(newDebounceTimer);
        } else {
            setSearchList([]);
            props.setMode("search");
        }
    };

    const playerSearch = (keyword) => {
        axios
            .post(
                "/api/kbobingo/search.php",
                { keyword: keyword },
                { cancelToken: cancelTokenRef.current.token }
            )
            .then((response) => {
                const result = response.data;
                if (result.success) {
                    recentSearches.set(keyword, { list: result.list, at: Date.now() });
                    props.setMode("search");
                    setSearchList(result.list);
                } else {
                    alert(result.error);
                }
            })
            .catch((error) => {
                console.error("Error fetching player list:", error);
            });
    };

    useEffect(() => {
        if (props.mode === "search" && fadeClass === false) {
            setFadeClass("opacity-0");
            setFadeInput("opacity-0 scale-95");
            const timer = setTimeout(() => {
                setFadeClass("opacity-100");
                setFadeInput("scale-100");
            }, 100);

            return () => {
                clearTimeout(timer);
            };
        } else if (
            !props.mode.startsWith("search") &&
            fadeClass === "opacity-100"
        ) {
            if (cancelTokenRef.current != null) {
                cancelTokenRef.current.cancel();
            }
            setSearchList([]);
            setInputValue("");
            lastKeyword.current = "";
            props.setSelected(null);
            setFadeClass("opacity-0");
            setFadeInput("opacity-0 scale-95");
            const timer = setTimeout(() => {
                setFadeClass(false);
                setFadeInput("scale-80");
            }, 200);

            return () => {
                clearTimeout(timer);
            };
        }
    }, [props.mode]);
    return (
        fadeClass && props.mode.startsWith("search") && (
            <>
                <div
                    className={`fixed inset-0 bg-gray-900 bg-opacity-40 transition-opacity ease-in duration-200 ${fadeClass}`}
                ></div>
                <div
                    className={`fixed inset-0 z-10 overflow-y-auto p-4 sm:p-6 md:p-20 py-10 font-family-NaSqNe`}
                    onClick={() => props.setMode("main")}
                >
                    <div
                        className={`mx-auto max-w-md transform divide-y divide-gray-100 dark:divide-gray-800 overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black ring-opacity-5 transition-all ease-in duration-200 ${fadeInput}`}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="relative">
                            <i className="bi bi-search pointer-events-none absolute left-4 top-3.5 h-5 w-5 text-gray-400"></i>
                            <input
                                id="input-player-search"
                                type="text"
                                className="h-12 w-full border-0 bg-transparent pl-11 pr-9 text-gray-900 placeholder:text-gray-400 focus:ring-0 text-base"
                                autoComplete="off"
                                spellCheck="false"
                                placeholder="선수명 입력"
                                onKeyUp={onSearch}
                                value={inputValue}
                                autoFocus
                                onChange={(e) => {
                                    setInputValue(e.target.value);
                                }}
                            ></input>

                            {props.mode == "searching" && (
                                <Spinner
                                    size="md"
                                    className="fill-blue-600 absolute right-5 top-[0.8rem]"
                                />
                            )}
                        </div>
                        {searchList.length > 0 && (
                            <SearchResult
                                lists={searchList}
                                greened={props.greened}
                                date={props.date}
                                status={props.status}
                                setStatus={props.setStatus}
                                mode={props.mode}
                                setMode={props.setMode}
                                selected={props.selected}
                                setInputValue={setInputValue}
                            />
                        )}
                    </div>
                </div>
            </>
        )
    );
};

const SearchResult = (props) => {
    return (
        <ul className="max-h-72 scroll-py-2 overflow-y-auto py-1.5 text-sm text-gray-800 z-50 dark:bg-gray-700 border-t">
            {props.lists.map((e, index) => (
                <SearchResultDiv
                    key={index}
                    player={e}
                    selected={props.selected}
                    date={props.date}
                    status={props.status}
                    setStatus={props.setStatus}
                    setMode={props.setMode}
                />
            ))}
        </ul>
    );
};

const SearchResultDiv = (props) => {
    const { date, player, selected, status, setStatus, setMode } = props;
    const [list, setList] = useState(null);
    const row = Math.floor(selected / 3);
    const col = selected % 3;
    const checkAnswer = () => {
        const cond = [];
        cond.push(status[date].grid.row[row].split("-"));
        cond.push(status[date].grid.col[col].split("-"));

        const check = cond.every((e) => {
            if (e[0] === "team") {
                return (
                    player.Season.hasOwnProperty(e[1]) ||
                    player.Team?.includes(e[1])
                );
            } else if (e[0] === "award" || e[0] === "stat") {
                if (e[1].endsWith("season") || e[0] === "award") {
                    if (cond.some((other) => other[0] === "team")) {
                        const teamCode = cond.find(
                            (other) => other[0] === "team"
                        )[1];
                        return Boolean(player.Season?.[teamCode]?.[e[1]]);
                    } else {
                        return Object.values(player.Season).some(
                            (obj) => obj[e[1]]
                        );
                    }
                } else {
                    return player.Total[e[1]];
                }
            } else if (e[0] === "profile") {
                return player.Profile[e[1]];
            } else if (e[0] === "pos") {
                if (cond.some((other) => other[0] === "team")) {
                    return player.Season[
                        cond.find((other) => other[0] === "team")[1]
                    ].pos.includes(e[1].toUpperCase());
                } else {
                    return Object.values(player.Season).some((obj) =>
                        obj.pos.includes(e[1].toUpperCase())
                    );
                }
            }
        });

        const newStatus = { ...status };
        if (check) {
            setMode("main");
            axios
                .post("/api/kbobingo/pick_data.php", {
                    index: status[date].grid.index,
                    p_no: player.SporkId,
                    row: row,
                    col: col,
                    type: 1,
                })
                .then((response) => {
                    const result = response.data;
                    if (result.code === 200) {
                        const save = {
                            p_no: player.SporkId,
                            p_name: player.Name,
                            p_img: player.Img,
                        };
                        newStatus[date].correctAnswer[row][col] = save;
                        newStatus[date].chance--;
                        setStatus(newStatus);
                        localStorage.setItem(
                            "kbobingo",
                            JSON.stringify(newStatus)
                        );
                        if (status[date].chance === 0) setMode("finished");
                    } else {
                        alert(result.error);
                    }
                })
                .catch((error) => {
                    console.error("KBO BINGO 데이터 받아오기 실패:", error);
                });
        } else {
            newStatus[date].wrongAnswer[row][col] = player.SporkId;
            newStatus[date].chance--;
            setStatus(newStatus);
            localStorage.setItem("kbobingo", JSON.stringify(newStatus));
            if (status[date].chance === 0) setMode("finished");
        }
    };

    useEffect(() => {
        setList(
            <li
                className="cursor-default select-none px-4 py-2 flex justify-between items-center font-medium hover:bg-gray-100"
                tabIndex="-1"
            >
                <div className="flex flex-col gap-1 ml-1.5 md:ml-2.5">
                    <div className="text-start">
                        <span
                            className={`text-base font-bold ${
                                status[date].wrongAnswer[row][col] ===
                                player.SporkId
                                    ? "text-red-500"
                                    : ""
                            }`}
                        >
                            {player.Name}
                        </span>

                        {player.BackNo != null && String(player.BackNo).trim() !== "" && <span className="ml-2">No.{player.BackNo}</span>}
                    </div>
                    <div className="font-normal text-xs text-gray-500 dark:text-gray-400 -mt-1 text-start">
                        {/* 메인페이지 선수 검색 규칙 + 현역은 올해까지 (End = 올해), 모르는 값은 ? */}
                        {(player.Debut || player.End) && (
                            <>
                                {`${player.Debut || "?"}–${player.End || "?"}`}
                                <span className="mx-1 text-gray-300">·</span>
                            </>
                        )}
                        {player.Pos}
                    </div>
                </div>
                {status[date].wrongAnswer[row][col] === player.SporkId ? (
                    <></>
                ) : status[date].correctAnswer.some((subArray) =>
                      subArray.some((obj) => obj?.p_no === player.SporkId)
                  ) ? (
                    <span className="mr-1.5 md:mr-2.5 px-3 text-blue-500">
                        중복
                    </span>
                ) : (
                    <button
                        className="rounded-md bg-teal-400 px-3 py-1.5 mr-1.5 md:mr-2.5 text-sm font-medium text-white shadow-sm hover:bg-teal-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400"
                        onClick={() => checkAnswer()}
                    >
                        선택
                    </button>
                )}
            </li>
        );
    }, [player, status]);
    return list;
};

export default Search;
