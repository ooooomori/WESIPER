import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import wesiperLogo from '../assets/images/wesiper-primary-logo.png';
import './nav.css';

const pages = [
    {id: "records", name: "선수 기록실", href: "/records"},
    {id: "kbodle", name: "KBODLE: 크보들", href: "/kbodle"},
    {id: "bingo", name: "KBO BINGO", href: "/bingo"},
    {id: "lineup", name: "라인업 맞추기", href: "/lineup"},
    {id: "contact", name: "문의하기", href: "mailto:godmascotvic@gmail.com", external: true},
];
// 탭에는 없지만 주소로는 들어올 수 있는 페이지의 제목
const hiddenPages = [{name: "KBO CANDLE", href: "/kbocandle"}];
// 화면 폭이 이 값 이상이면 가로 메뉴, 미만이면 햄버거 메뉴
const DESKTOP_QUERY = '(min-width: 800px)';

const Chevron = () => <svg className="site-nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>;

const Nav = () => {
    const location = useLocation();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const listRef = useRef(null);
    const toggleRef = useRef(null);
    const [indicator, setIndicator] = useState(null);
    const mainPages = pages.filter(page => !page.external);
    const contact = pages.find(page => page.external);

    // 가로 메뉴: 현재 페이지 뒤의 알약이 선택한 메뉴로 미끄러진다.
    useLayoutEffect(() => {
        const list = listRef.current;
        if (!list) return undefined;
        const update = () => {
            const active = list.querySelector('.site-nav-link.is-active');
            const cell = active?.closest('li');
            setIndicator(cell ? { left: cell.offsetLeft, width: cell.offsetWidth } : null);
        };
        update();
        const observer = new ResizeObserver(update);
        observer.observe(list);
        document.fonts?.ready?.then(update);
        return () => observer.disconnect();
    }, [location.pathname]);

    useEffect(() => {
        document.title = "WESIPER - " + ([...pages, ...hiddenPages].find(p => p.href === location.pathname )?.name ?? "웨시퍼");
    }, [location]);

    // 다른 화면으로 이동하면 메뉴를 닫는다.
    useEffect(() => { setOpen(false); }, [location.pathname, location.search]);

    // 메뉴가 열려 있는 동안: Esc로 닫고, 뒤쪽 화면은 스크롤하지 않는다. 가로 메뉴 폭이 되면 닫는다.
    useEffect(() => {
        if (!open) return undefined;
        const onKey = event => { if (event.key === 'Escape') { setOpen(false); toggleRef.current?.focus(); } };
        const media = window.matchMedia(DESKTOP_QUERY);
        const onResize = () => { if (media.matches) setOpen(false); };
        document.addEventListener('keydown', onKey);
        media.addEventListener('change', onResize);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            media.removeEventListener('change', onResize);
            document.body.style.overflow = overflow;
        };
    }, [open]);

    const item = (page, className, sheet = false) => {
        const active = !page.external && page.href === location.pathname;
        const props = { className: `${className}${active ? ' is-active' : ''}`, 'aria-current': active ? 'page' : undefined };
        const content = sheet ? <><span>{page.name}</span><Chevron /></> : <span>{page.name}</span>;
        return page.external
            ? <a {...props} href={page.href}>{content}</a>
            : <Link {...props} to={page.href}>{content}</Link>;
    };

    return (
        <>
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TWQDS8MH"
            height="0" width="0" style={{display:"none",visibility:"hidden"}} title="GoogleTagManager"></iframe></noscript>

            <nav className="site-navbar font-family-NaSqNe" aria-label="주 메뉴">
                <div className="site-nav-bar">
                    <Link to="/" className="site-nav-brand" aria-label="WESIPER 홈">
                        <img src={wesiperLogo} alt="WESIPER" />
                    </Link>
                    <ul className="site-nav-links" ref={listRef}>
                        {indicator && <li className="site-nav-indicator" aria-hidden="true" style={{ left: indicator.left, width: indicator.width }} />}
                        {mainPages.map(page => <li key={page.id}>{item(page, 'site-nav-link')}</li>)}
                    </ul>
                    <a className="site-nav-contact" href={contact.href}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 8 8 5.5L20 8" /></svg>
                        {contact.name}
                    </a>
                    <button type="button" ref={toggleRef} className="site-nav-toggle" aria-expanded={open} aria-controls="site-nav-sheet" aria-label={open ? '메뉴 닫기' : '메뉴 열기'} onClick={() => setOpen(value => !value)}>
                        <span aria-hidden="true" /><span aria-hidden="true" /><span aria-hidden="true" />
                    </button>
                </div>
                <div className={`site-nav-layer${open ? ' is-open' : ''}`} inert={!open ? '' : undefined}>
                    <button type="button" className="site-nav-backdrop" tabIndex={-1} aria-label="메뉴 닫기" onClick={() => setOpen(false)} />
                    <div id="site-nav-sheet" className="site-nav-sheet">
                        <button type="button" className="site-nav-sheet-search" aria-haspopup="dialog" onClick={() => { setOpen(false); navigate('/?search=1'); }}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3" /><path d="m15.6 15.6 4.4 4.4" /></svg>
                            <span>선수 이름을 검색해 보세요!</span>
                        </button>
                        <ul className="site-nav-sheet-links">
                            {mainPages.map(page => <li key={page.id}>{item(page, 'site-nav-sheet-link', true)}</li>)}
                        </ul>
                        <a className="site-nav-sheet-contact" href={contact.href}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 8 8 5.5L20 8" /></svg>
                            {contact.name}
                        </a>
                    </div>
                </div>
            </nav>
        </>
    )
}

export default Nav;
