import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { TwitterShareButton, XIcon } from "react-share";
import { Table } from "flowbite-react";
import MostGrid from "./MostGrid.jsx";
import LeaderboardTable from "./LeaderboardTable.jsx";
import DistinctLine from "./DistinctLine.jsx";
import axios from "axios";

const { Kakao } = window;

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
    const [stat, setStat] = useState({
        percentage: null,
        rank: null,
        total: null,
    });

    const game = props?.status?.[props.date];
    const bingoUrl = "https://wesiper.xyz/bingo/";

    const completed = game?.correctAnswer.reduce(
        (total, subArray) =>
            total + subArray.filter((item) => item !== null).length,
        0,
    );
    const score = game?.score;
    const uuid = localStorage.getItem("uuid");
    const handleClose = () => {
        props.setShow(false);
    };
    const navigate = useNavigate();

    useEffect(() => {
        const sendRequest = async () => {
            if (uuid !== null && game && game.chance === 0) {
                const controller = new AbortController();
                await axios
                    .post(
                        "/api/kbobingo/get_stat.php",
                        {
                            index: game.grid.index,
                            uuid: uuid,
                            score: score,
                            completed: completed,
                        },
                        { signal: controller.signal },
                    )
                    .then((response) => {
                        const result = response.data;
                        if (result.code === 200) {
                            setStat({
                                percentage: result.percentage,
                                rank: result.rank,
                                total: result.total,
                                nickname: result.nickname,
                                top: result.top,
                            });
                        }
                    })
                    .catch((error) => {
                        console.error(
                            "KBO BINGO 유저 기록 받아오기 실패:",
                            error,
                        );
                    });
                return () => {
                    controller.abort();
                };
            }
        };

        sendRequest();
    }, [score, completed]);

    const handleShare = (event, type) => {
        let shareText = `#크보빙고 ${game?.grid.index} ${score}점 (${completed}/9)\n`;
        for (let i = 0; i < 3; i++) {
            shareText += "\n";
            for (let j = 0; j < 3; j++) {
                if (game?.correctAnswer[i][j] !== null) shareText += "🟩";
                else shareText += "⬜️";
            }
        }

        if (navigator.canShare && type === "share") {
            event.preventDefault();
            navigator.share({
                title: "WESIPER",
                text: shareText,
                url: bingoUrl,
            });
        } else if (type === "copy") {
            event.preventDefault();
            shareText += "\n\n" + bingoUrl;
            navigator.clipboard.writeText(shareText).then(() => {
                console.log("Text copied to clipboard");
            });
        } else {
            if (type === "text") {
                shareText += "\n\n" + bingoUrl;
            }

            return shareText;
        }
    };

    useEffect(() => {
        // init 해주기 전에 clean up 을 해준다.
        if (props.show) {
            Kakao.cleanup();
            // 자신의 js 키를 넣어준다.
            Kakao.init("25dedcd63c24a5a75a9fae607290fd1f");
            // 잘 적용되면 true 를 뱉는다.
            Kakao.Share.createDefaultButton({
                container: "#kakaotalk-sharing-btn",
                objectType: "text",
                text: handleShare(null, "kakao"),
                link: {
                    webUrl: bingoUrl,
                },
                buttonTitle: "빙고 풀러 가기",
            });
        }
    }, [props.show]);

    const formattedDate = (date) => {
        if (date === undefined) return;
        const [year, month, day] = date.split("-");
        return `${year}년 ${month}월 ${day}일`;
    };

    return (
        <Modal
            show={props.show}
            onHide={handleClose}
            centered
            className="font-family-NaSqNe"
            scrollable={false}
        >
            <Modal.Header style={{ border: "none" }} closeButton>
                <span className="font-family-kbo text-xl font-bold">
                    KBO BINGO
                </span>
                <span className="font-family-kbo mx-2">
                    #{game?.grid.index}
                </span>
            </Modal.Header>
            {completed === 9 && (
                <div className="callout callout-info mb-0">
                    <span>오늘의 빙고를 클리어하셨어요 🎉</span>
                </div>
            )}

            {completed !== 9 && (
                <div className="callout callout-warning mb-0">
                    <span>아쉬워요! 내일 다시 도전해봐요 🔥</span>
                </div>
            )}
            <Modal.Body className="flex flex-col justify-center items-center">
                <div className="grid grid-cols-3 gap-0.5 sm:gap-1">
                    {[].concat
                        .apply([], game?.correctAnswer)
                        .map((e, index) => (
                            <div
                                key={index}
                                className={`w-12 h-12 sm:w-16 sm:h-16 rounded-xl border ${
                                    e === null ? "bg-gray-100" : "bg-green-400"
                                }`}
                            ></div>
                        ))}
                </div>
                <div className="flex flex-col justify-center items-center mt-3">
                    <span>내 점수</span>
                    <div className="text-center text-4xl sm:py-1 font-semibold">
                        <span>{score}</span>
                    </div>
                    <span className="text-sm mt-3">
                        상위{" "}
                        <span className="font-bold">{stat.percentage}</span>%에
                        해당하는 점수예요!
                    </span>
                    <span className="text-sm">
                        ({stat.total}명 중 {stat.rank}등)
                    </span>
                </div>
                <div className="flex justify-center mt-5 gap-3 w-full">
                    <TwitterShareButton url={handleShare(null, "text")}>
                        <XIcon size={30} round={true} borderRadius={30}></XIcon>
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

                    <Button
                        variant="primary"
                        size="sm"
                        className="!rounded-full !px-2.5"
                        onClick={(event) => handleShare(event, "share")}
                    >
                        결과 공유하기
                    </Button>
                </div>

                <div className="flex justify-center items-center flex-col mt-8 w-full">
                    <DistinctLine text="가장 많이 선택한 답" />
                    <MostGrid status={props.status} date={props.date} />
                    <DistinctLine text="BINGO 리더보드 📊" />
                    {stat.rank && (
                        <LeaderboardTable
                            status={stat}
                            score={score}
                            uuid={uuid}
                        />
                    )}
                    <Button
                        variant="outline-primary"
                        className="mt-8"
                        onClick={() => {
                            handleClose();
                            props.setShowPrev(true);
                        }}
                    >
                        이전 빙고 풀어보기
                    </Button>
                </div>
            </Modal.Body>
            <Modal.Footer
                style={{ border: "none" }}
                className="flex justify-evenly"
            >
                <div className="text-center">
                    <span>
                        다음 빙고까지
                        <br />
                        <CountdownTimer />
                    </span>
                </div>

                <Button variant="success" onClick={() => navigate("/kbodle")}>
                    크보들 풀기
                </Button>
            </Modal.Footer>
        </Modal>
    );
};

export default ResultModal;
