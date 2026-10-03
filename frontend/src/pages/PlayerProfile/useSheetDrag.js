import { useEffect, useRef, useState } from 'react';

// 아래에서 올라오는 모달(dialog)의 머리 부분을 잡고 끄는 동작.
// 돌려주는 값: dialog(ref), expanded(가득 펼쳐졌는지), close(), justDragged(ref), handlers(머리 부분에 붙일 포인터 이벤트)
export default function useSheetDrag() {
    const dialog = useRef(null);
    const drag = useRef(null);
    const justDragged = useRef(false);
    const [expanded, setExpanded] = useState(false);
    useEffect(() => { dialog.current.showModal(); }, []);
    // 모달이 열려 있는 동안 뒤 화면이 스크롤되지 않게 잠근다(모달 바깥을 쓸어도 페이지가 움직이지 않는다).
    useEffect(() => {
        const element = dialog.current;
        const root = document.documentElement;
        const previous = { overflow: root.style.overflow, paddingRight: root.style.paddingRight };
        // 스크롤바가 사라지며 화면이 옆으로 밀리지 않게 그 너비만큼 채운다.
        const scrollbar = window.innerWidth - root.clientWidth;
        root.style.overflow = 'hidden';
        if (scrollbar > 0) root.style.paddingRight = `${scrollbar}px`;
        // 배경(모달 바깥)에서 시작한 터치·휠은 페이지로 넘어가지 않게 막는다.
        const block = event => { if (event.target === element) event.preventDefault(); };
        element.addEventListener('touchmove', block, { passive: false });
        element.addEventListener('wheel', block, { passive: false });
        return () => {
            element.removeEventListener('touchmove', block);
            element.removeEventListener('wheel', block);
            root.style.overflow = previous.overflow;
            root.style.paddingRight = previous.paddingRight;
        };
    }, []);
    const close = () => dialog.current.close();
    // 머리 부분을 잡고 아래로 끌면 닫히고, 위로 끌면 손가락을 따라 시트가 늘어나 화면 가득 펼쳐진다.
    const maxSheetHeight = () => window.innerHeight * (window.innerWidth > 600 ? .9 : .94);
    const dragStart = event => {
        if (event.button > 0 || event.target.closest('button, select, a, input')) return;
        drag.current = { y: event.clientY, dy: 0, height: dialog.current.getBoundingClientRect().height };
        event.currentTarget.setPointerCapture?.(event.pointerId);
        dialog.current.style.transition = 'none';
    };
    const dragMove = event => {
        if (!drag.current) return;
        const raw = event.clientY - drag.current.y;
        // 살짝 움직인 정도(10px 이내)는 무시해서 탭·미세한 흔들림에 시트가 반응하지 않게 한다.
        const dy = Math.abs(raw) < 10 ? 0 : raw - Math.sign(raw) * 10;
        drag.current.dy = dy;
        const element = dialog.current;
        if (dy < 0) {
            const max = maxSheetHeight();
            const grown = drag.current.height - dy;
            // 최대 높이를 넘기면 살짝만 따라오게 해서 끝에 닿은 느낌을 준다.
            element.style.maxHeight = 'none';
            element.style.height = `${Math.min(grown, max + (grown - max) / 4)}px`;
            element.style.transform = '';
        } else {
            element.style.height = `${drag.current.height}px`;
            element.style.transform = `translateY(${dy}px)`;
        }
    };
    const dragEnd = () => {
        if (!drag.current) return;
        const { dy, height: startHeight } = drag.current;
        drag.current = null;
        // 드래그를 놓을 때 생기는 click이 바깥(배경) 클릭으로 처리되지 않게 막는다.
        if (Math.abs(dy) > 5) { justDragged.current = true; window.setTimeout(() => { justDragged.current = false; }, 0); }
        const element = dialog.current;
        element.style.transition = 'transform .2s ease, height .25s ease';
        // 살짝 스와이프하면 제자리로 돌아오고, 충분히 끌었을 때만 닫히거나 펼쳐진다.
        const remaining = maxSheetHeight() - startHeight;
        if (dy > (expanded ? 320 : 170)) { element.style.transform = 'translateY(110%)'; window.setTimeout(close, 180); return; }
        element.style.transform = '';
        const next = dy < -Math.max(110, Math.min(remaining, 400) * .4) ? true : expanded && dy > 130 ? false : expanded;
        setExpanded(next);
        // 목표 높이로 부드럽게 옮긴 뒤 인라인 스타일을 걷어내 CSS가 다시 높이를 맡게 한다.
        const natural = element.style.height || `${element.getBoundingClientRect().height}px`;
        element.classList.toggle('is-expanded', next);
        element.style.height = '';
        element.style.maxHeight = '';
        const target = next ? maxSheetHeight() : Math.min(element.getBoundingClientRect().height, maxSheetHeight());
        element.style.height = natural;
        requestAnimationFrame(() => {
            element.style.height = `${target}px`;
            window.setTimeout(() => { element.style.height = ''; element.style.maxHeight = ''; element.style.transition = ''; }, 260);
        });
    };
    return { dialog, expanded, close, justDragged, handlers: { onPointerDown: dragStart, onPointerMove: dragMove, onPointerUp: dragEnd, onPointerCancel: dragEnd } };
}
