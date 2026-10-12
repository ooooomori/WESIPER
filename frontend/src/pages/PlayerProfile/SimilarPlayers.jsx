import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import PlayerSilhouette from '../../components/PlayerSilhouette';
import { teamCapByName } from '../../lib/teamAssets';
import { getCachedProfileData, loadProfileData } from './profileDataCache';

// 사진이 없으면 마지막 소속팀 모자를 쓴 실루엣을 보여준다.
function SimilarPhoto({ player }) {
    const [step, setStep] = useState(0);
    if (step >= 2) return <PlayerSilhouette cap={teamCapByName(player.Team, player.Year)} />;
    return <img src={`/assets/images/player/kbo/${encodeURIComponent(player.PlayerId)}.${step === 0 ? 'jpg' : 'png'}`} alt="" loading="lazy" onError={() => setStep(value => value + 1)} />;
}

// 비슷한 선수: 통산 기록의 유사도 점수가 높은 순. 사진과 이름만 그 선수 페이지로 이어진다.
// 현역 선수는 지금 나이까지의 누적 기록끼리 비교한 목록(byAge)도 고를 수 있다.
export default function SimilarPlayers({ pid }) {
    const url = `/api/playerProfile.php?pid=${encodeURIComponent(pid)}&part=similar`;
    const ageUrl = `${url}&byAge=1`;
    const list = data => data?.similar?.players || null;
    const [career, setCareer] = useState(() => getCachedProfileData(url) || null);
    const [byAge, setByAge] = useState(() => list(getCachedProfileData(ageUrl)));
    const [ageMode, setAgeMode] = useState(false);
    useEffect(() => {
        let active = true;
        loadProfileData(url, data => Object.hasOwn(data, 'similar')).then(data => { if (active) setCareer(data); }).catch(() => {});
        return () => { active = false; };
    }, [url]);
    const age = career?.age ?? null;
    const careerPlayers = list(career) || [];
    // 통산 기준에 못 미치는 현역 선수는 나이 기준 목록만 보여준다.
    const showAge = age !== null && (ageMode || (career !== null && !careerPlayers.length));
    useEffect(() => {
        if (!showAge || byAge) return undefined;
        let active = true;
        loadProfileData(ageUrl, data => Object.hasOwn(data, 'similar')).then(data => { if (active) setByAge(list(data) || []); }).catch(() => { if (active) setByAge([]); });
        return () => { active = false; };
    }, [showAge, ageUrl, byAge]);
    const loading = showAge && !byAge;
    // 나이 기준 목록을 받는 동안에는 보고 있던 목록을 흐리게 둔다.
    const players = showAge && byAge ? byAge : careerPlayers;
    if (!players.length && !loading) return null;
    const toTop = () => window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    // 나이 기준 목록은 그 나이였던 시즌과 당시 팀을 적는다.
    const teamText = player => !player.Team ? '' : showAge && byAge ? `${player.Year} ${player.Team}` : player.IsActive ? player.Team : `前 ${player.Team}`;
    return <section><div className="profile-heading"><h2>비슷한 선수</h2>
        {age !== null && careerPlayers.length > 0 && <div className={`profile-game-switch ${ageMode ? 'is-detailed' : ''}`} role="group" aria-label="비교 기준">{['통산', `${age}세까지`].map((label, i) => <button key={label} type="button" className={ageMode === Boolean(i) ? 'active' : ''} aria-pressed={ageMode === Boolean(i)} onClick={() => setAgeMode(Boolean(i))}>{label}</button>)}</div>}
        {showAge && !careerPlayers.length && <small className="profile-season-caption">{age}세까지</small>}
    </div>
        <ol className={`profile-similar${loading ? ' is-loading' : ''}`} aria-busy={loading}>{players.map((player, index) => { const to = `/?pid=${encodeURIComponent(player.PlayerId)}`; return <li key={player.PlayerId} className="profile-similar-row">
            <span className="profile-similar-rank">{index + 1}</span>
            <Link className="profile-similar-photo" to={to} onClick={toTop} tabIndex={-1} aria-hidden="true"><SimilarPhoto player={player} /></Link>
            <Link className="profile-similar-name" to={to} onClick={toTop}>{player.Name}</Link>
            <span className="profile-similar-team">{teamText(player)}</span>
            <span className="profile-similar-score">{Number(player.Score).toFixed(1)}</span>
        </li>; })}</ol>
        <p className="profile-similar-caption">클래식 기록을 기반으로 성적의 유사도를 1000점 만점으로 나타낸 점수입니다. <a href="https://www.baseball-reference.com/about/similarity.shtml" target="_blank" rel="noopener noreferrer">빌 제임스의 공식</a>을 변형해 적용했습니다.</p>
    </section>;
}
