import { useEffect, useRef, useState } from "react";
import Modal from "react-bootstrap/Modal";
import HowToPlay from "../../../assets/images/kbobingo/how_to_play.png";

const slides = [
    { eyebrow: "HOW TO PLAY", title: "하루 한 판, KBO 빙고", description: "각 행과 열의 조건을 모두 만족하는 선수를 찾아 3×3 빙고판을 완성하세요.", content: <div className="bingo-help-intro-grid">
        <div className="bingo-help-feature"><span>🎯</span><div><strong>교집합을 찾아요</strong><p>가로·세로 조건을 동시에 만족해야 정답이에요.</p></div></div>
        <div className="bingo-help-feature"><span>💎</span><div><strong>희귀할수록 고득점</strong><p>다른 사람이 적게 고른 선수일수록 점수가 높아요.</p></div></div>
        <div className="bingo-help-feature"><span>🌙</span><div><strong>매일 새로운 도전</strong><p>빙고판은 매일 자정에 새롭게 바뀌어요.</p></div></div>
    </div> },
    { eyebrow: "BOARD GUIDE", title: "두 조건을 모두 만족하는 선수", description: "칸을 누른 뒤 조건에 맞는 선수 이름을 검색해 선택하세요.", content: <div className="bingo-help-board-guide">
        <img src={HowToPlay} alt="행과 열 조건의 교집합으로 선수를 선택하는 예시" />
        <div className="bingo-help-callout"><span className="bingo-help-callout-number">1</span><p><strong>키움 히어로즈</strong>와 <strong>KT 위즈</strong>에서 모두 뛴 선수라면 정답!</p></div>
        <p className="bingo-help-example">예: 박병호 — 2011~2021년 키움, 이후 KT 소속</p>
        <div className="bingo-help-note"><span>!</span><p>SK-SSG, 우리-서울-넥센-키움 등의 계열 팀은 같은 팀으로 인정되어요. 단, 현대-키움 등 해체 후 재창단된 경우는 같은 팀으로 인정되지 않아요.</p></div>
    </div> },
    { eyebrow: "BOARD GUIDE", title: "두 조건을 모두 만족하는 선수", description: "팀과 시즌 기록 조건이 만나는 칸은 해당 팀에서 기록을 달성해야 해요.", content: <div className="bingo-help-board-guide">
        <img src={HowToPlay} alt="2번 칸의 조건을 설명하는 빙고판 예시" />
        <div className="bingo-help-callout"><span className="bingo-help-callout-number orange">2</span><p><strong>키움 히어로즈</strong>에서 <strong>한 시즌 150안타 이상</strong>을 기록한 선수라면 정답!</p></div>
        <p className="bingo-help-example">예: 이정후 — 2022년 키움 소속으로 193안타 기록</p>
        <div className="bingo-help-note"><span>!</span><p>이용규 선수는 개별 조건을 각각 만족하지만, 키움 소속으로 150안타를 기록하지는 못했으므로 오답이에요.</p></div>
    </div> },
    { eyebrow: "BOARD GUIDE", title: "두 조건을 모두 만족하는 선수", description: "팀 소속 경력과 통산 기록 조건도 모두 만족하는 선수를 선택하세요.", content: <div className="bingo-help-board-guide">
        <img src={HowToPlay} alt="3번 칸의 조건을 설명하는 빙고판 예시" />
        <div className="bingo-help-callout"><span className="bingo-help-callout-number green">3</span><p><strong>키움 히어로즈</strong> 소속 경력이 있으면서 <strong>통산 100승 이상</strong>을 기록한 선수라면 정답!</p></div>
        <p className="bingo-help-example">예: 장원삼 — 키움 소속 경력이 있고 통산 121승 기록</p>
    </div> },
    { eyebrow: "CHECK BEFORE PLAY", title: "마지막으로 기억하세요", description: "아래 세 가지만 알면 준비 완료! 신중하게 9칸을 채워보세요.", content: <div className="bingo-help-rules">
        <div><span>01</span><p>시즌 중 이적한 선수의 시즌 성적은 <strong>최종 소속팀 기록</strong>으로 인정해요.</p></div>
        <div><span>02</span><p><strong>1차 · 1라운드 지명</strong>은 신인 1차 지명 또는 전면 드래프트 1라운드만 포함해요.</p></div>
        <div><span>03</span><p>선택 기회는 총 <strong>9번</strong>. 모두 사용하면 게임이 끝나요.</p></div>
    </div> },
];

const Chevron = ({ direction }) => <svg viewBox="0 0 24 24" aria-hidden="true"><path d={direction === "left" ? "m15 18-6-6 6-6" : "m9 18 6-6-6-6"} /></svg>;

const HelpModal = ({ show, setShow }) => {
    const [page, setPage] = useState(0);
    const touchStart = useRef(null);
    const handleClose = () => setShow(false);
    useEffect(() => { if (show) setPage(0); }, [show]);
    const move = (next) => setPage(Math.max(0, Math.min(slides.length - 1, next)));
    const handleKeyDown = (event) => {
        if (event.key === "ArrowRight") move(page + 1);
        if (event.key === "ArrowLeft") move(page - 1);
    };
    const handleTouchEnd = (event) => {
        if (touchStart.current === null) return;
        const distance = event.changedTouches[0].clientX - touchStart.current;
        if (Math.abs(distance) > 45) move(page + (distance < 0 ? 1 : -1));
        touchStart.current = null;
    };

    return <Modal show={show} onHide={handleClose} onKeyDown={handleKeyDown} centered dialogClassName="bingo-help-dialog" contentClassName="bingo-help-modal font-family-NaSqNe" aria-labelledby="bingo-help-title">
        <Modal.Header style={{ border: "none" }} closeButton>
            <span className="font-family-kbo text-xl font-bold" id="bingo-help-title">KBO BINGO</span>
        </Modal.Header>
        <Modal.Body className="bingo-help-body">
            <div className="bingo-help-viewport" onTouchStart={(event) => { touchStart.current = event.touches[0].clientX; }} onTouchEnd={handleTouchEnd}>
                <div className="bingo-help-track" style={{ transform: `translateX(-${page * 100}%)` }}>
                    {slides.map((slide, index) => <section className="bingo-help-slide" key={slide.title} aria-hidden={page !== index}>
                        <div className="bingo-help-hero"><div><span className="bingo-help-eyebrow">{slide.eyebrow}</span><h2>{slide.title}</h2></div></div>
                        <p className="bingo-help-description">{slide.description}</p>{slide.content}
                    </section>)}
                </div>
            </div>
            <div className="bingo-help-controls">
                <button className="bingo-help-nav secondary" onClick={() => move(page - 1)} disabled={page === 0} aria-label="이전 도움말"><Chevron direction="left" /> 이전</button>
                <div className="bingo-help-progress" aria-label={`${slides.length}페이지 중 ${page + 1}페이지`}>{slides.map((_, index) => <button key={index} className={index === page ? "active" : ""} onClick={() => move(index)} aria-label={`${index + 1}페이지로 이동`} aria-current={index === page ? "step" : undefined} />)}</div>
                {page === slides.length - 1 ? <button className="bingo-help-nav primary" onClick={handleClose}>시작하기 <span>✓</span></button> : <button className="bingo-help-nav primary" onClick={() => move(page + 1)}>다음 <Chevron /></button>}
            </div>
        </Modal.Body>
    </Modal>;
};

export default HelpModal;
