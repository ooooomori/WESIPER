import { useEffect, useRef, useState } from "react";
import Modal from "react-bootstrap/Modal";

const labels = ["팀", "포지션", "투", "타", "나이", "출신고", "드래프트"];

const ClueRow = ({ green = [], orange = [], wrong = [] }) => (
    <div className="kbodle-help-clues" aria-label="단서 색상 예시">
        {labels.map((label, index) => {
            const state = green.includes(index)
                ? "green"
                : orange.includes(index)
                  ? "orange"
                  : wrong.includes(index)
                    ? "wrong"
                    : "blank";
            return <div key={label} className={`kbodle-help-clue ${state}`}>{label}</div>;
        })}
    </div>
);

const slides = [
    {
        eyebrow: "HOW TO PLAY",
        title: "단서로 오늘의 선수를 맞혀요",
        description: "선수를 검색하면 정답과 비교한 7가지 단서가 색으로 표시돼요.",
        content: <div className="kbodle-help-overview">
            <ClueRow green={[0, 1]} orange={[4, 5]} wrong={[2, 3, 6]} />
            <div className="kbodle-help-legend"><span><i className="green" />일치</span><span><i className="orange" />근접</span><span><i className="wrong" />불일치</span></div>
            <div className="kbodle-help-tip"><span>TIP</span><p>색이 바뀐 단서를 하나씩 좁혀가며 오늘의 KBO 선수를 찾아보세요.</p></div>
        </div>,
    },
    {
        eyebrow: "EXACT MATCH",
        title: "초록색은 정확히 일치",
        description: "내가 고른 선수의 정보가 정답 선수와 완전히 같다는 뜻이에요.",
        content: <div className="kbodle-help-guide">
            <ClueRow green={[0, 1, 5]} />
            <div className="kbodle-help-callout green"><span>✓</span><p>이 예시에서는 <strong>팀, 포지션, 출신고</strong> 정보가 정답과 일치해요.</p></div>
        </div>,
    },
    {
        eyebrow: "CLOSE MATCH · 1",
        title: "주황색은 정답에 가까운 단서",
        description: "완전히 같지는 않지만 정답 범위를 좁힐 수 있는 정보예요.",
        content: <div className="kbodle-help-details">
            <div><ClueRow orange={[0]} /><p><strong>팀</strong> · 같은 드림/나눔 올스타에 속해요.</p><small>드림: 두산·롯데·삼성·SSG·KT / 나눔: KIA·LG·키움·한화·NC</small></div>
            <div><ClueRow orange={[1]} /><p><strong>포지션</strong> · 정답 선수의 서브 포지션이에요.</p></div>
            <div><ClueRow orange={[2, 3]} /><p><strong>투·타</strong> · 한쪽이 양타이거나, 투구 손은 같고 오버핸드/언더핸드만 달라요.</p></div>
        </div>,
    },
    {
        eyebrow: "CLOSE MATCH · 2",
        title: "주황색은 정답에 가까운 단서",
        description: "나이, 출신고, 드래프트는 아래 기준에 가까우면 주황색이에요.",
        content: <div className="kbodle-help-details">
            <div><ClueRow orange={[4]} /><p><strong>나이</strong> · 정답 선수와 나이 차이가 2살 이내예요.</p></div>
            <div><ClueRow orange={[5]} /><p><strong>출신고</strong> · 고등학교 지역이 같아요.</p><small>예: 서울고 ↔ 휘문고 · 국외 고등학교는 모두 같은 지역으로 인정</small></div>
            <div><ClueRow orange={[6]} /><p><strong>드래프트</strong> · 지명 방식이 같고 라운드 차이가 2 이내이거나, 라운드가 같아요.</p><small>예: 2차 4R ↔ 2차 2R / 3R ↔ 2차 3R · 단, 1차 지명 ↔ 1R은 제외</small></div>
        </div>,
    },
    {
        eyebrow: "NO MATCH",
        title: "검은색은 일치하지 않아요",
        description: "초록색이나 주황색 기준에 해당하지 않는 정보는 검은색으로 표시돼요.",
        content: <div className="kbodle-help-guide">
            <ClueRow wrong={[0, 4, 6]} />
            <div className="kbodle-help-callout wrong"><span>×</span><p>이 예시에서는 <strong>팀, 나이, 드래프트</strong> 정보가 정답과 가깝지 않아요.</p></div>
            <div className="kbodle-help-finish"><strong>준비 완료!</strong><p>초록색 단서를 늘려가며 정답 선수를 찾아보세요.</p></div>
        </div>,
    },
];

const Chevron = ({ direction }) => <svg viewBox="0 0 24 24" aria-hidden="true"><path d={direction === "left" ? "m15 18-6-6 6-6" : "m9 18 6-6-6-6"} /></svg>;

const HelpModal = ({ show, setShow }) => {
    const [page, setPage] = useState(0);
    const touchStart = useRef(null);
    const close = () => setShow(false);
    const move = (next) => setPage(Math.max(0, Math.min(slides.length - 1, next)));

    useEffect(() => { if (show) setPage(0); }, [show]);

    const handleTouchEnd = (event) => {
        if (touchStart.current === null) return;
        const distance = event.changedTouches[0].clientX - touchStart.current;
        if (Math.abs(distance) > 45) move(page + (distance < 0 ? 1 : -1));
        touchStart.current = null;
    };

    return <Modal show={show} onHide={close} onKeyDown={(event) => {
        if (event.key === "ArrowRight") move(page + 1);
        if (event.key === "ArrowLeft") move(page - 1);
    }} centered dialogClassName="kbodle-help-dialog" contentClassName="kbodle-help-modal font-family-NaSqNe" aria-labelledby="kbodle-help-title">
        <Modal.Header style={{ border: "none" }} closeButton>
            <span className="font-family-kbo text-xl font-bold" id="kbodle-help-title">KBODLE: 크보들</span>
        </Modal.Header>
        <Modal.Body className="kbodle-help-body">
            <div className="kbodle-help-viewport" onTouchStart={(event) => { touchStart.current = event.touches[0].clientX; }} onTouchEnd={handleTouchEnd}>
                <div className="kbodle-help-track" style={{ transform: `translateX(-${page * 100}%)` }}>
                    {slides.map((slide, index) => <section className="kbodle-help-slide" key={slide.eyebrow} aria-hidden={page !== index}>
                        <span className="kbodle-help-eyebrow">{slide.eyebrow}</span>
                        <h2>{slide.title}</h2>
                        <p className="kbodle-help-description">{slide.description}</p>
                        {slide.content}
                    </section>)}
                </div>
            </div>
            <div className="kbodle-help-controls">
                <button className="kbodle-help-nav secondary" type="button" onClick={() => move(page - 1)} disabled={page === 0}><Chevron direction="left" /> 이전</button>
                <div className="kbodle-help-progress" aria-label={`${slides.length}페이지 중 ${page + 1}페이지`}>{slides.map((_, index) => <button type="button" key={index} className={page === index ? "active" : ""} onClick={() => move(index)} aria-label={`${index + 1}페이지로 이동`} aria-current={page === index ? "step" : undefined} />)}</div>
                {page === slides.length - 1 ? <button className="kbodle-help-nav primary" type="button" onClick={close}>시작하기 <span>✓</span></button> : <button className="kbodle-help-nav primary" type="button" onClick={() => move(page + 1)}>다음 <Chevron /></button>}
            </div>
        </Modal.Body>
    </Modal>;
};

export default HelpModal;
