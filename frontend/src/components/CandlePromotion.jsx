import { useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";

export default function CandlePromotion() {
    const { pathname } = useLocation();
    const [dismissed, setDismissed] = useState(false);
    const [scrolling, setScrolling] = useState(false);
    const containerRef = useRef(null);
    const measureRef = useRef(null);
    const message = "간편하게 선수 기록을 검색하고 성적을 비교해보세요!";
    useEffect(() => {
        const container = containerRef.current;
        const measure = measureRef.current;
        if (!container || !measure) return;
        const update = () => setScrolling(window.innerWidth < 768 && measure.getBoundingClientRect().width > container.clientWidth);
        const observer = new ResizeObserver(update);
        observer.observe(container);
        window.addEventListener("resize", update);
        let active = true;
        document.fonts?.ready.then(() => { if (active) update(); });
        update();
        return () => { active = false; observer.disconnect(); window.removeEventListener("resize", update); };
    }, [pathname, dismissed]);
    if (dismissed || !["/bingo", "/kbodle"].includes(pathname)) return null;

    return (
        <div role="region" aria-label="KBO CANDLE 안내"
            className="font-family-NaSqNe bg-slate-800 text-white px-4 py-2.5 relative flex items-center justify-center text-center border-b border-slate-700 md:px-6 overflow-hidden min-w-0 max-w-full">
            <div ref={containerRef} className="relative text-xs md:text-sm text-white font-medium leading-relaxed mr-6 min-w-0 w-full flex items-center justify-center gap-1">
                <span aria-hidden="true" className="absolute w-0 h-0 overflow-hidden pointer-events-none">
                    <span ref={measureRef} className="invisible whitespace-nowrap flex items-center gap-1" style={{ width: "max-content" }}>
                        <span>📢</span><span>{message}</span><span className="ml-1 inline-block px-1.5 py-0.5 font-family-kbo font-bold">KBO CANDLE</span>
                    </span>
                </span>
                <span aria-hidden="true" className="shrink-0">📢</span>{" "}
                {scrolling ? <span className="candle-marquee min-w-0 flex-1 overflow-hidden" aria-label={message}>
                    <span className="candle-marquee-track" aria-hidden="true">
                        <span>{message}</span><span>{message}</span>
                    </span>
                </span> : <span>{message}</span>}{" "}
                <Link to="/kbocandle" className="ml-1 shrink-0 whitespace-nowrap inline-block px-1.5 py-0.5 !text-white hover:!text-sky-300 transition-colors duration-200 underline decoration-1 hover:decoration-2 underline-offset-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-white rounded">
                    <span className="font-family-kbo font-bold">KBO CANDLE</span>
                </Link>
            </div>
            <button type="button" aria-label="안내 배너 닫기" onClick={() => setDismissed(true)}
                className="absolute right-4 p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 rounded">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-3 h-3 cursor-pointer fill-slate-50" aria-hidden="true" viewBox="0 0 329.269 329">
                    <path d="M194.8 164.77 323.013 36.555c8.343-8.34 8.343-21.825 0-30.164-8.34-8.34-21.825-8.34-30.164 0L164.633 134.605 36.422 6.391c-8.344-8.34-21.824-8.34-30.164 0-8.344 8.34-8.344 21.824 0 30.164l128.21 128.215L6.259 292.984c-8.344 8.34-8.344 21.825 0 30.164a21.27 21.27 0 0 0 15.082 6.25c5.46 0 10.922-2.09 15.082-6.25l128.21-128.214 128.216 128.214a21.27 21.27 0 0 0 15.082 6.25c5.46 0 10.922-2.09 15.082-6.25 8.343-8.34 8.343-21.824 0-30.164zm0 0" />
                </svg>
            </button>
        </div>
    );
}
