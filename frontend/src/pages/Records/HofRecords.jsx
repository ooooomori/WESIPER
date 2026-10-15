import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Spinner } from 'flowbite-react';
import { teamCodeByName, teamColorByName, teamLogoByCode, teamSmallLogoByCode } from '../../lib/teamAssets';
import useTableDrag from '../PlayerProfile/useTableDrag';
import useStickyTableHead from '../PlayerProfile/useStickyTableHead';
import { recordColumnWidth } from '../PlayerProfile/recordTableLayout';

const metrics = [['blackInk', '블랙잉크'], ['grayInk', '그레이잉크'], ['standard', 'HOF 스탠다드'], ['monitor', 'HOF 모니터']];
const roles = [['batter', '타자'], ['pitcher', '투수']];
const PAGE = 50;
const scoreText = value => Number.isInteger(value) ? String(value) : value.toFixed(1);
const teamLogo = team => { const code = teamCodeByName(team); return code ? teamSmallLogoByCode(code) || teamLogoByCode(code) : null; };

// 기록 설명: 한 줄 정의와 항목별 기준. 점수 목록은 [배지, 설명] 쌍으로 적는다.
const descriptions = {
    blackInk: {
        title: '블랙잉크',
        lead: '시즌 리그 1위를 한 기록마다 점수를 주고 통산으로 더합니다. 공동 1위도 같은 점수입니다.',
        rows: [
            ['타자', [['4점', '홈런, 타점, 타율'], ['3점', '득점, 안타, 장타율'], ['2점', '2루타, 볼넷, 도루'], ['1점', '경기, 타수, 3루타']]],
            ['투수', [['4점', '승리, 평균자책점, 탈삼진'], ['3점', '이닝, 승률, 세이브'], ['2점', '완투, 9이닝당 볼넷, 9이닝당 피안타'], ['1점', '완봉, 경기, 선발 등판']]],
            ['기준', '비율 기록은 규정 타석과 규정 이닝을 채운 선수만, 승률은 10승 이상만 비교합니다.'],
        ],
    },
    grayInk: {
        title: '그레이잉크',
        lead: '시즌 리그 10위 이내에 든 기록마다 점수를 주고 통산으로 더합니다. 꾸준히 상위권을 지킨 선수가 높게 나옵니다.',
        rows: [
            ['점수표', '블랙잉크와 같은 점수표를 쓰고, 타자에는 출루율이 더해집니다.'],
            ['타자', [['3점', '출루율']]],
        ],
    },
    standard: {
        title: 'HOF 스탠다드',
        lead: '명예의 전당 선수들의 평균이 50점 안팎이 되도록 만든 통산 기록 점수입니다.',
        rows: [
            ['타자', '통산 안타, 타율, 득점, 타점, 장타율, 출루율, 홈런, 장타, 볼넷, 도루에 점수를 주고 포지션 가산점을 더합니다.'],
            ['투수', '통산 승리, 승률, 평균자책점, 탈삼진, 9이닝당 볼넷, 9이닝당 피안타, 이닝, 완투, 완봉에 점수를 줍니다.'],
            ['가산점', [['20점', '포수'], ['16점', '유격수'], ['14점', '2루수'], ['13점', '3루수'], ['12점', '중견수'], ['6점', '우익수'], ['3점', '좌익수'], ['1점', '1루수'], ['0점', '지명타자']]],
            ['기준', '타율, 장타율, 출루율 같은 타자 비율 항목은 통산 2,000타석 이상, 투수 비율 항목은 통산 500이닝 이상일 때만 점수를 줍니다.'],
        ],
    },
    monitor: {
        title: 'HOF 모니터',
        lead: '시즌 성적에 점수를 주고 모두 더합니다. 100점이면 후보권, 130점을 넘으면 입성이 사실상 확실합니다.',
        rows: [
            ['타율', [['2.5점', '.300 이상'], ['5점', '.350 이상'], ['15점', '.400 이상']]],
            ['타점, 득점', [['3점', '100타점 또는 100득점']]],
            ['홈런', [['2점', '30개'], ['4점', '40개'], ['10점', '50개']]],
            ['승리', [['2점', '15승'], ['4점', '18승'], ['6점', '20승'], ['8점', '23승'], ['10점', '25승'], ['15점', '30승']]],
            ['탈삼진', [['2점', '200개'], ['3점', '250개'], ['6점', '300개']]],
            ['기준', '타율은 100경기 이상 뛴 시즌만 셉니다.'],
        ],
    },
};

// 선수 수가 많지 않아 타자/투수마다 네 기록의 점수를 한 번에 받아 두고, 기록 정렬·현역만 보기·더 보기는 화면에서 처리한다.
const cache = new Map();
const resolved = new Map();
function loadAll(role) {
    if (!cache.has(role)) {
        const request = (async () => {
            const rows = [];
            let total = 0;
            do {
                const response = await fetch(`/api/hofRecords.php?v=4&role=${role}&metric=all&offset=${rows.length}&limit=200`);
                const data = await response.json().catch(() => null);
                if (!response.ok || !data) throw new Error(data?.error || '기록을 불러오지 못했습니다.');
                total = data.total;
                if (!data.rows.length) break;
                rows.push(...data.rows);
            } while (rows.length < total);
            resolved.set(role, rows);
            return rows;
        })();
        request.catch(() => cache.delete(role));
        cache.set(role, request);
    }
    return cache.get(role);
}

const FADE_MS = 200;
const MOVE_MS = 320;

// 좁은 화면에서만 짧은 열 이름을 쓴다(머리글 안에 따로 요소를 넣으면 정렬 화살표 스타일이 적용되므로 글자만 바꾼다).
function useCompact() {
    const query = '(max-width: 600px)';
    const [compact, setCompact] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);
    useEffect(() => {
        const media = window.matchMedia(query);
        const update = () => setCompact(media.matches);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);
    return compact;
}

export default function HofRecords() {
    const compact = useCompact();
    const [role, setRole] = useState('batter');
    const [metric, setMetric] = useState('blackInk');
    const [activeOnly, setActiveOnly] = useState(false);
    const [data, setData] = useState(() => resolved.has('batter') ? { key: 'batter', rows: resolved.get('batter'), loading: false, error: '' } : { key: '', rows: [], loading: true, error: '' });
    const [limit, setLimit] = useState(PAGE);
    const [rendered, setRendered] = useState([]);
    const [entering, setEntering] = useState(() => new Set());
    const [notesOpen, setNotesOpen] = useState(false);
    const scrollRef = useTableDrag();
    const stickyRef = useStickyTableHead();
    const timer = useRef(0);
    const bodyRef = useRef(null);
    const renderedRef = useRef([]);
    const tops = useRef(null);

    useEffect(() => {
        let active = true;
        if (resolved.has(role)) { setData({ key: role, rows: resolved.get(role), loading: false, error: '' }); return undefined; }
        setData({ key: '', rows: [], loading: true, error: '' });
        loadAll(role)
            .then(rows => { if (active) setData({ key: role, rows, loading: false, error: '' }); })
            .catch(error => { if (active) setData({ key: '', rows: [], loading: false, error: error.message }); });
        return () => { active = false; };
    }, [role]);

    useEffect(() => { setLimit(PAGE); }, [role, metric]);

    // 현역만 보기에서는 현역 선수끼리 다시 순위를 매긴다(공동 순위 포함).
    const target = useMemo(() => {
        const list = data.rows
            .filter(row => row.scores[metric] > 0 && (!activeOnly || row.active))
            .sort((a, b) => b.scores[metric] - a.scores[metric] || a.name.localeCompare(b.name, 'ko') || a.id - b.id);
        let rank = 0, last = null;
        return list.map((row, index) => {
            const score = row.scores[metric];
            if (last === null || score !== last) rank = index + 1;
            last = score;
            return { ...row, rank };
        });
    }, [data.rows, metric, activeOnly]);
    const visible = useMemo(() => target.slice(0, limit), [target, limit]);

    // 목록이 바뀌면 빠지는 줄이 먼저 흐려지고, 그다음 목록을 바꾸면서 남는 줄이 제 자리로 미끄러진다.
    useEffect(() => {
        window.clearTimeout(timer.current);
        const previous = renderedRef.current;
        const visibleIds = new Set(visible.map(row => row.id));
        const previousIds = new Set(previous.map(item => item.row.id));
        const commit = () => {
            const body = bodyRef.current;
            if (body && previous.length) tops.current = new Map([...body.querySelectorAll('tr[data-id]')].map(row => [row.dataset.id, row.getBoundingClientRect().top]));
            setEntering(previous.length && data.key ? new Set(visible.filter(row => !previousIds.has(row.id)).map(row => row.id)) : new Set());
            setRendered(visible.map(row => ({ row, leaving: false })));
        };
        const leavingIds = previous.filter(item => !visibleIds.has(item.row.id)).map(item => item.row.id);
        if (!data.key || !previous.length || !leavingIds.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) commit();
        else {
            setRendered(previous.map(item => ({ row: item.row, leaving: !visibleIds.has(item.row.id) })));
            timer.current = window.setTimeout(commit, FADE_MS);
        }
        return () => window.clearTimeout(timer.current);
    }, [visible, data.key]);

    useLayoutEffect(() => {
        renderedRef.current = rendered;
        const body = bodyRef.current;
        if (!body) return;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const rows = [...body.querySelectorAll('tr[data-id]')];
        // 빠지는 줄: 흐려진다.
        if (!reduce) rows.filter(row => row.classList.contains('is-leaving') && !row.dataset.motion).forEach(row => {
            row.dataset.motion = '1';
            row.animate([{ opacity: 1 }, { opacity: 0 }], { duration: FADE_MS, easing: 'ease-out', fill: 'forwards' });
        });
        const before = tops.current;
        tops.current = null;
        if (!before || reduce) return;
        // 남는 줄: 이전 자리에서 새 자리로 미끄러지고, 새로 생기는 줄은 흐릿하게 나타난다.
        const moves = rows.map(row => [row, before.get(row.dataset.id), row.getBoundingClientRect().top]);
        moves.forEach(([row, from, to]) => {
            if (from === undefined) row.animate([{ opacity: 0 }, { opacity: 1 }], { duration: MOVE_MS, easing: 'ease-out' });
            else if (Math.abs(from - to) > 1) row.animate([{ transform: `translateY(${from - to}px)` }, { transform: 'translateY(0)' }], { duration: MOVE_MS, easing: 'cubic-bezier(.4,0,.2,1)' });
        });
    }, [rendered]);

    const loadMore = () => setLimit(value => value + PAGE);

    // 다른 기록 탭의 표와 같은 구조·너비 계산을 쓴다.
    const columns = [['blackInk', '블랙잉크'], ['grayInk', '그레이잉크'], ['standard', 'HOF 스탠다드'], ['monitor', 'HOF 모니터'], ['period', '활동']];
const shortLabels = { standard: '스탠다드', monitor: '모니터' };
    const widths = [40, 108, ...columns.map(([key, label]) => recordColumnWidth(key === 'period' ? '0000-0000' : label, key))];
    const mobileWidths = [32, 92, ...columns.map(([key, label]) => recordColumnWidth(key === 'period' ? '0000-0000' : (shortLabels[key] || label), key, true) + (metric === key ? 8 : 0))];
    const tableStyle = { '--record-table-width': `${widths.reduce((sum, width) => sum + width, 0)}px`, '--record-table-mobile-width': `${mobileWidths.slice(0, -1).reduce((sum, width) => sum + width, 0)}px` };
    const tableClass = 'profile-season-games profile-year-records record-room-table record-room-hof-table has-position';
    const colgroup = <colgroup>{widths.map((width, index) => <col key={index} style={{ '--record-column-width': `${width}px`, '--record-column-mobile-width': `${mobileWidths[index]}px` }} />)}</colgroup>;
    const heading = ([key, label]) => {
        if (key === 'period') return <th key={key} scope="col" className="record-room-hof-period">{label}</th>;
        const sorted = metric === key;
        return <th key={key} scope="col" className={sorted ? 'is-sorted' : undefined} aria-sort={sorted ? 'descending' : 'none'}>
            <button type="button" className="profile-year-sort-heading" onClick={() => setMetric(key)} aria-label={`${label} 정렬`}>{compact && shortLabels[key] ? shortLabels[key] : label}{sorted && <span aria-hidden="true">▾</span>}</button>
        </th>;
    };
    const headRow = <tr><th className="profile-year-cell" scope="col">순위</th><th className="profile-year-team-cell" scope="col">선수</th>{columns.map(heading)}</tr>;
    const empty = !data.loading && !target.length;

    return <>
        <div className="record-room-hof-bar">
            <div className={`record-room-scope${role === 'pitcher' ? ' is-all' : ''}`} role="group" aria-label="타자 투수 선택">
                {roles.map(([key, label]) => <button key={key} type="button" aria-pressed={role === key} onClick={() => setRole(key)}>{label}</button>)}
            </div>
            <label className="record-room-chip"><input type="checkbox" checked={activeOnly} onChange={event => setActiveOnly(event.target.checked)} /><span>현역만</span></label>
        </div>
        <div className="profile-year-table-wrap">
            <div className="profile-table-sticky-head" ref={stickyRef} aria-hidden="true"><div><table className={tableClass} style={tableStyle}>{colgroup}<thead>{headRow}</thead></table></div></div>
            <div className="profile-game-scroll" ref={scrollRef}><table className={`${tableClass}${data.loading ? ' is-loading' : ''}`} aria-busy={data.loading} style={tableStyle}>
                {colgroup}
                <caption className="sr-only">{metrics.find(([key]) => key === metric)[1]} {roles.find(([key]) => key === role)[1]} 순위</caption>
                <thead>{headRow}</thead>
                <tbody ref={bodyRef}>{empty || (data.loading && !rendered.length)
                    ? <tr aria-hidden="true"><td className={data.loading ? 'profile-year-loading-space' : 'profile-year-empty-space'} colSpan={widths.length} /></tr>
                    : rendered.map(({ row, leaving }) => {
                        const logo = teamLogo(row.team);
                        const className = [row.active ? 'is-active-player' : '', leaving ? 'is-leaving' : ''].filter(Boolean).join(' ') || undefined;
                        return <tr key={row.id} data-id={row.id} className={className} style={row.active && teamColorByName(row.team) ? { '--hof-color': teamColorByName(row.team) } : undefined} aria-hidden={leaving || undefined}>
                            <td className="profile-year-cell record-room-rank">{row.rank}</td>
                            <td className="profile-year-team-cell record-room-player"><Link to={`/?pid=${row.id}`} title={[row.name, row.team, row.active ? '현역' : ''].filter(Boolean).join(' ')}>{logo ? <img src={logo} alt="" /> : <i aria-hidden="true" />}<span className={`record-room-name${[...row.name].length >= 5 ? ' is-marquee' : ''}`}><b>{row.name}</b><b aria-hidden="true">{row.name}</b></span></Link></td>
                            {metrics.map(([key]) => <td key={key} className={metric === key ? 'is-sorted' : undefined}>{row.scores[key] > 0 ? scoreText(row.scores[key]) : ''}</td>)}
                            <td className="record-room-hof-period">{row.active ? `${row.first}-` : `${row.first}-${row.last}`}</td>
                        </tr>;
                    })}</tbody>
            </table></div>
            {data.loading && <div className="profile-year-table-loading"><Spinner size="lg" className="fill-blue-600" aria-label="기록 불러오는 중" /></div>}
            {empty && <p className="profile-games-empty profile-year-empty-message">{data.error || '조건에 맞는 선수가 없습니다.'}</p>}
        </div>
        {!data.loading && visible.length < target.length && <button type="button" className="record-room-more" onClick={loadMore}>{`더 보기 (${visible.length}/${target.length})`}</button>}
        <section className={`record-room-hof-note${notesOpen ? ' is-open' : ''}`}>
            <button type="button" className="record-room-hof-note-toggle" aria-expanded={notesOpen} aria-controls="record-room-hof-note-panel" onClick={() => setNotesOpen(open => !open)}>
                <span>기록 설명</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" /></svg>
            </button>
            {notesOpen && <div className="record-room-hof-note-grid" id="record-room-hof-note-panel">
                {metrics.map(([key]) => {
                    const note = descriptions[key];
                    return <article key={key}>
                        <h3>{note.title}</h3>
                        <p>{note.lead}</p>
                        <dl>{note.rows.map(([label, content]) => <div key={label}>
                            <dt>{label}</dt>
                            <dd>{Array.isArray(content)
                                ? <ul>{content.map(([point, text]) => <li key={`${point}${text}`}><b>{point}</b>{text}</li>)}</ul>
                                : content}</dd>
                        </div>)}</dl>
                    </article>;
                })}
            </div>}
        </section>
    </>;
}
