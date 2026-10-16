import { useLayoutEffect, useRef } from 'react';

const MOVE_MS = 320;

// 목록이 바뀌면 남는 줄은 이전 자리에서 새 자리로 미끄러지고, 새로 생기는 줄은 흐릿하게 나타난다. 렌더 직후(그리기 전)에 시작한다.
// 줄에는 data-id를 달고, 위치는 tbody 기준 offsetTop이라 스크롤과 무관하다. 목록이 비면(불러오는 중) 기억을 지워 채워질 때는 움직이지 않는다.
export default function useRowMotion(bodyRef, dep) {
    const previous = useRef(new Map());
    useLayoutEffect(() => {
        const body = bodyRef.current;
        if (!body) return;
        const rows = [...body.querySelectorAll('tr[data-id]')];
        const before = previous.current;
        previous.current = new Map(rows.map(row => [row.dataset.id, row.offsetTop]));
        if (!before.size || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        rows.forEach(row => {
            const from = before.get(row.dataset.id);
            if (from === undefined) row.animate([{ opacity: 0 }, { opacity: 1 }], { duration: MOVE_MS, easing: 'ease-out' });
            else if (Math.abs(from - row.offsetTop) > 1) row.animate([{ transform: `translateY(${from - row.offsetTop}px)` }, { transform: 'translateY(0)' }], { duration: MOVE_MS, easing: 'cubic-bezier(.4,0,.2,1)' });
        });
    }, [bodyRef, dep]);
}
