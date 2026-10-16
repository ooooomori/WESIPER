import { useEffect, useRef, useState } from 'react';

// 시리즈 하나의 진행 상황: 두 팀, 승수, 이긴 팀.
function resolveSeries(key, games, teams, seedOf) {
    const names = games.length ? [...new Set(games.flatMap((game) => [game.away, game.home]))].slice(0, 2) : teams;
    const wins = Object.fromEntries(names.filter(Boolean).map((name) => [name, 0]));
    games.forEach((game) => {
        if (game.state !== 'final' || game.awayScore === game.homeScore) return;
        wins[game.awayScore > game.homeScore ? game.away : game.home] += 1;
    });
    let winner = null;
    if (key === 'wildcard') {
        // 4위는 1승이면 올라가고, 5위는 2승이 필요하다.
        const [upper, lower] = [...names].sort((a, b) => (seedOf[a] ?? 9) - (seedOf[b] ?? 9));
        if (upper && wins[upper] >= 1) winner = upper;
        else if (lower && wins[lower] >= 2) winner = lower;
    } else {
        const need = key === 'final' ? 4 : 3;
        winner = names.find((name) => wins[name] >= need) || null;
    }
    return { names: names.length === 2 ? names : teams, wins, winner };
}

export default function Postseason({ rows, TeamLogo, getTeamStyle }) {
    const [state, setState] = useState({ status: 'loading', series: null });
    const boxRef = useRef(null);
    const [width, setWidth] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        const load = async () => {
            try {
                const response = await fetch('/api/postseason.php?v=1', { signal: controller.signal });
                const data = await response.json();
                if (!response.ok || data.code !== '100') throw new Error('Invalid postseason');
                if (!controller.signal.aborted) setState({ status: 'ready', series: data.series });
            } catch {
                if (!controller.signal.aborted) setState((previous) => previous.series ? previous : { status: 'error', series: null });
            }
        };
        load();
        const timer = window.setInterval(load, 300000);
        return () => { controller.abort(); window.clearInterval(timer); };
    }, []);

    // 대진표 실제 폭을 재서 선을 픽셀 단위로 그린다.
    useEffect(() => {
        const node = boxRef.current;
        if (!node) return undefined;
        const observer = new ResizeObserver(() => setWidth(node.clientWidth));
        observer.observe(node);
        setWidth(node.clientWidth);
        return () => observer.disconnect();
    }, [state.status]);

    if (state.status === 'loading') return <p className="main-home-ranking-pending" role="status">포스트시즌 대진을 불러오는 중</p>;
    if (state.status === 'error') return <p className="main-home-ranking-pending" role="status">포스트시즌 대진을 불러오지 못했습니다.</p>;

    const seeds = rows.slice(0, 5).map((row) => row[1]);
    const seedOf = Object.fromEntries(seeds.map((name, index) => [name, index + 1]));
    const wildcard = resolveSeries('wildcard', state.series.wildcard, [seeds[3], seeds[4]], seedOf);
    const semi = resolveSeries('semi', state.series.semi, [seeds[2], wildcard.winner], seedOf);
    const playoff = resolveSeries('playoff', state.series.playoff, [seeds[1], semi.winner], seedOf);
    const final = resolveSeries('final', state.series.final, [seeds[0], playoff.winner], seedOf);
    const champion = final.winner;


    // 트리 배치: 아래 한 줄에 5위부터 1위까지 놓고 라운드마다 한 칸씩 위로 계단처럼 올라간다. 경기 수만큼 층 사이 간격을 벌려 경기 목록이 선과 겹치지 않게 한다.
    const compact = width > 0 && width < 480;
    const U = compact ? { logo: 28, row: 15, pill: 20, r: 6, name: 14 } : { logo: 38, row: 17, pill: 24, r: 9, name: 17 };
    const series = [
        { key: 'wildcard', title: '와일드카드', result: wildcard, left: seeds[4], right: seeds[3] },
        { key: 'semi', title: '준플레이오프', result: semi, left: wildcard.winner, right: seeds[2] },
        { key: 'playoff', title: '플레이오프', result: playoff, left: semi.winner, right: seeds[1] },
        { key: 'final', title: '한국시리즈', result: final, left: playoff.winner, right: seeds[0] },
    ];
    // 위에서부터 한국시리즈, 플레이오프, 준플레이오프, 와일드카드 가로선의 높이.
    const count = (key) => state.series[key].length;
    const yLine = { final: U.pill / 2 + 2 };
    yLine.playoff = yLine.final + U.pill + 10 + count('final') * U.row;
    yLine.semi = yLine.playoff + U.pill + 10 + count('playoff') * U.row;
    yLine.wildcard = yLine.semi + U.pill + 10 + count('semi') * U.row;
    const teamTop = yLine.wildcard + U.pill / 2 + 12 + count('wildcard') * U.row;
    const height = teamTop + U.logo + U.name + 3;
    // 모바일은 양 끝 팀을 가장자리에 붙이고 와일드카드 두 팀 사이를 가장 넓게 벌린다.
    const colW = compact ? 32 : width / 5;
    const edge = 16, wcGap = 96, gap = (width - 2 * edge - wcGap) / 3;
    const centers = compact ? [edge, edge + wcGap, edge + wcGap + gap, edge + wcGap + 2 * gap, width - edge] : [0, 1, 2, 3, 4].map((col) => (width / 5) * (col + 0.5));
    const cx = (col) => centers[col];
    const lost = new Set();
    series.forEach(({ result }) => { if (result.winner) result.names.forEach((name) => { if (name && name !== result.winner) lost.add(name); }); });

    // 선: 시리즈마다 왼쪽 다리, 오른쪽 다리, 위층으로 오르는 승자 줄기. 두 팀이 정해진 시리즈는 양쪽, 끝난 시리즈는 이긴 쪽만 진하게 칠한다.
    const paths = [];
    const mids = [];
    series.forEach((item, index) => {
        const y = yLine[item.key], r = U.r, { winner } = item.result;
        const xl = index === 0 ? cx(0) : mids[index - 1], xr = cx(index === 0 ? 1 : index + 1);
        const mid = (xl + xr) / 2;
        mids.push(mid);
        const leg = (x, fromY) => `M${x} ${fromY}V${y + r}Q${x} ${y} ${x + (x < mid ? r : -r)} ${y}H${mid}`;
        const prev = series[index - 1];
        // 두 팀이 모두 정해지면 끝나기 전에도 양쪽 경로를 진하게, 끝난 뒤에는 이긴 팀만 진하게 칠한다.
        const both = Boolean(item.left && item.right);
        const lit = (name) => (winner ? winner === name : both);
        if (index === 0) paths.push({ d: leg(xl, teamTop - 4), on: lit(item.left), name: item.left });
        else {
            paths.push({ d: `M${xl} ${yLine[prev.key]}V${y + r}Q${xl} ${y} ${xl + r} ${y}`, on: lit(item.left), name: item.left });
            paths.push({ d: `M${xl + r} ${y}H${mid}`, on: lit(item.left), name: item.left });
        }
        paths.push({ d: leg(xr, teamTop - 4), on: lit(item.right), name: item.right });
    });
    const day = '일월화수목금토';
    // 진한 선과 필 테두리는 팀 색으로 칠하고, 색이 없거나 너무 밝으면 남색으로 대신한다.
    const lineColor = (name) => {
        const color = name && getTeamStyle(name).color;
        const hex = typeof color === 'string' && /^#[0-9a-f]{6}$/i.test(color) ? color : null;
        if (!hex) return '#102746';
        const [red, green, blue] = [1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16));
        return 0.299 * red + 0.587 * green + 0.114 * blue > 190 ? '#102746' : hex;
    };

    return (
        <div className="main-home-bracket-scroll">
            <div ref={boxRef} className={`main-home-bracket${compact ? ' is-compact' : ''}`} role="group" aria-label="포스트시즌 대진표" style={{ height: width > 0 ? height : 0 }}>
                {width > 0 && <>
                    <svg width={width} height={height} aria-hidden="true">
                        {paths.map((path, index) => <path key={index} d={path.d} className={path.on ? 'is-on' : undefined} style={path.on ? { stroke: lineColor(path.name) } : undefined} />)}
                    </svg>
                    {[4, 3, 2, 1, 0].map((col) => {
                        const name = seeds[4 - col];
                        if (!name) return null;
                        return (
                            <div key={name} className={`main-home-bracket-team${lost.has(name) ? ' is-out' : ''}${name === champion ? ' is-champion' : ''}`} style={{ left: cx(col) - colW / 2, width: colW, top: teamTop, '--logo': `${U.logo}px` }}>
                                <TeamLogo name={name} logo={getTeamStyle(name).logo} />
                                <strong>{name}</strong>
                            </div>
                        );
                    })}
                    {series.map((item, index) => {
                        // 경기 점수는 팀 이름으로 매겨 아래 두 다리와 같은 왼쪽 오른쪽 순서로 보인다.
                        const leftName = item.left || item.result.names.find((name) => name && name !== item.right);
                        const y = yLine[item.key], x = mids[index];
                        return (
                            <div key={item.key}>
                                <span className={`main-home-bracket-pill${item.result.winner ? ' is-done' : ''}`} style={{ left: x, top: y, height: U.pill, ...(item.result.winner && { borderColor: lineColor(item.result.winner) }) }}>{item.title}</span>
                                <div className="main-home-bracket-games" style={{ left: x, top: y + U.pill / 2 + 3 }}>
                                    {state.series[item.key].map((game) => {
                                        const done = game.state === 'final';
                                        const leftIsAway = game.away === leftName;
                                        const l = leftIsAway ? game.awayScore : game.homeScore, r = leftIsAway ? game.homeScore : game.awayScore;
                                        const month = game.date.slice(4, 6) * 1, date = game.date.slice(6, 8);
                                        const weekday = day[new Date(game.date.slice(0, 4), month - 1, date).getDay()];
                                        return (
                                            <p key={game.id} className={done ? undefined : 'is-scheduled'} style={{ height: U.row }}>
                                                <span>{month}.{date}{!compact && ` (${weekday})`}</span>
                                                {done && <><b className={l > r ? 'is-win' : undefined}>{l}</b><i>:</i><b className={r > l ? 'is-win' : undefined}>{r}</b></>}
                                            </p>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </>}
            </div>
        </div>
    );
}
