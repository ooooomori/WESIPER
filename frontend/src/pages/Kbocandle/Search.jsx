import { useRef, useState, useEffect } from "react";
import axios from "axios";
import { Spinner } from "flowbite-react";
import PlayerList from "./PlayerList";

const Searchbar = ({ setSearchPlayer }) => {
    const [searchList, setSearchList] = useState([]);
    const [debounceTimer, setDebounceTimer] = useState(null);
    const [inputValue, setInputValue] = useState("");
    const [isSearching, setIsSearching] = useState(false);

    const searchAreaRef = useRef(null);
    const cancelTokenRef = useRef(null);

    useEffect(() => {
        const closeSearchList = (event) => {
            if (searchAreaRef.current?.contains(event.target)) return;

            clearTimeout(debounceTimer);
            cancelTokenRef.current?.cancel();
            cancelTokenRef.current = null;
            setSearchList([]);
            setIsSearching(false);
        };

        document.addEventListener("pointerdown", closeSearchList);
        document.addEventListener("focusin", closeSearchList);

        return () => {
            document.removeEventListener("pointerdown", closeSearchList);
            document.removeEventListener("focusin", closeSearchList);
        };
    }, [debounceTimer]);

    const onSearch = (event) => {
        const keyword = event.target.value;
        clearTimeout(debounceTimer);

        if (keyword.length >= 1) {
            const newDebounceTimer = setTimeout(() => {
                setIsSearching(true);
                setSearchList([]);

                if (cancelTokenRef.current !== null) {
                    cancelTokenRef.current.cancel();
                }
                cancelTokenRef.current = axios.CancelToken.source();

                playerSearch(keyword);
            }, 500);
            setDebounceTimer(newDebounceTimer);
        } else {
            setSearchList([]);
            setIsSearching(false);
        }
    };

    const playerSearch = (keyword) => {
        axios
            .post(
                "/api/kbocandle/get_player_list.php",
                { name: keyword },
                { cancelToken: cancelTokenRef.current.token },
            )
            .then((response) => {
                const result = response.data;
                setIsSearching(false);

                if (result.success !== false) {
                    setSearchList(result.list || result || []);
                } else {
                    alert(result.error || "검색 실패");
                }
            })
            .catch((error) => {
                if (!axios.isCancel(error)) {
                    setIsSearching(false);
                    console.error("Error fetching player list:", error);
                }
            });
    };

    // 선수 선택 시 실행될 깔끔한 콜백
    const handleSelect = (player) => {
        setSearchList([]);
        if (setSearchPlayer) {
            setSearchPlayer(player);
        }
    };

    return (
        <div
            ref={searchAreaRef}
            className="candle-player-search relative w-full max-w-md font-family-NaSqNe"
        >
            <div className="candle-player-search-box relative">
                <i className="bi bi-search candle-search-icon pointer-events-none absolute left-4 top-1/2 -translate-y-1/2"></i>

                <input
                    id="input-player-search"
                    type="text"
                    className="candle-player-search-input h-12 w-full border-0 bg-transparent pl-11 pr-12 focus:ring-0 text-base"
                    autoComplete="off"
                    spellCheck="false"
                    placeholder="선수명 입력"
                    onKeyUp={onSearch}
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                />

                {isSearching && (
                    <Spinner
                        size="md"
                        className="fill-blue-600 absolute right-4 top-1/4"
                    />
                )}
            </div>

            {searchList.length > 0 && (
                <div className="candle-search-results absolute left-0 right-0 top-full mt-2 z-50 overflow-hidden">
                    <ul className="max-h-72 overflow-y-auto py-1 text-sm">
                        {searchList.map((player, index) => (
                            <li
                                key={index}
                                className="cursor-pointer select-none px-4 py-2.5 flex items-center justify-between transition-colors"
                                onClick={() => handleSelect(player)}
                            >
                                <PlayerList player={player} />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
};

export default Searchbar;
