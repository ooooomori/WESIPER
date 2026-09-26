import wspImg from "../../assets/images/wsp.png";
import ssgImg from "../../assets/images/logos/ssg-small-logo.png";
import { useNavigate } from "react-router-dom";
import "./main.css";
import axios from "axios";
import { useState, useEffect } from "react";

function Main() {
    const [todaySSG, setTodaySSG] = useState("경기 없음");
    const navigate = useNavigate();

    useEffect(() => {
        axios
            .post("/api/todayGames.php")
            .then((response) => {
                const result = response.data;
                if (result.success && result.isGameExist) {
                    const isAwayWin =
                        Number(result.game.away_score) >
                        Number(result.game.home_score);
                    const isHomeWin =
                        Number(result.game.away_score) <
                        Number(result.game.home_score);
                    let ssgStatus = "무승부";
                    if (result.game.isGameFinished) {
                        if (
                            (isAwayWin && result.game.away === "SSG") ||
                            (isHomeWin && result.game.home === "SSG")
                        ) {
                            ssgStatus = "승리";
                        } else if (
                            (isHomeWin && result.game.away === "SSG") ||
                            (isAwayWin && result.game.home === "SSG")
                        ) {
                            ssgStatus = "패배";
                        }
                    } else {
                        ssgStatus = result.game.status;
                    }
                    setTodaySSG(
                        <div className="bg-white border rounded-2xl px-3 py-1">
                            <span className="font-bold">
                                {result.game.away}{" "}
                                <span
                                    className={
                                        isAwayWin
                                            ? "win"
                                            : isHomeWin
                                            ? "lose"
                                            : ""
                                    }
                                >
                                    {result.game.away_score}
                                </span>{" "}
                                :{" "}
                                <span
                                    className={
                                        isAwayWin
                                            ? "lose"
                                            : isHomeWin
                                            ? "win"
                                            : ""
                                    }
                                >
                                    {result.game.home_score}
                                </span>{" "}
                                {result.game.home}
                            </span>{" "}
                            <span>
                                ({result.game.stadium}, {ssgStatus})
                            </span>
                        </div>
                    );
                }
            })
            .catch((error) => {
                console.error("오늘의 랜더스 탐지 오류:", error);
            });
    }, []);

    return (
        <div>
            <div className="flex flex-col items-center justify-center sm:mt-12">
                <img src={wspImg} className="h-60 sm:h-72" alt="" />
                <button
                    className="main-btn font-family-kbo fw-bold"
                    onClick={() => navigate("/kbodle")}
                >
                    KBODLE: 크보들
                </button>
                <button
                    className="main-btn font-family-kbo fw-bold"
                    onClick={() => navigate("/bingo")}
                >
                    KBO BINGO
                </button>
                <button
                    className="main-btn font-family-kbo fw-bold"
                    onClick={() => navigate("/kbocandle")}
                >
                    KBO CANDLE
                </button>
                <p className="font-family-NaSqNe mt-3 mb-2 text-lg">
                    <img src={ssgImg} alt="SSG" className="h-5 inline-block" />{" "}
                    오늘의 랜더스
                </p>
                <p className="font-family-NaSqNe mt-0">{todaySSG}</p>
            </div>
        </div>
    );
}

export default Main;
