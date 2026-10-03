import Modal from "react-bootstrap/Modal";
import Button from "react-bootstrap/Button";

const labels = ["팀", "포지션", "투", "타", "나이", "출신고", "드래프트"];

const ClueRow = ({ green = [], orange = [], wrong = [] }) => (
    <div className="game-help-clues" aria-label="단서 색상 예시">
        {labels.map((label, index) => {
            const state = green.includes(index) ? "green" : orange.includes(index) ? "orange" : wrong.includes(index) ? "wrong" : "blank";
            return <div key={label} className={`game-help-clue is-${state}`}>{label}</div>;
        })}
    </div>
);

// 주황색(가까운 단서) 기준
const closeRules = [
    { label: "팀", rule: "같은 드림/나눔 올스타에 속해요.", note: "드림: 두산·롯데·삼성·SSG·KT / 나눔: KIA·LG·키움·한화·NC" },
    { label: "포지션", rule: "정답 선수의 서브 포지션이에요." },
    { label: "투·타", rule: "한쪽이 양타이거나, 투구 손은 같고 오버핸드/언더핸드만 달라요." },
    { label: "나이", rule: "정답 선수와 나이 차이가 2살 이내예요." },
    { label: "출신고", rule: "고등학교 지역이 같아요.", note: "예: 서울고 ↔ 휘문고 · 국외 고등학교는 모두 같은 지역으로 인정" },
    { label: "드래프트", rule: "지명 방식이 같고 라운드 차이가 2 이내이거나, 라운드가 같아요.", note: "예: 2차 4R ↔ 2차 2R / 3R ↔ 2차 3R · 단, 1차 지명 ↔ 1R은 제외" },
];

const HelpModal = ({ show, setShow }) => {
    const close = () => setShow(false);
    return <Modal show={show} onHide={close} centered scrollable className="game-modal game-help font-family-NaSqNe" aria-labelledby="kbodle-help-title">
        <Modal.Header style={{ border: "none" }} closeButton>
            <span className="font-family-kbo" id="kbodle-help-title">KBODLE: 크보들</span>
            <span>게임 방법</span>
        </Modal.Header>
        <Modal.Body className="game-help-body">
            <p className="game-help-lead">선수를 검색하면 정답과 비교한 7가지 단서가 색으로 표시돼요. 단서를 좁혀 오늘의 선수를 맞혀요.</p>

            <section className="game-help-section">
                <h3><span>01</span>색으로 보는 단서</h3>
                <ClueRow green={[0, 1]} orange={[4, 5]} wrong={[2, 3, 6]} />
                <ul className="game-help-legend">
                    <li><i className="is-green" aria-hidden="true" /><strong>초록색</strong><span>정답과 정확히 일치해요.</span></li>
                    <li><i className="is-orange" aria-hidden="true" /><strong>주황색</strong><span>같지는 않지만 정답에 가까워요.</span></li>
                    <li><i className="is-wrong" aria-hidden="true" /><strong>검은색</strong><span>일치하지도, 가깝지도 않아요.</span></li>
                </ul>
                <p className="game-help-reading">위 예시는 <strong>팀·포지션</strong>이 일치하고, <strong>나이·출신고</strong>는 가깝고, <strong>투·타·드래프트</strong>는 다르다는 뜻이에요.</p>
            </section>

            <section className="game-help-section">
                <h3><span>02</span>주황색이 되는 기준</h3>
                <dl className="game-help-terms">
                    {closeRules.map(item => <div key={item.label}><dt>{item.label}</dt><dd>{item.rule}{item.note && <small>{item.note}</small>}</dd></div>)}
                </dl>
            </section>
        </Modal.Body>
        <Modal.Footer>
            <Button variant="primary" onClick={close}>시작하기</Button>
        </Modal.Footer>
    </Modal>;
};

export default HelpModal;
