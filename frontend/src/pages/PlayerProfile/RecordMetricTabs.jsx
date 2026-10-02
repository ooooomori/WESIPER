import { useLayoutEffect, useRef, useState } from 'react';

export default function RecordMetricTabs({ options, value, onChange }) {
    const tabsRef = useRef(null);
    const [indicator, setIndicator] = useState(null);
    useLayoutEffect(() => {
        const tabs = tabsRef.current;
        const update = () => {
            const selected = tabs?.querySelector('button[aria-pressed="true"]');
            if (!selected) return;
            setIndicator({ left: selected.offsetLeft, top: selected.offsetTop + selected.offsetHeight - 2, width: selected.offsetWidth });
        };
        update();
        const observer = new ResizeObserver(update);
        observer.observe(tabs);
        for (const button of tabs.querySelectorAll('button')) observer.observe(button);
        return () => observer.disconnect();
    }, [value, options]);
    return <nav className="profile-year-views profile-chart-metric-tabs" ref={tabsRef} aria-label="기록 지표">
        {options.map(([id, name]) => <button key={id} type="button" aria-pressed={value === id} onClick={() => onChange(id)}>{name}</button>)}
        {indicator && <span className="profile-year-view-indicator" aria-hidden="true" style={indicator} />}
    </nav>;
}
