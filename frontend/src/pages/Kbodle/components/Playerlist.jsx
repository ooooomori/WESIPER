import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import axios from "axios";
import { useEffect, useState } from "react";

const Playerlist = (props) => {
    const [team, setTeam] = useState(0);
    const [roster, setRoster] = useState({});
    const handleClose = () => {
        props.setShow(false);
    };
    const GUDAN = [
        "LG",
        "KT",
        "SSG",
        "NC",
        "두산",
        "KIA",
        "롯데",
        "삼성",
        "한화",
        "키움",
    ];

    const POS = ["투수", "포수", "내야수", "외야수"];

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

    useEffect(() => {
        axios
            .post("/api/kbodle/get_roster.php")
            .then((response) => {
                const result = response.data;
                setRoster(result);
            })
            .catch((error) => {
                console.error("Error fetching kbo roster:", error);
            });
    }, []);

    // 패치 내역 확인 클릭 시 실행될 함수
    const showAlertHistory = (e) => {
        e.preventDefault(); // a 태그의 기본 이동 막기

        if (!roster || !roster.update_history) {
            alert("업데이트 내역이 없습니다.");
            return;
        }

        // JSON 객체를 alert용 문자열로 변환
        // 예: "2026-02-03: 내용\n2026-01-20: 내용..."
        let historyMessage = "";

        // Object.entries로 날짜(date)와 내역배열(contents)을 가져옴
        Object.entries(roster.update_history).forEach(([date, contents]) => {
            historyMessage += `📅 ${date}\n`;

            // 밸류가 배열이므로 한 번 더 반복문 실행
            contents.forEach((line) => {
                historyMessage += `  • ${line}\n`; // 불렛 포인트 추가
            });

            historyMessage += `\n`; // 날짜별 구분 공백
        });

        alert(historyMessage);
    };

    const getTeamLogo = (team) => {
        return new URL(
            `../../../assets/images/logos/${teamCode(team)}-logo.svg`,
            import.meta.url,
        ).href;
    };

    return (
        <>
            <Modal
                show={props.show}
                onHide={handleClose}
                centered
                className="font-family-NaBaGo"
            >
                <Modal.Header style={{ border: "none" }} closeButton>
                    <span className="font-family-kbo text-xl font-bold">
                        KBODLE 선수 명단
                    </span>
                </Modal.Header>
                <Modal.Body className="text-sm">
                    <div className="whitespace-nowrap overflow-x-scroll py-1 border-y-2">
                        {GUDAN.map((e, i) => (
                            <div
                                className={`inline-block text-center px-3 transition  hover:text-black hover:grayscale-0 ${
                                    i === team
                                        ? "text-black grayscale-0"
                                        : "text-gray-300 grayscale"
                                }`}
                                key={e}
                                onClick={() => setTeam(i)}
                            >
                                <img
                                    src={getTeamLogo(e)}
                                    className="h-8 w-8 sm:h-12 sm:w-12"
                                    alt={e}
                                />
                                <span className="mt-1 h-2">{e}</span>
                            </div>
                        ))}
                    </div>
                    {roster && roster.status === 200 && (
                        <div className="flex flex-col">
                            {POS.map((e, i) => (
                                <div className="mt-3">
                                    <span className="font-family-kbo bg-slate-600 rounded-md px-3 py-1 text-white">
                                        {e}
                                    </span>
                                    <ul className="mt-2">
                                        {roster[GUDAN[team]][i].map((e) => (
                                            <li className="float-left mr-2">
                                                {e}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}

                            <div className="mt-5 text-gray-700">
                                <span>
                                    • {roster.latest_update_date} 기준 현역인
                                    선수를 대상으로 해요.
                                </span>
                                <a
                                    href="#"
                                    onClick={showAlertHistory}
                                    className="text-blue-600 hover:underline hover:underline-offset-2 ml-1"
                                >
                                    패치 내역 확인
                                </a>
                                <br />
                                <span>
                                    • 현역 선수인데 명단에 누락되었거나 잘못
                                    추가된 선수는 제보 부탁드려요!
                                </span>
                            </div>
                        </div>
                    )}
                </Modal.Body>
            </Modal>
        </>
    );
};

export default Playerlist;
