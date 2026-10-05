import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import Form from "react-bootstrap/Form";
import axios from "axios";
import Collapse from "react-bootstrap/Collapse";
import React, { useEffect, useState } from "react";
import { PlayerImg } from "./Player.jsx";
import { TwitterShareButton, XIcon } from "react-share";
import { useNavigate } from "react-router-dom";
import { Base64 } from "js-base64";

const { Kakao } = window;
const kbodleUrl = "https://wesiper.xyz/kbodle";

const ResultSquare = (props) => {
    useEffect(() => {
        // init 해주기 전에 clean up 을 해준다.
        Kakao.cleanup();
        // 자신의 js 키를 넣어준다.
        Kakao.init("25dedcd63c24a5a75a9fae607290fd1f");
        // 잘 적용되면 true 를 뱉는다.
        Kakao.Share.createDefaultButton({
            container: "#kakaotalk-sharing-btn",
            objectType: "text",
            text: props.handleShare(null, "kakao"),
            link: {
                webUrl: kbodleUrl,
            },
            buttonTitle: "크보들 풀기",
        });
    }, [props]);

    const [copied, setCopied] = useState(false);
    useEffect(() => {
        if (!copied) return undefined;
        const timer = setTimeout(() => setCopied(false), 2000);
        return () => clearTimeout(timer);
    }, [copied]);

    return (
        <div className="kbodle-result-share">
            <div className="kbodle-result-tiles" aria-label="내 풀이 결과">
                {props.tiles
                    ?.filter((row) => row.length > 0)
                    .map((row, index) => (
                        <React.Fragment key={index}>
                            {row.join(" ")}
                            <br />
                        </React.Fragment>
                    ))}
            </div>
            <div className="kbodle-result-actions">
                <Button
                    variant="primary"
                    onClick={(event) => {
                        props.handleShare(event, "copy");
                        setCopied(true);
                    }}
                >
                    {copied ? "복사했어요" : "결과 복사하기"}
                </Button>
                <div className="kbodle-result-icons">
                    <TwitterShareButton
                        url={props.handleShare(null, "text")}
                        aria-label="X에 공유"
                    >
                        <XIcon size={32} round={true}></XIcon>
                    </TwitterShareButton>
                    <button
                        id="kakaotalk-sharing-btn"
                        type="button"
                        className="kbodle-result-icon"
                        aria-label="카카오톡 공유"
                    >
                        <img
                            src="https://developers.kakao.com/assets/img/about/logos/kakaotalksharing/kakaotalk_sharing_btn_medium.png"
                            alt=""
                        />
                    </button>
                    {typeof navigator !== "undefined" && navigator.canShare && (
                        <button
                            type="button"
                            className="kbodle-result-icon is-system"
                            aria-label="다른 앱으로 공유"
                            title="공유하기"
                            onClick={(event) => props.handleShare(event, "share")}
                        >
                            <i className="bi-box-arrow-up" aria-hidden="true"></i>
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
};

function CountdownTimer() {
    const calculateTimeLeft = () => {
        const now = new Date();
        const tomorrow = new Date(now);
        tomorrow.setDate(now.getDate() + 1);
        tomorrow.setHours(0, 0, 0, 0);

        const difference = tomorrow - now;
        let timeLeft = {};

        if (difference > 0) {
            timeLeft = {
                hours: Math.floor((difference / (1000 * 60 * 60)) % 24),
                minutes: Math.floor((difference / 1000 / 60) % 60),
                seconds: Math.floor((difference / 1000) % 60),
            };
        }

        return timeLeft;
    };

    const [timeLeft, setTimeLeft] = useState(calculateTimeLeft());

    useEffect(() => {
        const timer = setTimeout(() => {
            setTimeLeft(calculateTimeLeft());
        }, 1000);

        return () => clearTimeout(timer);
    });

    const formattedTime = `${String(timeLeft.hours).padStart(2, "0")}:${String(
        timeLeft.minutes,
    ).padStart(2, "0")}:${String(timeLeft.seconds).padStart(2, "0")}`;

    return <span className="font-bold">{formattedTime}</span>;
}

const ResultModal = (props) => {
    const [show, setShow] = useState(false);
    const [hideAnswer, setHideAnswer] = useState(false);
    const [prevKbodle, setPrevKbodle] = useState([]);
    const game = props?.status?.game;
    const custom = props.custom;
    const handleClose = () => {
        setShow(false);
        props.setShowResult(false);
    };
    const navigate = useNavigate();

    useEffect(() => {
        axios
            .post("/api/kbodle/get_prev_kbodle.php")
            .then((response) => {
                const result = response.data;
                if (result.status === 200) {
                    setPrevKbodle(result.list);
                } else {
                    alert(result.error);
                }
            })
            .catch((error) => {
                console.error("Error fetching kbodle list:", error);
            });
    }, []);
    useEffect(() => {
        if (props.show && props.kbodleMode !== "make-kbodle") setShow(true);
    }, [props.show, props.kbodleMode]);

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

    const formattedDate = (date) => {
        if (date === undefined) return;
        const [year, month, day] = date.split("-");
        return `${year}년 ${month}월 ${day}일`;
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

    const stats = JSON.parse(localStorage.getItem("kbodle-stats"));
    // 마지막으로 고른 선수가 정답이면 성공. 판을 알 수 없으면 공유 문구와 같은 기준(연속 기록 0 = 실패)을 쓴다.
    const lastPick = game?.board?.[(game?.count ?? 0) - 1];
    const solved = lastPick ? String(lastPick.SporkId) === String(game?.answer?.SporkId) : stats?.winStreak !== 0;

    const handleShare = (event, type) => {
        let shareText = "";
        if (custom === "")
            shareText += "#KBODLE #크보들 " + (game?.answer?.index ?? "");
        else shareText += "#크보들 - " + custom;
        shareText += " " + (stats?.winStreak !== 0 ? game?.count : "X") + "/9";
        if (stats?.winStreak > 1 && custom === "")
            shareText += "🔥" + stats.winStreak;
        shareText += "\n\n";
        if (props.tiles) {
            shareText += props.tiles
                .filter((row) => row.length > 0)
                .map((row) => row.join(" "))
                .join("\n");
        }

        if (navigator.canShare && type === "share") {
            event.preventDefault();
            navigator.share({
                title: "WESIPER",
                text: shareText,
                url: kbodleUrl,
            });
        } else if (type === "copy") {
            event.preventDefault();
            shareText += "\n\n" + kbodleUrl;
            navigator.clipboard.writeText(shareText).then(() => {
                console.log("Text copied to clipboard");
            });
        } else {
            if (type === "text") {
                shareText += "\n\n" + kbodleUrl;
            }

            return shareText;
        }
    };

    const getTeamLogo = (team) => {
    return new URL(`../../../assets/images/logos/${teamCode(team)}-logo.svg`, import.meta.url).href;
};

    return (
        <>
            <Modal
                show={show}
                onHide={handleClose}
                centered
                className="game-modal font-family-NaSqNe"
                scrollable
            >
                <Modal.Header style={{ border: "none" }} closeButton>
                    <span className="font-family-kbo text-xl font-bold">
                        KBODLE: 크보들
                    </span>
                    <span className="font-family-kbo mx-2">
                        #{game?.answer.index}
                    </span>
                </Modal.Header>

                <Modal.Body className="kbodle-result-body">
                    {/* 결과 요약: 몇 번 만에 맞혔는지 + 연속 기록 */}
                    <div className={`kbodle-result-hero ${solved ? "is-win" : "is-fail"}`}>
                        <div className="kbodle-result-score" aria-label={solved ? `9번 중 ${game?.count}번 만에 성공` : "실패"}>
                            <b>{solved ? game?.count : "X"}</b>
                            <span>/9</span>
                        </div>
                        <div className="kbodle-result-message">
                            <strong>
                                {custom !== ""
                                    ? `크보들 - ${custom}`
                                    : solved
                                      ? "오늘의 크보들 클리어!"
                                      : "아쉬워요!"}
                            </strong>
                            <p>
                                {solved
                                    ? `${game?.count}번 만에 맞혔어요.`
                                    : custom === ""
                                      ? "내일 다시 도전해봐요."
                                      : "정답은 아래 선수였어요."}
                            </p>
                        </div>
                        {custom === "" && stats && stats.winStreak > 1 && (
                            <span className="kbodle-result-streak">
                                🔥 {stats.winStreak}일 연속
                            </span>
                        )}
                    </div>

                    {/* 정답 선수 */}
                    <Collapse in={!hideAnswer}>
                        <div>
                            <div
                                className={
                                    "modal-player kbodle-result-answer bg-" +
                                    teamCode(game?.answer.Team)
                                }
                            >
                                <div className="kbodle-result-photo">
                                    {game && (
                                        <PlayerImg
                                            img={game?.answer.SporkId}
                                            name={game?.answer.Name}
                                            className=""
                                        />
                                    )}
                                </div>
                                <div className="kbodle-result-player">
                                    <span className="kbodle-result-team">
                                        <img
                                            src={
                                                game &&
                                                game.answer.Team &&
                                                getTeamLogo(game?.answer.Team)
                                            }
                                            alt=""
                                        ></img>
                                        {teamFull(game?.answer.Team)}
                                    </span>
                                    <strong>
                                        {game?.answer.Name}
                                        <small>No.{game?.answer.BackNo}</small>
                                    </strong>
                                    <a
                                        href={"/?pid=" + game?.answer.SporkId}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        선수 프로필 보기
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" /></svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </Collapse>
                    <Form.Check
                        type="switch"
                        id="switch-modal-player"
                        label="정답 숨기기 (스크린샷용)"
                        className="kbodle-result-hide"
                        checked={hideAnswer}
                        onChange={() => setHideAnswer(!hideAnswer)}
                    />

                    <section className="kbodle-result-section">
                        <h3>결과 공유</h3>
                        <ResultSquare
                            tiles={props.tiles}
                            handleShare={handleShare}
                        />
                    </section>

                    <section className="kbodle-result-section">
                        <h3>지나간 크보들 풀기</h3>
                        <div className="kbodle-result-previous">
                            {prevKbodle &&
                                prevKbodle.map((e, index) => (
                                    <button
                                        type="button"
                                        key={e.PK}
                                        onClick={() => {
                                            window.location.href =
                                                "https://wesiper.xyz/kbodle/?code=" +
                                                Base64.encode(
                                                    e.playerID + "_#" + e.PK,
                                                    true,
                                                );
                                        }}
                                    >
                                        {index + 1}일 전
                                    </button>
                                ))}
                        </div>
                        <Button
                            variant="outline-primary"
                            className="kbodle-result-custom"
                            onClick={() => {
                                handleClose();
                                props.setKbodleMode("make-kbodle");
                            }}
                        >
                            나만의 크보들 만들기
                        </Button>
                    </section>
                </Modal.Body>
                <Modal.Footer className="kbodle-result-footer">
                    <div className="kbodle-result-countdown">
                        <span>다음 크보들까지</span>
                        <CountdownTimer />
                    </div>

                    <Button
                        variant="success"
                        onClick={() => {
                            handleClose();
                            navigate("/bingo");
                        }}
                    >
                        크보빙고 풀기
                    </Button>
                    <Button
                        variant="outline-success"
                        onClick={() => {
                            handleClose();
                            navigate("/lineup");
                        }}
                    >
                        라인업 맞추기
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
};

export default ResultModal;
