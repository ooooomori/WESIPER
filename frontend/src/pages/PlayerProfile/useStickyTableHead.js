import { useEffect, useRef } from 'react';

// 긴 표를 아래로 내려도 컬럼명이 보이도록, 화면 위에 붙는 머리글 사본을 표와 맞춘다.
// 사본(.profile-table-sticky-head)은 가로 스크롤 영역 바로 앞에 두고, 그 ref를 여기서 돌려준다.
// measure: 열 너비가 내용에 따라 정해지는 표라면 실제 머리글 칸의 너비를 사본에 옮긴다.
export default function useStickyTableHead(measure = false) {
    const stickyRef = useRef(null);
    useEffect(() => {
        const sticky = stickyRef.current;
        const scroller = sticky?.nextElementSibling;
        if (!sticky || !scroller) return;
        const clip = sticky.firstElementChild;
        let frame = 0, measured = false;
        const copyWidths = () => {
            const table = scroller.querySelector('table');
            const source = table?.tHead?.rows[0]?.cells;
            const copy = clip.querySelector('table');
            const target = copy?.tHead?.rows[0]?.cells;
            if (!source || !target || source.length !== target.length) return;
            copy.style.tableLayout = 'fixed';
            copy.style.width = `${table.getBoundingClientRect().width}px`;
            copy.style.minWidth = '0';
            [...source].forEach((cell, index) => { target[index].style.boxSizing = 'border-box'; target[index].style.width = `${cell.getBoundingClientRect().width}px`; });
            measured = true;
        };
        const update = () => {
            frame = 0;
            const top = sticky.getBoundingClientRect().top;
            const box = scroller.getBoundingClientRect();
            // 표 머리글이 화면 위로 넘어간 뒤부터, 표의 마지막 줄이 지나가기 전까지만 보인다.
            const stuck = top > box.top + 1 && top + clip.offsetHeight * 2 < box.bottom;
            if (stuck && measure && !measured) copyWidths();
            sticky.classList.toggle('is-stuck', stuck);
            if (stuck) clip.scrollLeft = scroller.scrollLeft;
        };
        const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
        const remeasure = () => { measured = false; schedule(); };
        const syncLeft = () => { clip.scrollLeft = scroller.scrollLeft; };
        scroller.addEventListener('scroll', syncLeft, { passive: true });
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', remeasure);
        update();
        return () => { cancelAnimationFrame(frame); scroller.removeEventListener('scroll', syncLeft); window.removeEventListener('scroll', schedule); window.removeEventListener('resize', remeasure); };
    });
    return stickyRef;
}
