// 검색 화면의 '최근 본 선수' 저장소. 검색 목록 클릭뿐 아니라 선수 페이지를 연 모든 경로(직접 주소, 가족·비교 링크 등)에서 기록한다.
export const RECENT_PLAYERS_KEY = 'wesiper-recent-players';
const MAX_RECENT = 8;
const sameId = (a, b) => String(a) === String(b);

export function readRecentPlayers() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(RECENT_PLAYERS_KEY) || '[]');
        if (!Array.isArray(saved)) return [];
        // 예전 저장분에 숫자/문자 ID가 섞여 생긴 중복을 정리한다.
        return saved.filter((player, index) => player && player.PlayerId != null && saved.findIndex(other => other && sameId(other.PlayerId, player.PlayerId)) === index).slice(0, MAX_RECENT);
    } catch { return []; }
}
export function writeRecentPlayers(list) {
    try { window.localStorage.setItem(RECENT_PLAYERS_KEY, JSON.stringify(list)); } catch { /* 저장 불가 환경에서는 메모리에만 유지한다. */ }
}
export function recentPlayerEntry(player) {
    const { PlayerId, Name, Team, FormerTeam, IsNumberRetired, NumberRetiredTeam, Pos, MainPos } = player;
    const IsActive = player.IsActive ?? (player.IsKbodle != null ? String(player.IsKbodle) !== '0' : undefined);
    return { PlayerId, Name, Team, FormerTeam, IsActive, IsNumberRetired, NumberRetiredTeam, Pos, MainPos };
}
export function addRecentPlayer(player, previous = readRecentPlayers()) {
    if (!player || player.PlayerId == null || !player.Name) return previous;
    const next = [recentPlayerEntry(player), ...previous.filter(item => !sameId(item.PlayerId, player.PlayerId))].slice(0, MAX_RECENT);
    writeRecentPlayers(next);
    return next;
}
export function removeRecentPlayer(playerId, previous = readRecentPlayers()) {
    const next = playerId == null ? [] : previous.filter(item => !sameId(item.PlayerId, playerId));
    writeRecentPlayers(next);
    return next;
}
