// 기록실 조회 조건·기간 계산과 API 호출. 같은 조건의 응답은 새로고침 전까지 다시 요청하지 않는다.
export const PAGE_SIZE = 50;
export const minOptions = [[100, '규정'], [70, '70%'], [50, '50%'], [30, '30%'], [0, '제한 없음'], ['custom', '직접 입력']];
// 수비 탭의 최소 조건: 수비상 조건(투수 48이닝·포수 72경기·내외야 합계 720이닝)과 그 비율, 유틸리티 수비상 조건, 직접 입력(이닝 또는 경기)
export const fieldingMinOptions = [[100, '수비상 조건'], [70, '70%'], [50, '50%'], [30, '30%'], ['utility', '유틸리티 수비상 조건'], [0, '제한 없음'], ['custom', '직접 입력']];
// 기간: 시즌 안의 일부(전반기·후반기·월)는 고른 시즌마다 적용하고, 날짜 기준(최근 n일 등)은 시즌 선택과 상관없이 그 날짜만 본다.
export const spanOptions = [['full', '시즌 전체'], ['firstHalf', '전반기'], ['secondHalf', '후반기'], ['pickMonth', '월 지정'], ['last7', '최근 7일'], ['last15', '최근 15일'], ['last30', '최근 30일'], ['week', '이번 주'], ['month', '이번 달'], ['custom', '직접 입력']];
export const seasonTypes = [['regular', '정규시즌'], ['postseason', '포스트시즌'], ['preseason', '시범경기'], ['futures', '퓨처스리그']];
export const seasonParts = ['firstHalf', 'secondHalf', 'pickMonth'];
export const isDateSpan = span => span !== 'full' && !seasonParts.includes(span);
// 이름이 바뀐 팀은 한 구단으로 묶는다(해태→KIA, SK→SSG, 넥센→키움, 삼미·청보·태평양→현대 등).
export const franchises = ['KIA', '삼성', 'LG', '두산', 'KT', 'SSG', '롯데', '한화', 'NC', '키움', '현대', '쌍방울'];
// 주 포지션: 타자는 가장 많이 선 포지션, 투수는 선발·구원, 수비는 기록의 포지션
export const positionOptions = {
    batter: [['C', '포수'], ['1B', '1루수'], ['2B', '2루수'], ['3B', '3루수'], ['SS', '유격수'], ['LF', '좌익수'], ['CF', '중견수'], ['RF', '우익수'], ['IF', '내야수'], ['OF', '외야수'], ['DH', '지명타자']],
    pitcher: [['SP', '선발'], ['RP', '구원']],
    fielding: [['P', '투수'], ['C', '포수'], ['1B', '1루수'], ['2B', '2루수'], ['3B', '3루수'], ['SS', '유격수'], ['LF', '좌익수'], ['CF', '중견수'], ['RF', '우익수'], ['IF', '내야수'], ['OF', '외야수']],
};
export const handOptions = {
    batter: [['R', '우타'], ['L', '좌타'], ['S', '양타']],
    pitcher: [['R', '우투'], ['L', '좌투'], ['U', '언더'], ['D', '사이드']],
    fielding: [],
};
// 경기별 기록(기간·구장·상대팀·상대 선수)과 공식 수비 기록은 2001년부터 있다.
export const GAME_LOG_FIRST_YEAR = 2001;
// 특정 타자를 상대한 투수 기록의 열(타석 결과로 알 수 있는 항목만).
export const versusColumns = [['경기', 'games'], ['타석', 'pa'], ['타수', 'ab'], ['피안타', 'h'], ['피홈런', 'hr'], ['볼넷', 'bb'], ['사구', 'hbp'], ['삼진', 'so'], ['피안타율', 'opponentAvg'], ['피출루율', 'opponentObp'], ['피장타율', 'opponentSlg'], ['피OPS', 'opponentOps'], ['K%', 'kPct'], ['BB%', 'bbPct'], ['K/BB', 'kBb'], ['땅볼/뜬공', 'groundFly'], ['BABIP', 'babip']];
// 표의 종류: 타자·투수·수비, 그리고 상대 타자를 지정한 투수 기록(versus).
export const tableKind = (type, filter) => type === 'pitcher' && filter?.vs?.length ? 'versus' : type;
// 상대 선수는 여러 명 고를 수 있고, 그 선수들을 상대한 기록을 합산한다.
export const MAX_OPPONENTS = 10;
// 2001년 이후 기록만 볼 수 있는 조건인지
export const modernOnly = (type, filter) => type === 'fielding' || Boolean(filter.vs?.length || filter.opp || filter.stadium) || filter.span !== 'full';

// 직접 입력하는 최소 조건이 이닝 단위인지(투수 기록, 또는 수비 기록에서 이닝을 고른 경우). 상대 타자를 지정한 투수 기록은 타석이다.
export const customMinInnings = (type, filter) => type === 'fielding' ? filter.minUnit !== 'games' : tableKind(type, filter) === 'pitcher';
// 입력 중인 글자를 걸러낸다: 이닝이면 소수점 아래 0·1·2 한 자리까지, 아니면 숫자만.
export const customMinText = (text, innings) => (innings ? /^\d{0,5}(\.[012]?)?$/ : /^\d{0,5}$/).test(text);

// 낮을수록 좋은 기록은 처음 누를 때 오름차순(좋은 기록이 위)으로 정렬한다.
const lowerBetter = ['era', 'whip', 'fip', 'bb9', 'h9', 'hr9', 'pitchesPerInning', 'opponentAvg', 'opponentObp', 'opponentSlg', 'opponentOps'];
export const defaultDirection = (kind, key) => (kind === 'pitcher' || kind === 'versus') && (lowerBetter.includes(key) || (kind === 'pitcher' && key === 'bbPct')) ? 'asc' : 'desc';
const defaultSortKeys = {
    batter: { basic: 'avg', advanced: 'woba', special: 'sb' },
    pitcher: { basic: 'era', advanced: 'k9', special: 'qs' },
    fielding: { basic: 'innings' },
    versus: { basic: 'pa' },
};
export const defaultSort = (kind, view = 'basic') => { const key = defaultSortKeys[kind][view] || defaultSortKeys[kind].basic; return { key, direction: defaultDirection(kind, key) }; };

const pad = value => String(value).padStart(2, '0');
const isoDate = date => `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
const parseDate = text => new Date(`${text}T00:00:00Z`);
const daysAgo = (text, days) => { const date = parseDate(text); date.setUTCDate(date.getUTCDate() - days); return isoDate(date); };
export const dotDate = text => text.replaceAll('-', '.');

export function defaultFilter(meta) {
    const year = meta.currentYear;
    return {
        seasonType: 'regular', season: year, to: year, span: 'full', month: Number(meta.today.slice(5, 7)), start: meta.seasons[year]?.start || meta.today, end: meta.today,
        career: false, splitTeams: false, foreign: false, prospect: false, goldenGlove: false, award: false, mergePositions: false, team: '', opp: '', stadium: '', pos: '', hand: '', players: [], vs: [], min: 100, minValue: '', minUnit: 'innings', minScope: 'rate', ageBy: 'age', ageMin: '', ageMax: '', birthMin: '', birthMax: '', active: false,
    };
}

// 날짜 기준 기간 → [시작일, 끝일]. 만들 수 없으면 null.
export function dateRange(filter, meta) {
    const today = meta.today;
    if (filter.span === 'last7') return [daysAgo(today, 6), today];
    if (filter.span === 'last15') return [daysAgo(today, 14), today];
    if (filter.span === 'last30') return [daysAgo(today, 29), today];
    if (filter.span === 'week') return [daysAgo(today, (parseDate(today).getUTCDay() + 6) % 7), today];
    if (filter.span === 'month') return [`${today.slice(0, 8)}01`, today];
    return filter.start && filter.end && filter.start <= filter.end && filter.start >= `${GAME_LOG_FIRST_YEAR}-01-01` ? [filter.start, filter.end] : null;
}

// 화면의 조건 → 조회 조건(API 파라미터와 조건 버튼에 보여줄 이름). 잘못된 조건이면 { error }.
export function resolveFilter(filter, meta, type) {
    const modern = modernOnly(type, filter);
    const tooEarly = '2001년 이후 시즌만 볼 수 있는 조건입니다.';
    const spanName = Object.fromEntries(spanOptions)[filter.span];
    const regular = type === 'fielding' || filter.seasonType === 'regular';
    const params = regular ? {} : { season: filter.seasonType };
    let label;
    if (isDateSpan(filter.span)) {
        if (type === 'fielding') return { error: '수비 기록은 시즌 단위로만 볼 수 있습니다.' };
        const range = dateRange(filter, meta);
        if (!range) return { error: '기간을 다시 확인해 주세요.' };
        Object.assign(params, { mode: 'period', start: range[0], end: range[1] });
        label = filter.span === 'custom' ? `${dotDate(range[0])}~${dotDate(range[1])}` : spanName;
    } else {
        if (filter.season > filter.to) return { error: '시작 시즌이 끝 시즌보다 늦습니다.' };
        if (modern && filter.to < GAME_LOG_FIRST_YEAR) return { error: tooEarly };
        const from = modern ? Math.max(filter.season, GAME_LOG_FIRST_YEAR) : filter.season;
        // 여러 시즌을 고르면 시즌마다 따로 한 줄씩 보고, '통산'을 켜면 선수별로 합산한다.
        if (from === filter.to) { Object.assign(params, { mode: 'year', year: from }); label = `${from}`; }
        else if (filter.career) { Object.assign(params, { mode: 'range', from, to: filter.to }); label = `${from}~${filter.to} 통산`; }
        else { Object.assign(params, { mode: 'range', from, to: filter.to, splitSeasons: 1 }); label = `${from}~${filter.to}`; }
        if (filter.span !== 'full') {
            if (type === 'fielding') return { error: '수비 기록은 시즌 단위로만 볼 수 있습니다.' };
            if (!regular) return { error: '전반기·후반기·월 지정은 정규시즌에서만 쓸 수 있습니다.' };
            if (filter.span === 'pickMonth') { Object.assign(params, { part: 'month', month: filter.month }); label += ` ${filter.month}월`; }
            else { params.part = filter.span; label += ` ${spanName}`; }
        }
    }
    // 후보 조건을 켜면 최소 조건은 쓰지 않는다.
    const candidatesOnly = (filter.goldenGlove && (type === 'fielding' || filter.seasonType === 'regular')) || (filter.award && type === 'fielding');
    if (candidatesOnly) params.min = 0;
    else if (filter.min === 'utility') params.min = type === 'fielding' ? 'utility' : 100;
    else if (filter.min === 'custom') {
        // 이닝은 야구 표기(.1 = 1/3이닝, .2 = 2/3이닝)로, 타석·경기 수는 정수로 받는다.
        const innings = customMinInnings(type, filter);
        const text = String(filter.minValue).replace(/\.$/, '');
        if (!(innings ? /^\d{1,5}(\.[012])?$/ : /^\d{1,5}$/).test(text)) return { error: '최소 조건 값을 다시 확인해 주세요.' };
        Object.assign(params, { min: 0, minValue: text }, type === 'fielding' ? { minUnit: filter.minUnit } : {});
    } else params.min = Number(filter.min);
    // 최소 조건은 기본으로 비율 기록 정렬에만 걸리고, '모든 기록'을 고르면 어떤 기록으로 정렬해도 걸린다.
    if (!candidatesOnly && filter.minScope === 'all' && filter.min !== 0) params.minScope = 'all';
    // 나이 범위 또는 출생 연도 범위 중 고른 쪽만 쓴다.
    const [low, high, floor, ceiling, wrong] = filter.ageBy === 'birth' ? ['birthMin', 'birthMax', 1900, 2100, '출생 연도를 다시 확인해 주세요.'] : ['ageMin', 'ageMax', 10, 60, '나이를 다시 확인해 주세요.'];
    for (const key of [low, high]) {
        if (filter[key] === '') continue;
        const value = Number(filter[key]);
        if (!Number.isInteger(value) || value < floor || value > ceiling) return { error: wrong };
        params[key] = value;
    }
    if (params[low] !== undefined && params[high] !== undefined && params[low] > params[high]) return { error: wrong };
    const gameLog = type !== 'fielding';
    if (filter.team) params.team = filter.team;
    if (filter.opp && gameLog) params.opp = filter.opp;
    if (filter.stadium && gameLog) params.stadium = filter.stadium;
    if (filter.pos) params.pos = filter.pos;
    if (filter.hand) params.hand = filter.hand;
    if (filter.splitTeams) params.splitTeams = 1;
    for (const flag of ['active', 'foreign', 'prospect']) if (filter[flag]) params[flag] = 1;
    if (filter.vs?.length && gameLog) params.vs = filter.vs.map(player => player.id).sort((a, b) => a - b).join(',');
    if (filter.players?.length) params.players = filter.players.map(player => player.id).sort((a, b) => a - b).join(',');
    if (filter.goldenGlove && regular) params.gg = 1;
    if (filter.award && type === 'fielding') params.award = 1;
    if (filter.mergePositions && type === 'fielding') params.mergePos = 1;
    if (!regular) label = `${label} ${Object.fromEntries(seasonTypes)[filter.seasonType]}`;
    const extras = [params.players && filter.players.map(player => player.name).join(', '), filter.team, params.gg && '골든글러브 후보', params.award && '수비상 후보', filter.active && '현역', filter.foreign && '외국인', filter.prospect && '유망주', params.opp && `vs ${params.opp}`, params.stadium, params.vs && `vs ${filter.vs.map(player => player.name).join(', ')}`].filter(Boolean);
    return { params, label: [label, ...extras].join(' · ') };
}

let metaRequest = null;
export function loadRecordRoomMeta() {
    metaRequest ||= fetch('/api/playerRecords.php?meta=1').then(async response => {
        const result = await response.json();
        if (!response.ok || !Array.isArray(result.years)) throw new Error(result.error || '기록실 정보를 불러오지 못했습니다.');
        return result;
    }).catch(error => { metaRequest = null; throw error; });
    return metaRequest;
}

const pages = new Map();
export const recordRoomKey = (type, params, sort) => JSON.stringify([type, params, sort.key, sort.direction]);
export function loadRecordRoomPage(type, params, sort, offset) {
    const key = `${recordRoomKey(type, params, sort)}|${offset}`;
    if (!pages.has(key)) {
        const query = new URLSearchParams({ type, ...params, sort: sort.key, dir: sort.direction, offset, limit: PAGE_SIZE });
        const request = fetch(`/api/playerRecords.php?${query}`).then(async response => {
            const result = await response.json();
            if (!response.ok || !Array.isArray(result.rows)) throw new Error(result.error || '기록을 불러오지 못했습니다.');
            return result;
        }).catch(error => { pages.delete(key); throw error; });
        pages.set(key, request);
    }
    return pages.get(key);
}

// 상대 선수 고르기: 메인 검색과 같은 선수 검색 API를 쓴다.
export async function searchPlayers(keyword, signal) {
    const response = await fetch('/api/kbocandle/get_player_list.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: keyword }), signal });
    const result = await response.json();
    const list = result.list || result;
    if (!response.ok || !Array.isArray(list)) throw new Error('검색 실패');
    return list.slice(0, 8);
}
