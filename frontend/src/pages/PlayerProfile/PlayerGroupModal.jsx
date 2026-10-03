import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import useSheetDrag from './useSheetDrag';

const schoolLevels = { 초: '초등학교', 중: '중학교', 고: '고등학교', 대: '대학교' };
const isAlumniName = name => /^[\p{L}\p{N} .]{2,30}(초|중|고|대|리틀)$/u.test(name);
// 학력의 한 토막("화곡초(강서구리틀)", "(방송통신대)", "동의대(얼리 드래프트)")을 글자 조각으로 나눈다.
// name이 있는 조각은 동문 조회가 되는 학교(초·중·고·대)나 리틀야구단이다.
export const alumniSchoolChunks = part => part.split(/(\([^)]*\))/).filter(Boolean).flatMap(chunk => {
    if (chunk.startsWith('(')) {
        const inner = chunk.slice(1, -1).trim();
        return isAlumniName(inner) ? [{ text: '(' }, { text: inner, name: inner }, { text: ')' }] : [{ text: chunk }];
    }
    return [{ text: chunk, name: isAlumniName(chunk.trim()) ? chunk.trim() : null }];
});

// 이름이 바뀐 학교는 지금 이름으로 보여준다(서버의 ALUMNI_SCHOOL_ALIASES와 같은 묶음).
const currentSchoolName = { 군산상고: '군산상일고', 덕수정보고: '덕수고', 덕수정보산업고: '덕수고', 덕수상고: '덕수고' };
// 모달 제목용 정식 이름: "부산고" → "부산고등학교", "군산상고" → "군산상업고등학교", "안산공고" → "안산공업고등학교".
// "건대부중"처럼 대학 부속 학교의 줄임말은 풀어 쓰기 어려워 그대로 둔다.
const schoolFullName = name => {
    if (/리틀$/.test(name)) return `${name.replace(/리틀$/, '').trim()} 리틀야구단`;
    if (/부(초|중|고)$/.test(name)) return name;
    if (/상고$/.test(name)) return name.replace(/상고$/, '상업고등학교');
    if (/공고$/.test(name)) return name.replace(/공고$/, '공업고등학교');
    return name + (schoolLevels[name.slice(-1)] || '').slice(1);
};

// 입단 기록("07 롯데 2차 4라운드 29순위")에서 입단 연도·지명 구단·지명 내용을 꺼낸다.
export const parseDraft = draft => {
    const match = String(draft || '').trim().match(/^(\d{4}|\d{2})\s+(\S+)(?:\s+(.+))?$/);
    if (!match) return null;
    const short = Number(match[1]);
    const year = match[1].length === 4 ? short : short >= 82 ? 1900 + short : 2000 + short;
    const detail = (match[3] || '').trim();
    const pick = detail.match(/(\d+)순위/);
    const round = detail.match(/^(?:(\d)차\s*)?(\d+)라운드/);
    // 묶음 이름과 순서: 1차 지명 → 라운드 순 → 그 밖의 지명(특별지명 등) → 육성선수 → 기록 없음
    const [roundLabel, roundOrder] = /^1차/.test(detail) ? ['1차 지명', 0]
        : round ? [`${round[1] ? `${round[1]}차 ` : ''}${round[2]}라운드`, Number(round[2])]
        : /육성/.test(detail) ? ['육성선수', 900] : detail ? [detail, 800] : ['기타', 999];
    // 전체 지명 순서: 순위가 있으면 그 순위, 1차 지명은 맨 앞, 나머지(육성선수 등)는 맨 뒤
    return { year, team: match[2], detail: detail === '1차' ? '1차 지명' : detail, pick: pick ? Number(pick[1]) : null, roundLabel, roundOrder, order: pick ? Number(pick[1]) : /^1차/.test(detail) ? 0 : 9999 };
};

const icons = {
    school: <><path d="M2.5 9 12 4.5 21.5 9 12 13.5 2.5 9Z" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" /><path d="M6.5 11.2v4.3c1.5 1.4 3.4 2 5.5 2s4-.6 5.5-2v-4.3M21.5 9v5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></>,
    draft: <><rect x="5" y="4.5" width="14" height="16" rx="2.5" stroke="currentColor" strokeWidth="1.8" /><path d="M9 3.5h6v3H9zM8.5 11h7M8.5 14.5h7M8.5 18h4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></>,
    birthday: <><path d="M4 20.5h16M5.5 20.5v-7A1.5 1.5 0 0 1 7 12h10a1.5 1.5 0 0 1 1.5 1.5v7M5.5 16c1.6 0 1.6 1.2 3.25 1.2S10.4 16 12 16s1.6 1.2 3.25 1.2S16.9 16 18.5 16M12 12V8.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /><path d="M12 4c.9 1 1.4 1.7 1.4 2.4a1.4 1.4 0 0 1-2.8 0C10.6 5.7 11.1 5 12 4Z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" /></>,
};

// 선수 묶음 목록 모달: 같은 학교·리틀야구단(school), 같은 해 입단(draft), 같은 생일(birthday).
// group = { type, value }, getLogo(team)은 팀의 작은 로고 주소를 돌려준다.
export default function PlayerGroupModal({ group, currentPid, getLogo, onClose }) {
    const { type } = group;
    // 같은 생일·입단 동기 모달은 머리 부분에서 날짜·연도를 앞뒤로 넘기거나 직접 고를 수 있다.
    const [value, setValue] = useState(group.value);
    const [loading, setLoading] = useState(true);
    // 수상 경력 모달과 같은 동작: 머리 부분을 아래로 끌면 닫히고, 위로 끌면 가득 펼쳐진다.
    const { dialog, expanded, close, justDragged, handlers: dragHandlers } = useSheetDrag();
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        // 날짜를 넘길 때 목록이 사라졌다 나타나며 모달 높이가 출렁이지 않게, 새 목록이 올 때까지 이전 목록을 둔다.
        setLoading(true); setError('');
        fetch(`/api/playerGroup.php?type=${encodeURIComponent(type)}&value=${encodeURIComponent(value)}`, { signal: controller.signal })
            .then(response => response.ok ? response.json() : Promise.reject(new Error('failed')))
            .then(result => { if (!Array.isArray(result.players)) throw new Error('invalid'); setData(result); setLoading(false); })
            .catch(reason => { if (reason.name !== 'AbortError') { setData(null); setLoading(false); setError('선수 목록을 불러오지 못했습니다.'); } });
        return () => controller.abort();
    }, [type, value]);
    // 리틀야구단은 학교가 아니라서 '동문' 대신 '출신'이라고 쓴다.
    const little = type === 'school' && /리틀$/.test(value);
    const [month, day] = type === 'birthday' ? value.split('-').map(Number) : [];
    const text = type === 'draft' ? { label: '입단 동기', title: `${value}년 입단`, people: '입단 동기' }
        : type === 'birthday' ? { label: '같은 생일', title: `${month}월 ${day}일`, people: '생일이 같은 선수' }
        : { label: little ? '리틀야구단 출신' : `${schoolLevels[value.slice(-1)] || '학교'} 동문`, title: schoolFullName(data?.school || currentSchoolName[value] || value), people: little ? '출신 선수' : '동문 선수' };
    // 2월 29일도 고를 수 있게 윤년(2024년)을 기준으로 날짜를 센다.
    const monthDay = date => `${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const shiftDay = step => setValue(monthDay(new Date(2024, month - 1, day + step)));
    const pickDate = (nextMonth, nextDay) => setValue(monthDay(new Date(2024, nextMonth - 1, Math.min(nextDay, new Date(2024, nextMonth, 0).getDate()))));
    // 입단 연도: 프로 원년(1982년)부터 내년 입단까지
    const draftYears = Array.from({ length: new Date().getFullYear() + 1 - 1982 + 1 }, (_, index) => 1982 + index);
    const shiftYear = step => { const next = Number(value) + step; if (draftYears.includes(next)) setValue(String(next)); };
    const players = data?.players || [];
    let groups;
    if (type === 'draft') {
        // 입단 동기는 라운드별로 묶고, 그 안에서는 지명 순서(순위가 없으면 지명 구단 이름순)대로 보여준다.
        const drafted = players.map(player => ({ ...player, draft: parseDraft(player.Draft) }))
            .sort((a, b) => (a.draft?.roundOrder ?? 999) - (b.draft?.roundOrder ?? 999) || (a.draft?.order ?? 9999) - (b.draft?.order ?? 9999) || String(a.draft?.team).localeCompare(String(b.draft?.team), 'ko'));
        const byRound = new Map();
        for (const player of drafted) { const label = player.draft?.roundLabel || '기타'; byRound.set(label, [...(byRound.get(label) || []), player]); }
        groups = [...byRound];
    } else groups = [['현역', players.filter(player => player.IsActive)], ['은퇴', players.filter(player => !player.IsActive)]].filter(([, list]) => list.length);
    // 입단 동기 모달의 라운드 탭: 누르면 그 라운드로 이동하고, 목록을 내리면 보고 있는 라운드가 표시된다.
    const body = useRef(null);
    const tabs = useRef(null);
    const [activeGroup, setActiveGroup] = useState(null);
    const showTabs = type === 'draft' && groups.length > 1;
    const sectionTop = section => section.getBoundingClientRect().top - body.current.getBoundingClientRect().top + body.current.scrollTop;
    const jumpTo = label => {
        const section = [...body.current.querySelectorAll('section')].find(item => item.dataset.group === label);
        if (section) body.current.scrollTo({ top: sectionTop(section), behavior: 'smooth' });
    };
    const trackGroup = () => {
        if (!showTabs) return;
        const sections = [...body.current.querySelectorAll('section')];
        // 끝까지 내렸으면 마지막 라운드, 아니면 맨 위에 걸려 있는 라운드
        const atEnd = body.current.scrollTop + body.current.clientHeight >= body.current.scrollHeight - 2;
        const current = atEnd ? sections[sections.length - 1] : sections.filter(section => sectionTop(section) <= body.current.scrollTop + 8).pop() || sections[0];
        setActiveGroup(current?.dataset.group || null);
    };
    // 날짜·연도를 바꾸면 목록을 맨 위로 되돌린다.
    useEffect(() => { body.current?.scrollTo({ top: 0 }); setActiveGroup(null); }, [value]);
    // 입단 동기 목록이 뜨면 지금 보고 있는 선수의 라운드부터 보여준다.
    useEffect(() => {
        if (type !== 'draft' || loading || !data) return;
        const section = body.current?.querySelector('.is-self')?.closest('section');
        if (!section) return;
        body.current.scrollTo({ top: sectionTop(section) });
        setActiveGroup(section.dataset.group);
    }, [data, loading]);
    const shownGroup = activeGroup && groups.some(([label]) => label === activeGroup) ? activeGroup : groups[0]?.[0];
    useEffect(() => {
        // 표시된 탭이 가려져 있으면 탭 줄을 옆으로 밀어 보이게 한다.
        const tab = tabs.current?.querySelector('[aria-pressed="true"]');
        if (tab) tabs.current.scrollTo({ left: tab.offsetLeft - (tabs.current.clientWidth - tab.offsetWidth) / 2, behavior: 'smooth' });
    }, [shownGroup]);
    const row = player => {
        // 입단 동기 목록의 로고는 지명한 구단(로고가 없으면 지금 팀), 나머지는 지금(마지막) 팀
        const logoTeam = type === 'draft' && player.draft?.team && getLogo(player.draft.team) ? player.draft.team : player.Team;
        const logo = logoTeam ? getLogo(logoTeam) : null;
        const self = String(player.PlayerId) === String(currentPid);
        const team = player.Team ? (player.IsActive ? player.Team : `前 ${player.Team}`) : null;
        // 입단 동기 목록의 포지션은 세부 포지션(선발투수, 유격수) 대신 등록 포지션(투수, 내야수)을 쓴다.
        const meta = type === 'draft' ? [player.draft?.pick ? `${player.draft.pick}순위` : null, team, player.BasePos || player.Pos] : [team, player.Pos, player.Birth ? `${String(player.Birth).slice(0, 4)}년생` : null];
        const content = <>
            <span className="profile-alumni-logo">{logo ? <img src={logo} alt="" /> : <b>{(logoTeam || '—').slice(0, 2)}</b>}</span>
            <span className="profile-alumni-name"><strong>{player.Name}</strong>{player.BackNo && <small>#{player.BackNo}</small>}{self && <em>현재 선수</em>}</span>
            <span className="profile-alumni-meta">{meta.filter(Boolean).join(' · ')}</span>
            {!self && <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" /></svg>}
        </>;
        return <li key={player.PlayerId}>{self ? <div className="profile-alumni-row is-self">{content}</div>
            : <Link className="profile-alumni-row" to={`/?pid=${encodeURIComponent(player.PlayerId)}`} onClick={() => { close(); window.scrollTo({ top: 0, left: 0, behavior: 'instant' }); }}>{content}</Link>}</li>;
    };
    return <dialog ref={dialog} className={`profile-career-modal profile-alumni-modal${expanded ? ' is-expanded' : ''}`} aria-labelledby="profile-alumni-title" onClose={onClose} onClick={event => { if (event.target !== event.currentTarget || justDragged.current) return; const box = event.currentTarget.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close(); }}>
        <header className="profile-career-modal-head" {...dragHandlers}>
            <div className="profile-career-emblem"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">{icons[type]}</svg></div>
            <div className="profile-career-title">
                <small>{text.label}</small>
                <h2 id="profile-alumni-title">{text.title}</h2>
                <p>{data && !loading ? <strong>{players.length}명</strong> : <span>불러오는 중</span>}{type === 'birthday' && <>
                    <button type="button" className="profile-birthday-step" aria-label="전날" onClick={() => shiftDay(-1)}><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 5-7 7 7 7" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
                    <select aria-label="월" value={month} onChange={event => pickDate(Number(event.target.value), day)}>{Array.from({ length: 12 }, (_, index) => <option key={index + 1} value={index + 1}>{index + 1}월</option>)}</select>
                    <select aria-label="일" value={day} onChange={event => pickDate(month, Number(event.target.value))}>{Array.from({ length: new Date(2024, month, 0).getDate() }, (_, index) => <option key={index + 1} value={index + 1}>{index + 1}일</option>)}</select>
                    <button type="button" className="profile-birthday-step" aria-label="다음 날" onClick={() => shiftDay(1)}><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
                </>}{type === 'draft' && <>
                    <button type="button" className="profile-birthday-step" aria-label="이전 해" disabled={Number(value) <= draftYears[0]} onClick={() => shiftYear(-1)}><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 5-7 7 7 7" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
                    <select aria-label="입단 연도" value={value} onChange={event => setValue(event.target.value)}>{[...draftYears].reverse().map(year => <option key={year} value={year}>{year}년</option>)}</select>
                    <button type="button" className="profile-birthday-step" aria-label="다음 해" disabled={Number(value) >= draftYears[draftYears.length - 1]} onClick={() => shiftYear(1)}><svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
                </>}</p>
            </div>
            <button type="button" className="profile-career-close" aria-label="닫기" onClick={close}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg></button>
        </header>
        {showTabs && <div className="profile-alumni-tabs" ref={tabs} role="group" aria-label="라운드로 이동">{groups.map(([label, list]) => <button key={label} type="button" aria-pressed={shownGroup === label} onClick={() => jumpTo(label)}>{label}<span>{list.length}</span></button>)}</div>}
        <div ref={body} onScroll={trackGroup} className={`profile-alumni-body${loading && data ? ' is-loading' : ''}`}>
            {error ? <p className="profile-alumni-empty">{error}</p> : !data ? <p className="profile-alumni-empty">{text.people}를 찾는 중이에요.</p> : !players.length ? <p className="profile-alumni-empty">등록된 {text.people}가 없습니다.</p>
                : groups.map(([label, list]) => <section key={label} data-group={label}><h3>{label}<span>{list.length}</span></h3><ul>{list.map(row)}</ul></section>)}
        </div>
    </dialog>;
}
