import { useEffect, useState } from 'react';

export function attachTableDrag(scroll) {
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
