import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import Form from "react-bootstrap/Form";
import InputGroup from "react-bootstrap/InputGroup";
import React, { useRef, useEffect, useState } from "react";
import ResultSpinner from "./ResultSpinner.jsx";
import { PlayerImg, PlayerName } from "./Player.jsx";
import axios from "axios";
import { Base64 } from "js-base64";

const SearchAnswer = (props) => {
    const [searchList, setSearchList] = useState([]);
    const [debounceTimer, setDebounceTimer] = useState(null);
    const [inputValue, setInputValue] = useState("");

    const cancelTokenRef = useRef(null);

    const onSearch = (event) => {
        props.setMode("search");
        const keyword = event.target.value.trim();

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
    const playerSearch = (keyword) => {
        axios
            .post(
                "/api/kbodle/get_player_list.php",
                { keyword: keyword, type: "custom" },
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

    let placeholder = "출제할 선수를 골라주세요!";

    return (
        <div
            className="search-bar"
            id="search-bar"
            style={props.mode.startsWith("search") ? { zIndex: "1050" } : {}}
        >
            <div className="search-container">
                <i className="bi bi-search"></i>
                <input
                    id="input-player-search"
                    type="text"
                    className="form-control"
                    autoComplete="off"
                    spellCheck="false"
                    placeholder={placeholder}
                    onKeyUp={onSearch}
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                ></input>
            </div>
            {props.mode === "search-ing" && <ResultSpinner />}
            {searchList && props.mode === "search" && (
                <SearchResult lists={searchList} {...props} />
            )}
        </div>
    );
};

const SearchResult = (props) => {
    const selectPlayer = (player) => {
        props.setAnswer(player);
        props.setMode("main");
    };

    const lis = [];
    const legend = ["Team", "Pos", "Pit", "Bat", "Age", "HS", "Draft"];
    for (let i = 0; i < props.lists.length; i++) {
        let player = props.lists[i];
        let divs = [];
        for (let j = 0; j < legend.length; j++) {
            let divClass = "blank-div font-black";
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
                onClick={() => selectPlayer(player)}
                onKeyDown={(event) =>
                    event.key === "Enter" || event.key === " "
                        ? selectPlayer(player)
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
            <ul className="p-0">{lis}</ul>
        </div>
    );
    return lis.length > 0 && resultList;
};

const CustomTable = (props) => {
    const { answer, maker, setMaker } = props;
    const [div, setDiv] = useState([]);

    const legend = ["Team", "Pos", "Pit", "Bat", "Age", "HS", "Draft"];
    useEffect(() => {
        const newDiv = [];
        for (let i = 0; i < legend.length; i++) {
            let classList =
                Object.keys(answer).length === 0 ? "blank-div" : "green-div";
            newDiv.push(
                <div key={i} className={`legend-div ${classList}`}>
                    {i === 5
                        ? answer[legend[i]]?.slice(0, 4)
                        : answer[legend[i]]}
                </div>,
            );
        }
        setDiv(newDiv);
    }, [answer]);
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
            <div className="py-2">
                <PlayerName
                    team={answer.Team}
                    name={answer.Name}
                    img={answer.SporkId}
                    hand={
                        answer.Pos === "선발" || answer.Pos === "구원"
                            ? answer.Pit
                            : answer.Bat
                    }
                    pos={answer.Pos}
                />
                <div className="flex justify-center font-family-NaSqNe">
                    {div}
                </div>
            </div>
            <div className="py-3">
                <div className="flex justify-center font-family-NaSqNe">
                    <InputGroup className="mb-3">
                        <InputGroup.Text
                            id="maker"
                            style={{
                                borderTopLeftRadius: "10px",
                                borderBottomLeftRadius: "10px",
                            }}
                        >
                            제작자
                        </InputGroup.Text>
                        <Form.Control
                            placeholder="닉네임 입력"
                            aria-label="닉네임 입력"
                            aria-describedby="maker"
                            value={maker}
                            onChange={(e) =>
                                !/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(
                                    e.target.value,
                                )
                                    ? setMaker(e.target.value)
                                    : null
                            }
                            spellCheck={false}
                            style={{
                                borderTopRightRadius: "10px",
                                borderBottomRightRadius: "10px",
                            }}
                        />
                    </InputGroup>
                </div>
            </div>
        </>
    );
};

const MakeKbodle = (props) => {
    const { show, setShow, answer, maker } = props;
    const handleClose = () => {
        setShow && setShow(false);
        setCopied(false);
    };
    const [copied, setCopied] = useState(false);

    const teamCode = (teamName) => {
        if (teamName === "SSG") return "ssg";
        else if (teamName === "삼성") return "sam";
        else if (teamName === "KT") return "kt";
        else if (teamName === "NC") return "nc";
        else if (teamName === "두산") return "doo";
        else if (teamName === "LG") return "lg";
        else if (teamName === "한화") return "han";
        else if (teamName === "KIA") return "kia";
        else if (teamName === "키움") return "kiw";
        else if (teamName === "롯데") return "lot";
        else return teamName;
    };

    const teamFull = (teamName) => {
        if (teamName === "SSG") return "SSG 랜더스";
        else if (teamName === "삼성") return "삼성 라이온즈";
        else if (teamName === "KT") return "KT 위즈";
        else if (teamName === "NC") return "NC 다이노스";
        else if (teamName === "두산") return "두산 베어스";
        else if (teamName === "LG") return "LG 트윈스";
        else if (teamName === "한화") return "한화 이글스";
        else if (teamName === "KIA") return "KIA 타이거즈";
        else if (teamName === "키움") return "키움 히어로즈";
        else if (teamName === "롯데") return "롯데 자이언츠";
        else return teamName;
    };

    return (
        <>
            <Modal
                show={show}
                onHide={handleClose}
                centered
                className="font-family-NaSqNe"
                scrollable
            >
                <Modal.Header style={{ border: "none" }} closeButton>
                    <span className="font-family-kbo fs-5 font-bold">
                        KBODLE: 크보들
                    </span>
                    <span className="font-family-kbo mx-2"></span>
                </Modal.Header>

                <Modal.Body>
                    <span className="font-family-kbo">
                        ⚾ 나만의 크보들 - {maker}
                    </span>
                    <div>
                        <div className="m-0 p-0">
                            <div
                                className={
                                    "my-2 modal-player bg-" +
                                    teamCode(answer.Team)
                                }
                            >
                                <div className="modal-player-img">
                                    {
                                        <PlayerImg
                                            img={answer.SporkId}
                                            name={answer.Name}
                                            className=""
                                        />
                                    }
                                </div>
                                <div className="modal-player-text font-family-kbo">
                                    <div className="mb-2">
                                        <img
                                            src={
                                                answer.Team &&
                                                require(
                                                    `../../../assets/images/logos/${teamCode(
                                                        answer.Team,
                                                    )}-logo.svg`,
                                                )
                                            }
                                            alt={answer.Team}
                                            className="h-6 inline mr-1"
                                        ></img>
                                        <span>{teamFull(answer.Team)}</span>
                                    </div>
                                    <span>No.{answer.BackNo}</span>{" "}
                                    <span>{answer.Name}</span>{" "}
                                    <a
                                        href={
                                            "https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId=" +
                                            answer.SporkId
                                        }
                                        target="_blank"
                                        title="KBO 기록실"
                                        className="text-blue-600 underline underline-offset-2"
                                    >
                                        #
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="callout callout-info mb-0">
                        {!copied && (
                            <span className="fs-sm">
                                [TIP] 링크를 공유하면 내가 만든 크보들을 다른
                                사람들이 풀 수 있어요 😋
                            </span>
                        )}
                        {copied && (
                            <span className="fs-sm">
                                링크가 복사되었어요! 복사한 링크를 공유해봐요 😋
                            </span>
                        )}
                    </div>
                </Modal.Body>
                <Modal.Footer
                    style={{ border: "none" }}
                    className="flex justify-evenly"
                >
                    <Button
                        variant="primary"
                        className="font-family-NaSqNe font-bold px-5"
                        onClick={() => {
                            const text = answer.SporkId + "_" + maker;
                            const url =
                                "https://wesiper.xyz/kbodle/?code=" +
                                Base64.encode(text, true);
                            navigator.clipboard.writeText(url).then(() => {
                                console.log("클립보드에 복사되었어요.");
                                setCopied(true);
                            });
                        }}
                    >
                        링크 복사하기
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
};
/*
navigator.clipboard.writeText("https://wesiper.xyz/kbodle/?code=" + window.btoa(JSON.stringify({ID: answer.ID, Name: answer.Name, Birth: answer.Birth, Maker: maker}))).then(() => {
    console.log('클립보드에 복사되었어요.');
});
*/
const CustomKbodle = (props) => {
    const [answer, setAnswer] = useState({});
    const [showResult, setShowResult] = useState(false);
    const [maker, setMaker] = useState("");

    return (
        <>
            <SearchAnswer {...props} answer={answer} setAnswer={setAnswer} />
            <CustomTable answer={answer} maker={maker} setMaker={setMaker} />
            <Button
                variant="primary"
                className="font-family-NaSqNe font-bold mb-3 px-5"
                onClick={() => setShowResult(true)}
                disabled={!(answer.SporkId && maker != "")}
            >
                나만의 크보들 만들기
            </Button>
            <MakeKbodle
                show={showResult}
                setShow={setShowResult}
                answer={answer}
                maker={maker}
            />
        </>
    );
};

export default CustomKbodle;
