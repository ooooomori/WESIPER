export function gameParticipants(game) {
    const state = String(game.GAME_STATE_SC ?? '');
    const person = (role, name) => name?.trim() ? `${role} ${name.trim()}` : '';
    if (state === '4') return { awayPlayer: '', homePlayer: '' };
    if (state === '3') {
        const awayScore = Number(game.away_score ?? game.T_SCORE_CN);
        const homeScore = Number(game.home_score ?? game.B_SCORE_CN);
        if (!Number.isFinite(awayScore) || !Number.isFinite(homeScore) || awayScore === homeScore) return { awayPlayer: '', homePlayer: '' };
        const winner = person('승', game.W_PIT_P_NM);
        const loser = person('패', game.L_PIT_P_NM);
        return awayScore > homeScore ? { awayPlayer: winner, homePlayer: loser } : { awayPlayer: loser, homePlayer: winner };
    }
    // 진행 중인 경기도 예정 경기처럼 선발 투수만 보여준다(실시간 투수·타자는 받지 않는다).
    return { awayPlayer: person('선발', game.T_PIT_P_NM), homePlayer: person('선발', game.B_PIT_P_NM) };
}
