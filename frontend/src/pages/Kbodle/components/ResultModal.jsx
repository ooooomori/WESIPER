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

    return (
        <div className="text-center">
            <div className="modal-result">
                <div className="modal-result-tiles flex items-center justify-center">
                    {props.tiles
                        ?.filter((row) => row.length > 0)
                        .map((row, index) => (
                            <React.Fragment key={index}>
                                {row.join(" ")}
                                <br />
                            </React.Fragment>
                        ))}
                </div>
                <div className="modal-result-right flex items-center flex-col justify-center">
                    <Button
                        variant="primary"
                        size="sm"
                        className="!rounded-full !px-2.5"
                        onClick={(event) => props.handleShare(event, "copy")}
                    >
                        결과 복사하기
                    </Button>
                    <div className="flex justify-evenly w-full">
                        <TwitterShareButton
                            url={props.handleShare(null, "text")}
                        >
                            <XIcon
                                size={30}
                                round={true}
                                borderRadius={30}
                            ></XIcon>
                        </TwitterShareButton>
                        <button
                            id="kakaotalk-sharing-btn"
                            style={{
                                border: "none",
                                background: "transparent",
                                padding: "0",
                                width: "30px",
                                height: "30px",
                            }}
                        >
                            <img
                                src="https://developers.kakao.com/assets/img/about/logos/kakaotalksharing/kakaotalk_sharing_btn_medium.png"
                                alt="카카오톡 공유"
                                style={{
                                    borderRadius: "50%",
                                    width: "100%",
                                    height: "100%",
                                    objectFit: "cover",
                                }}
                            />
                        </button>
                    </div>
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
                className="font-family-NaSqNe"
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

                <Modal.Body className="!p-0">
                    {custom === "" && stats?.winStreak !== 0 && (
                        <div className="callout callout-info mb-0">
                            <span>오늘의 크보들을 클리어하셨어요 🎉</span>
                            {stats && stats.winStreak > 1 && (
                                <>
                                    <br />
                                    <span>
                                        🔥{" "}
                                        <strong>
                                            {stats.winStreak}일 연속
                                        </strong>
                                        으로 크보들 클리어중 🔥
                                    </span>
                                </>
                            )}
                        </div>
                    )}
                    {custom === "" && stats?.winStreak === 0 && (
                        <div className="callout callout-warning mb-0">
                            <span>아쉬워요! 내일 다시 도전해봐요 🔥</span>
                        </div>
                    )}
                    <div className="!p-[1rem]">
                        <span className="font-family-NaSqNe font-bold">
                            ⚾
                            {custom === ""
                                ? " 오늘의 크보들"
                                : ` 크보들 - ${custom}`}
                        </span>
                        <a
                            id="share-icon"
                            className="icon-link mx-2"
                            href=""
                            onClick={(event) => handleShare(event, "share")}
                            title="공유하기"
                        >
                            <i className="bi-box-arrow-up"></i>
                        </a>
                        <div className="my-3">
                            <ResultSquare
                                tiles={props.tiles}
                                handleShare={handleShare}
                            />
                        </div>
                        <div>
                            <Collapse in={!hideAnswer}>
                                <div className="m-0 p-0">
                                    <div
                                        className={
                                            "my-2 modal-player bg-" +
                                            teamCode(game?.answer.Team)
                                        }
                                    >
                                        <div className="modal-player-img">
                                            {game && (
                                                <PlayerImg
                                                    img={game?.answer.SporkId}
                                                    name={game?.answer.Name}
                                                    className=""
                                                />
                                            )}
                                        </div>
                                        <div className="modal-player-text font-family-kbo">
                                            <div className="mb-2">
                                                <img
                                                    src={
                                                        game &&
                                                        game.answer.Team &&
                                                        getTeamLogo(game?.answer.Team)
                                                    }
                                                    alt={game?.answer.Team}
                                                    className="h-6 inline mr-1"
                                                ></img>
                                                <span>
                                                    {teamFull(
                                                        game?.answer.Team,
                                                    )}
                                                </span>
                                            </div>
                                            <span>
                                                No.{game?.answer.BackNo}
                                            </span>{" "}
                                            <span>{game?.answer.Name}</span>{" "}
                                            <a
                                                href={
                                                    "https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId=" +
                                                    game?.answer.SporkId
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
                            </Collapse>
                            <Form.Check
                                type="switch"
                                id="switch-modal-player"
                                label="정답 숨기기 (스크린샷용)"
                                className="mx-2 text-gray-500"
                                style={{ fontSize: "14px" }}
                                checked={hideAnswer}
                                onChange={() => setHideAnswer(!hideAnswer)}
                            />
                        </div>
                        <div className="my-5">
                            <span className="font-family-NaSqNe font-bold">
                                ⚜️ 지나간 크보들 풀기
                            </span>
                            <div className="mt-3 flex gap-2 overflow-x-scroll">
                                {prevKbodle &&
                                    prevKbodle.map((e, index) => (
                                        <Button
                                            key={e.PK}
                                            variant="secondary"
                                            size="sm"
                                            className="!rounded-full !px-2.5 whitespace-nowrap"
                                            onClick={() => {
                                                window.location.href =
                                                    "https://wesiper.xyz/kbodle/?code=" +
                                                    Base64.encode(
                                                        e.playerID +
                                                            "_#" +
                                                            e.PK,
                                                        true,
                                                    );
                                            }}
                                        >
                                            {index + 1}일 전
                                        </Button>
                                    ))}
                            </div>
                        </div>

                        <div className="flex justify-center mt-5">
                            <Button
                                variant="outline-primary"
                                onClick={() => {
                                    handleClose();
                                    props.setKbodleMode("make-kbodle");
                                }}
                            >
                                나만의 크보들 만들기
                            </Button>
                        </div>
                    </div>
                </Modal.Body>
                <Modal.Footer
                    style={{ border: "none" }}
                    className="flex justify-evenly"
                >
                    <div className="text-center">
                        <span>
                            다음 크보들까지
                            <br />
                            <CountdownTimer />
                        </span>
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
                </Modal.Footer>
            </Modal>
        </>
    );
};

export default ResultModal;
