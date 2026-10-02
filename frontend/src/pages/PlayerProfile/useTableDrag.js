import { useEffect, useState } from 'react';

// 모바일에서 표 끝에 닿은 뒤에도 더 끌면 고무줄처럼 늘어났다가 돌아온다.
// 고정 칸은 그대로 두고 나머지 칸만 옮기며, 벌어진 틈은 끝 칸이 자기 배경·구분선으로 채운다.
function attachTableOverscroll(scroll) {
    let touch = null, side = null, marked = [], offset = 0, releaseFrame = 0;
    const resist = pull => {
        const width = scroll.clientWidth || 1;
        return Math.sign(pull) * (1 - 1 / (Math.abs(pull) * .55 / width + 1)) * width;
    };
    // 되돌아가는 도중에 다시 잡으면 지금 늘어난 만큼에서 이어 끌 수 있도록 끈 거리를 역산한다.
    const unresist = value => {
        const width = scroll.clientWidth || 1;
        return Math.sign(value) * (1 / (1 - Math.min(Math.abs(value) / width, .99)) - 1) * width / .55;
    };
    const setOffset = value => { offset = value; scroll.style.setProperty('--overscroll', `${value}px`); };
    const reset = () => {
        cancelAnimationFrame(releaseFrame);
        for (const cell of marked) delete cell.dataset.overscroll;
        marked = []; side = null; offset = 0;
        scroll.classList.remove('is-overscrolling');
        scroll.style.removeProperty('--overscroll');
    };
    const mark = (cell, value) => { cell.dataset.overscroll = value; marked.push(cell); };
    const prepare = nextSide => {
        reset();
        side = nextSide;
        const table = scroll.querySelector('table');
        for (const row of table?.rows ?? []) {
            const cells = [...row.cells];
            const fixed = cells.filter(cell => getComputedStyle(cell).position === 'sticky');
            fixed.forEach(cell => mark(cell, 'fixed'));
            const edge = nextSide === 'end' ? cells.at(-1) : cells.find(cell => !fixed.includes(cell));
            if (edge && !fixed.includes(edge)) mark(edge, nextSide);
        }
        scroll.classList.add('is-overscrolling');
    };
    // 칸 이동과 틈 채우기가 같은 값을 쓰도록 CSS 전환 대신 프레임마다 값을 줄인다.
    const release = () => {
        const from = offset, startedAt = performance.now();
        const frame = now => {
            const progress = Math.min(1, (now - startedAt) / 420);
            setOffset(from * (1 - progress) ** 3);
            if (progress < 1) releaseFrame = requestAnimationFrame(frame);
            else reset();
        };
        cancelAnimationFrame(releaseFrame);
        releaseFrame = requestAnimationFrame(frame);
    };
    const start = event => {
        if (event.touches.length !== 1) { touch = null; return; }
        const { clientX, clientY } = event.touches[0];
        cancelAnimationFrame(releaseFrame);
        touch = { x: clientX, y: clientY, lastX: clientX, axis: null, pull: side ? unresist(offset) : 0 };
    };
    const move = event => {
        if (!touch || event.touches.length !== 1) return;
        const { clientX, clientY } = event.touches[0];
        if (!touch.axis) {
            const dx = Math.abs(clientX - touch.x), dy = Math.abs(clientY - touch.y);
            if (dx < 4 && dy < 4) return;
            touch.axis = dx > dy ? 'x' : 'y';
        }
        const delta = clientX - touch.lastX;
        touch.lastX = clientX;
        if (touch.axis !== 'x') return;
        const max = scroll.scrollWidth - scroll.clientWidth;
        const atStart = scroll.scrollLeft <= 0, atEnd = scroll.scrollLeft >= max - 1;
        if (!touch.pull && !((atStart && delta > 0) || (atEnd && delta < 0))) return;
        if (!touch.pull) prepare(delta > 0 ? 'start' : 'end');
        const pull = touch.pull + delta;
        // 반대로 되돌려 끌면 늘어난 만큼만 줄이고, 다시 원래 스크롤로 넘긴다.
        touch.pull = touch.pull && Math.sign(pull) !== Math.sign(touch.pull) ? 0 : pull;
        if (event.cancelable) event.preventDefault();
        setOffset(resist(touch.pull));
    };
    const end = () => {
        if (side && offset) release();
        else if (side) reset();
        touch = null;
    };
    scroll.addEventListener('touchstart', start, { passive: true });
    scroll.addEventListener('touchmove', move, { passive: false });
    scroll.addEventListener('touchend', end);
    scroll.addEventListener('touchcancel', end);
    return () => {
        reset();
        scroll.removeEventListener('touchstart', start);
        scroll.removeEventListener('touchmove', move);
        scroll.removeEventListener('touchend', end);
        scroll.removeEventListener('touchcancel', end);
    };
}

export function attachTableDrag(scroll) {
    const detachOverscroll = attachTableOverscroll(scroll);
    let drag = null;
    const updateOverflow = () => scroll.classList.toggle('is-draggable', scroll.scrollWidth > scroll.clientWidth);
    const finish = event => {
        if (!drag || (event?.pointerId !== undefined && event.pointerId !== drag.id)) return;
        const id = drag.id;
        drag = null;
        scroll.classList.remove('is-dragging');
        if (scroll.hasPointerCapture(id)) scroll.releasePointerCapture(id);
    };
    const down = event => {
        if (event.pointerType !== 'mouse' || event.button !== 0 || scroll.scrollWidth <= scroll.clientWidth) return;
        if (event.target.closest('button, a, input, select, textarea, [contenteditable="true"]')) return;
        const rect = scroll.getBoundingClientRect();
        if (event.clientY >= rect.top + scroll.clientHeight) return;
        drag = { id: event.pointerId, x: event.clientX, left: scroll.scrollLeft, active: false };
    };
    const move = event => {
        if (!drag || event.pointerId !== drag.id) return;
        if (!(event.buttons & 1)) { finish(event); return; }
        const distance = event.clientX - drag.x;
        if (!drag.active && Math.abs(distance) < 4) return;
        if (!drag.active) {
            drag.active = true;
            scroll.setPointerCapture(drag.id);
            scroll.classList.add('is-dragging');
            window.getSelection()?.removeAllRanges();
        }
        event.preventDefault();
        scroll.scrollLeft = Math.max(0, Math.min(scroll.scrollWidth - scroll.clientWidth, drag.left - distance));
    };
    const stopNativeDrag = event => { if (drag) event.preventDefault(); };
    const blur = () => finish();
    const observer = new ResizeObserver(updateOverflow);
    observer.observe(scroll);
    if (scroll.firstElementChild) observer.observe(scroll.firstElementChild);
    updateOverflow();
    scroll.addEventListener('pointerdown', down);
    scroll.addEventListener('lostpointercapture', finish);
    scroll.addEventListener('dragstart', stopNativeDrag);
    window.addEventListener('pointermove', move, { passive: false });
    window.addEventListener('pointerup', finish);
    window.addEventListener('pointercancel', finish);
    window.addEventListener('blur', blur);
    return () => {
        finish();
        detachOverscroll();
        observer.disconnect();
        scroll.classList.remove('is-draggable');
        scroll.removeEventListener('pointerdown', down);
        scroll.removeEventListener('lostpointercapture', finish);
        scroll.removeEventListener('dragstart', stopNativeDrag);
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', finish);
        window.removeEventListener('pointercancel', finish);
        window.removeEventListener('blur', blur);
    };
}

export default function useTableDrag() {
    const [scroll, setScroll] = useState(null);
    useEffect(() => scroll ? attachTableDrag(scroll) : undefined, [scroll]);
    return setScroll;
}
