import { teamFullName } from '../../lib/teamFullName';

const aliases = { HT: 'KIA', OB: '두산', SS: '삼성', LT: '롯데', HH: '한화', WO: '키움', NX: '키움', SK: 'SSG' };
const historicalColors = {
    해태: '#ea0029', 빙그레: '#f37321', 현대: '#007f4e', 쌍방울: '#d71920',
    삼미: '#005bac', 청보: '#e33a36', 태평양: '#f58220',
    OB: '#131d40', MBC: '#0071bc', SK: '#e51937',
    우리: '#820024', 히어로즈: '#820024', 넥센: '#820024',
};
const historicalNames = Object.fromEntries(Object.entries(historicalColors).flatMap(([name, color]) => [[name, color], [teamFullName(name), color]]));

export function recordTeamColor(name, teams) {
    const normalized = String(name ?? '').trim().toUpperCase();
    return historicalNames[normalized]
        || teams[aliases[normalized] || normalized]?.[1]
        || Object.values(teams).find(team => team[3].toUpperCase() === normalized)?.[1]
        || '#182536';
}
