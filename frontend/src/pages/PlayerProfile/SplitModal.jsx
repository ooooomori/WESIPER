import useSheetDrag from './useSheetDrag';

const seasonLabels = { regular: '정규시즌', preseason: '시범경기', postseason: '포스트시즌', futures: '퓨처스리그' };
const rate = value => value == null ? '—' : value.toFixed(3).replace(/^0\./, '.');
const splitNames = { month: '월별', opponent: '상대별', inning: '이닝별', venue: '홈/원정' };

function batterCards(row) {
    const t = row.totals || {};
    return {
        rates: [['타율', rate(row.avg)], ['출루율', rate(row.obp)], ['장타율', rate(row.slg)], ['OPS', rate(row.ops)]],
        counts: [['타수', t.ab], ['안타', t.h], ['2루타', t.doubles], ['3루타', t.triples], ['홈런', t.hr], ['타점', t.rbi], ['득점', t.r], ['볼넷', (t.bb ?? 0) + (t.hbp ?? 0)], ['삼진', t.so], ['도루', t.sb], ['도루자', t.cs]],
    };
}
function pitcherCards(row, fipConstants) {
    const t = row.totals || {};
    const outs = Number(t.outs) || 0;
    // 등판 경기의 70% 이상이 구원이면 승-패 대신 홀-세를 앞세우고, 승리·패전은 아래 칸으로 내린다.
    const list = row.list || [];
    const relief = list.length > 0 && list.filter(game => game.isStarter === false).length / list.length >= 0.7;
    // 리그 상수는 연도마다 달라, 통산처럼 여러 해가 섞이면 이닝으로 가중평균한다.
    let weighted = 0, weightedOuts = 0, known = true;
    for (const game of list) {
        const [whole, third = '0'] = String(game.innings ?? '0').split('.');
        const gameOuts = (Number(whole) || 0) * 3 + (Number(third) || 0);
        const constant = fipConstants?.[String(game.date).slice(0, 4)];
        if (!gameOuts) continue;
        if (constant == null) { known = false; break; }
        weighted += constant * gameOuts; weightedOuts += gameOuts;
    }
    const fipConstant = known && weightedOuts ? weighted / weightedOuts : null;
    const fip = outs > 0 && fipConstant != null ? (13 * t.hr + 3 * (t.bb + t.hbp) - 2 * t.so) * 3 / outs + fipConstant : null;
    return {
        rates: [['ERA', row.era == null ? '—' : row.era.toFixed(2)], relief ? ['홀-세', `${t.holds ?? 0}-${t.saves ?? 0}`] : ['승-패', `${t.wins ?? 0}-${t.losses ?? 0}`], ['삼진', t.so], ['WHIP', row.whip == null ? '—' : row.whip.toFixed(2)]],
        counts: [['실점(자책)', `${t.r ?? 0}(${t.er ?? 0})`], ['피안타', t.h], ['피홈런', t.hr], ['볼넷', t.bb], ['사구', t.hbp], ['QS', t.qs], ['FIP', fip == null ? '—' : fip.toFixed(2)], ...(relief ? [['승리', t.wins ?? 0], ['패전', t.losses ?? 0]] : [['홀드', t.holds ?? 0], ['세이브', t.saves ?? 0]])],
    };
}
function gameLine(game, pitcher) {
    if (pitcher) {
        const earned = game.er != null && game.r != null && Number(game.er) !== Number(game.r) ? `(${game.er}자책)` : '';
        return `${game.innings ?? '—'}이닝 ${game.r ?? '—'}실점${earned} ${game.so ?? 0}삼진`;
    }
    const extras = [['hr', '홈런'], ['rbi', '타점'], ['bb', '볼넷'], ['r', '득점'], ['sb', '도루']].filter(([key]) => Number(game[key]) > 0).map(([key, label]) => `${game[key]}${label}`);
    return [`${game.ab ?? '—'}타수 ${game.h ?? '—'}안타`, ...extras].join(' ');
}

const badgeTone = value => value === '패전' ? 'loss' : value === '홀드' ? 'hold' : value === '세이브' ? 'save' : '';

export default function SplitModal({ row, split, pitcher, year, season, fipConstants, onClose }) {
    const { dialog, expanded, close, justDragged, handlers } = useSheetDrag();
    const { rates, counts } = pitcher ? pitcherCards(row, fipConstants) : batterCards(row);
    const games = row.list || [];
    const totals = row.totals || {};
    const summary = pitcher ? `${totals.games ?? row.games}경기 · ${totals.innings ?? '0'}이닝`
        : totals.games != null ? `${totals.games}경기 · ${totals.pa ?? 0}타석` : `${totals.pa ?? row.games}타석`;
    const titleLabel = split === 'opponent' ? `vs ${row.label}` : split === 'inning' ? `${row.label} 타석` : row.label;
    return <dialog ref={dialog} className={`profile-career-modal profile-split-modal${expanded ? ' is-expanded' : ''}`} aria-labelledby="profile-split-title" onClose={onClose}
        onClick={event => { if (event.target !== event.currentTarget || justDragged.current) return; const box = event.currentTarget.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close(); }}>
        <header className="profile-career-modal-head profile-split-modal-head" {...handlers}>
            <div className="profile-career-title">
                <small>{[year === 'all' ? '통산' : year, seasonLabels[season]].filter(Boolean).join(' ')}</small>
                <h2 id="profile-split-title">{titleLabel}<span>{summary}</span></h2>
            </div>
            <button type="button" className="profile-career-close" aria-label="닫기" onClick={close}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" /></svg></button>
            <div className="profile-split-modal-rates">{rates.map(([label, value]) => <div key={label}><span>{label}</span><strong>{value ?? '—'}</strong></div>)}</div>
        </header>
        <div className="profile-split-modal-body">
            <dl className={`profile-split-modal-counts${pitcher ? ' is-three' : ''}`}>{counts.filter(([, value]) => value != null).map(([label, value]) => <div key={label}><span>{label}</span><strong>{value}</strong></div>)}</dl>
            {games.length > 0 && <>
                <h3>경기별 기록</h3>
                <ul className="profile-split-modal-games">{[...games].sort((a, b) => String(a.date).localeCompare(String(b.date))).map(game => <li key={game.gameId || game.date}>
                    <time dateTime={game.date}>{String(game.date).slice(5).replace('-', '.')}</time>
                    <b>{game.opponent ? `${game.isAway ? '@' : 'vs'} ${game.opponent}` : '—'}</b>
                    <span className={`profile-split-modal-line${!pitcher && Number(game.hr) > 0 ? ' is-good' : ''}`}>{gameLine(game, pitcher)}{pitcher && game.badge ? <span className={`profile-game-badge ${badgeTone(game.badge)}`}>{game.badge}</span> : null}</span>
                </li>)}</ul>
            </>}
        </div>
    </dialog>;
}
