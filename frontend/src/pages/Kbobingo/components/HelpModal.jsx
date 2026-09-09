import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import HowToPlay from "../../../assets/images/kbobingo/how_to_play.png";

const HelpModal = (props) => {
    const handleClose = () => {
        props.setShow(false);
    };

    return (
        <>
            <Modal
                show={props.show}
                onHide={handleClose}
                centered
                className="font-family-NaSqNe"
            >
                <Modal.Header style={{ border: "none" }} closeButton>
                    <span className="font-family-kbo text-xl font-bold">
                        KBO BINGO
                    </span>
                </Modal.Header>
                <Modal.Body className="text-sm">
                    <p className="font-family-kbo text-lg mb-1">
                        ✨ KBO BINGO 소개
                    </p>
                    <p className="mb-1">
                        - 각 행과 열의 조건에 맞는 선수를 선택하고, 빈칸을 채워
                        빙고를 완성하세요!
                    </p>
                    <p className="mb-1">
                        - 한 칸을 채울 때마다 점수를 얻어요. 남들이 생각하지
                        못한 선수들을 선택할 수록 고득점을 할 수 있어요.
                    </p>
                    <p className="mb-1">
                        - 빙고판은 매일 자정에 초기화되어요. 매일매일 빙고를
                        채워봐요!
                    </p>
                    <p className="font-family-kbo text-lg mt-3 mb-2">
                        🔖 KBO BINGO 규칙
                    </p>
                    <div className="border border-dashed border-cyan-500 rounded-3xl px-3 mb-3">
                        <div className="flex justify-center my-3">
                            <img
                                src={HowToPlay}
                                alt="플레이 방법 이미지"
                                className="w-3/4"
                            />
                        </div>

                        <div className="mb-3">
                            <div className="">
                                <span className="font-bold mr-2 sm:text-base">
                                    🟥 1번 칸 🟥
                                </span>
                                <span className="font-bold">키움 히어로즈</span>
                                <span className="text-xs">
                                    (우리/서울/넥센 시절 포함)
                                </span>
                                와 <span className="font-bold">KT 위즈</span> 두
                                팀에서 모두 뛰어 본 적이 있는 선수를 선택하면
                                정답이에요!
                                <p className="ml-2 mt-2 text-xs">
                                    예){" "}
                                    <a
                                        href="https://statiz.sporki.com/player/?m=playerinfo&p_no=10470"
                                        className="text-blue-600 hover:underline hover:underline-offset-2"
                                    >
                                        박병호
                                    </a>
                                    는 2011~2021년 키움 소속, 이후 KT
                                    소속이었으므로 정답
                                </p>
                            </div>
                            <div className="mt-2">
                                <span className="font-bold mr-2 sm:text-base">
                                    🟧 2번 칸 🟧
                                </span>
                                <span className="font-bold">키움 히어로즈</span>
                                에서{" "}
                                <span className="font-bold">
                                    한 시즌 150 안타 이상
                                </span>
                                을 기록한 선수를 선택하면 정답이에요!
                                <p className="ml-2 mt-2 text-xs">
                                    예){" "}
                                    <a
                                        href="https://statiz.sporki.com/player/?m=playerinfo&p_no=12906"
                                        className="text-blue-600 hover:underline hover:underline-offset-2"
                                    >
                                        이정후
                                    </a>
                                    는 2022년 키움 소속으로 193개의 안타를
                                    기록했으므로 정답
                                </p>
                            </div>
                            <div className="mt-2">
                                <span className="font-bold mr-2 sm:text-base">
                                    🟩 3번 칸 🟩
                                </span>
                                <span className="font-bold">키움 히어로즈</span>{" "}
                                소속이었던 적이 있으면서,{" "}
                                <span className="font-bold">
                                    통산 100승 이상
                                </span>
                                을 기록한 선수를 선택하면 정답이에요!
                                <p className="ml-2 mt-2 text-xs">
                                    예){" "}
                                    <a
                                        href="https://statiz.sporki.com/player/?m=playerinfo&p_no=10375"
                                        className="text-blue-600 hover:underline hover:underline-offset-2"
                                    >
                                        장원삼
                                    </a>
                                    은 2008~2009년 키움 소속이었고, 통산 121승을
                                    기록했으므로 정답
                                </p>
                            </div>
                        </div>
                    </div>

                    <p className="mb-1">
                        ✔️ 시즌 성적 혹은 포지션 기록 조건이 특정 팀 조건과
                        겹치는 경우 <span className="font-bold">그 팀에서</span>{" "}
                        해당 성적, 수상, 포지션을 달성해야 해요.
                    </p>
                    <p className="ml-2 mb-2 text-xs mt-1">
                        예) 'LG'와 '시즌 타율 3할 이상'의 교집합 칸에서,
                        '서건창'은 오답이에요. (LG에서는 3할 기록 한 적
                        없으므로)
                    </p>
                    <p className="mb-1">
                        ✔️ 시즌 중, 후 이적한 선수의 경우, 해당 시즌 성적은{" "}
                        <span className="font-bold">
                            시즌 중 최종 소속팀에서 기록한 것으로 인정
                        </span>
                        되어요.
                    </p>
                    <p className="ml-2 mb-2 text-xs mt-1">
                        예) 2009년 KIA 김상현 선수는 시즌 초 LG에서도 뛰었지만,
                        전부 KIA의 기록으로 간주해요.
                    </p>
                    <p className="mb-1">
                        ✔️ <span className="font-bold">1차 · 1라운드 지명</span>{" "}
                        조건의 경우 신인 1차 지명 혹은 전면 드래프트 1라운드
                        지명 선수만 정답이에요. 2차 1라운드 등은 포함되지
                        않아요.
                    </p>
                    <p className="mb-1">
                        ✔️ 선택 기회는 <span className="font-bold">9번</span>!
                        남은 기회를 모두 사용하면 게임이 끝나요.
                    </p>
                </Modal.Body>
                <Modal.Footer
                    style={{
                        border: "none",
                        display: "flex",
                        justifyContent: "center",
                    }}
                >
                    <Button
                        variant="outline-primary"
                        className="font-family-kbo mb-3 !px-10"
                        onClick={handleClose}
                    >
                        도움말 닫기
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
};

export default HelpModal;
