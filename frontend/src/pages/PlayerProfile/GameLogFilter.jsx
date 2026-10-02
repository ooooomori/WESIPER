import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export default function GameLogFilter({ years = [], year, season, availableSeasons = {}, onYearChange, onSeasonChange, seasonOnly = false, disabled = false }) {
    const [open, setOpen] = useState(false);
    const root = useRef(null);
    const panel = useRef(null);
    const [panelLeft, setPanelLeft] = useState(null);
    useLayoutEffect(() => {
        if (!open) return;
        const positionPanel = () => {
            if (!root.current || !panel.current) return;
            const anchor = root.current.getBoundingClientRect();
            const width = panel.current.getBoundingClientRect().width;
            const viewportWidth = document.documentElement.clientWidth;
            const centered = anchor.left + anchor.width / 2 - width / 2;
            const left = Math.max(16, Math.min(centered, viewportWidth - width - 16));
            setPanelLeft(left - anchor.left);
        };
        positionPanel();
        const observer = new ResizeObserver(positionPanel);
        observer.observe(root.current);
        observer.observe(panel.current);
        window.addEventListener('resize', positionPanel);
        window.addEventListener('scroll', positionPanel, true);
        return () => {
            observer.disconnect();
            window.removeEventListener('resize', positionPanel);
            window.removeEventListener('scroll', positionPanel, true);
        };
    }, [open]);
    const seasonLabels = { regular: '정규시즌', preseason: '시범경기', postseason: '포스트시즌', futures: '퓨처스리그' };
    const available = seasonOnly ? availableSeasons : availableSeasons[year] || [];
    useEffect(() => {
        if (!open) return;
        const closeOutside = event => { if (!root.current?.contains(event.target)) setOpen(false); };
        const closeEscape = event => { if (event.key === 'Escape') { setOpen(false); root.current?.querySelector('button')?.focus(); } };
        document.addEventListener('pointerdown', closeOutside);
        document.addEventListener('keydown', closeEscape);
        return () => { document.removeEventListener('pointerdown', closeOutside); document.removeEventListener('keydown', closeEscape); };
    }, [open]);
    return <div className="profile-game-filter" ref={root}>
        <button className="profile-game-filter-trigger" type="button" aria-expanded={open} aria-label={seasonOnly ? '연도별 기록 시즌 종류' : '경기 연도와 시즌 선택'} disabled={disabled || (!seasonOnly && !years.length)} onClick={() => setOpen(value => !value)}>{!seasonOnly && <>{year ? `${year}년` : '연도'} · </>}{seasonLabels[season]}<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
        {open && <div className="profile-game-filter-panel" ref={panel} style={panelLeft === null ? undefined : { left: panelLeft, transform: "none" }}>{!seasonOnly && <><div className="profile-game-filter-label">연도</div><div className="profile-game-filter-years">{years.map(value => <button key={value} type="button" aria-pressed={Number(year) === Number(value)} onClick={() => onYearChange(String(value))}>{value}</button>)}</div></>}<div className="profile-game-filter-seasons"><div className="profile-game-filter-label">시즌 종류</div>{Object.entries(seasonLabels).map(([value, label]) => <button key={value} type="button" disabled={!available.includes(value)} aria-pressed={season === value} onClick={() => { onSeasonChange(value); setOpen(false); }}>{label}{season === value && <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 12 4 4 10-10" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" /></svg>}</button>)}</div></div>}
    </div>;
}
