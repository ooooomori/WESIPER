import Modal from "react-bootstrap/Modal";
import Button from "react-bootstrap/Button";
import HowToPlay from "../../../assets/images/kbobingo/how_to_play.png";

// 예시 빙고판(how_to_play.png)의 1·2·3번 칸 설명. 번호 색은 그림의 표시 색과 같다.
const cells = [
    { tone: "red", rule: <><strong>키움 히어로즈</strong>와 <strong>KT 위즈</strong>에서 모두 뛴 선수</>, example: "박병호 — 2011~2021년 키움, 이후 KT 소속" },
    { tone: "orange", rule: <><strong>키움 히어로즈</strong>에서 <strong>한 시즌 150안타 이상</strong>을 기록한 선수</>, example: "이정후 — 2022년 키움 소속으로 193안타",
        caution: "이용규는 두 조건을 각각 만족하지만, 키움 소속으로 150안타를 기록한 적은 없어서 오답이에요." },
    { tone: "green", rule: <><strong>키움 히어로즈</strong> 소속 경력이 있고 <strong>통산 100승 이상</strong>인 선수</>, example: "장원삼 — 키움 소속 경력, 통산 121승" },
];

const HelpModal = ({ show, setShow }) => {
    const handleClose = () => setShow(false);
    return <Modal show={show} onHide={handleClose} centered scrollable className="game-modal game-help font-family-NaSqNe" aria-labelledby="bingo-help-title">
        <Modal.Header style={{ border: "none" }} closeButton>
            <span className="font-family-kbo" id="bingo-help-title">KBO BINGO</span>
            <span>게임 방법</span>
        </Modal.Header>
        <Modal.Body className="game-help-body">
            <p className="game-help-lead">가로·세로 조건을 모두 만족하는 선수를 찾아 3×3 빙고판을 채워요.</p>

            <section className="game-help-section">
                <h3><span>01</span>기본 규칙</h3>
                <ul className="game-help-list">
                    <li><b aria-hidden="true">·</b><div><strong>교집합을 찾아요</strong><p>칸을 누르고, 그 칸의 가로·세로 조건을 동시에 만족하는 선수를 검색해 선택해요.</p></div></li>
                    <li><b aria-hidden="true">·</b><div><strong>희귀할수록 고득점</strong><p>다른 사람이 적게 고른 선수일수록 점수가 높아요.</p></div></li>
                    <li><b aria-hidden="true">·</b><div><strong>기회는 9번</strong><p>선택 기회를 모두 쓰면 게임이 끝나고, 빙고판은 매일 자정에 바뀌어요.</p></div></li>
                </ul>
            </section>

            <section className="game-help-section">
                <h3><span>02</span>칸 채우는 법</h3>
                <figure className="game-help-figure"><img src={HowToPlay} alt="가로와 세로 조건이 만나는 칸에 1, 2, 3번이 표시된 빙고판 예시" /></figure>
                <ul className="game-help-list">
                    {cells.map((cell, index) => <li key={cell.tone}>
                        <b className={`is-${cell.tone}`}>{index + 1}</b>
                        <div><p className="game-help-rule">{cell.rule}</p><small>예: {cell.example}</small>{cell.caution && <small className="game-help-caution">{cell.caution}</small>}</div>
                    </li>)}
                </ul>
            </section>

            <section className="game-help-section">
                <h3><span>03</span>꼭 알아둘 것</h3>
                <ul className="game-help-notes">
                    <li>팀과 <strong>시즌 기록</strong>이 만나는 칸은 그 팀 소속으로 기록을 달성해야 해요.</li>
                    <li>시즌 중 이적한 선수의 시즌 성적은 <strong>최종 소속팀 기록</strong>으로 인정해요.</li>
                    <li>SK-SSG, 우리-서울-넥센-키움처럼 이어진 팀은 <strong>같은 팀</strong>이에요. 현대-키움처럼 해체 후 재창단된 경우는 다른 팀이에요.</li>
                    <li><strong>1차 · 1라운드 지명</strong>은 신인 1차 지명 또는 전면 드래프트 1라운드만 포함해요.</li>
                </ul>
            </section>
        </Modal.Body>
        <Modal.Footer>
            <Button variant="primary" onClick={handleClose}>시작하기</Button>
        </Modal.Footer>
    </Modal>;
};

export default HelpModal;
