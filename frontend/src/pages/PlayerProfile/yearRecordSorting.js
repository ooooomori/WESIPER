const positionOrder = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF', 'DH'];
const positionRank = position => { const rank = positionOrder.indexOf(positionLabels[position] || position); return rank < 0 ? positionOrder.length : rank; };
const positionLabels = { 투수: 'P', 포수: 'C', '1루수': '1B', '2루수': '2B', '3루수': '3B', 유격수: 'SS', 좌익수: 'LF', 중견수: 'CF', 우익수: 'RF', 지명타자: 'DH', 지명: 'DH' };
export function nextYearRecordSort(previous, key) {
    return { key, direction: previous?.key === key && previous.direction === 'desc' ? 'asc' : 'desc' };
}
function compareRecords(a, b, sort) {
    const value = row => sort.key === 'age' ? row.age : sort.key === 'position' ? positionLabels[row.position ?? row.stats?.position] || row.position || row.stats?.position : row.stats?.[sort.key];
    const left = value(a), right = value(b);
    const missing = value => value === null || value === undefined || value === '';
    if (missing(left) || missing(right)) return Number(missing(left)) - Number(missing(right));
    const comparison = Number.isFinite(Number(left)) && Number.isFinite(Number(right)) ? Number(left) - Number(right) : String(left).localeCompare(String(right), 'en');
    return sort.direction === 'asc' ? comparison : -comparison;
}
export function sortedYearRecordRows(rows, sort, fielding = false) {
    if (!fielding) return sort ? [...rows].sort((a, b) => compareRecords(a, b, sort)).map(row => ({ ...row, teams: row.teams ? [...row.teams].sort((a, b) => compareRecords(a, b, sort)) : [] })) : rows;
    if (!sort) return rows.map(row => ({ ...row, positions: [...row.positions].sort((a, b) => (b.games ?? -1) - (a.games ?? -1) || positionRank(a.position) - positionRank(b.position)) }));
    const positions = rows.flatMap(row => row.positions.map(stats => ({ year: row.year, team: row.team, stats })));
    positions.sort((a, b) => compareRecords(a, b, sort));
    const groups = [];
    for (const row of positions) {
        const last = groups.at(-1);
        if (last?.year === row.year && last.team === row.team) last.positions.push(row.stats);
        else groups.push({ year: row.year, team: row.team, positions: [row.stats] });
    }
    return groups;
}
