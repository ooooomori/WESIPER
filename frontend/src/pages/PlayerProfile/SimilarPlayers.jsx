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

// 유사 선수: 통산 기록의 유사도 점수가 높은 순.
export default function SimilarPlayers({ pid }) {
    const url = `/api/playerProfile.php?pid=${encodeURIComponent(pid)}&part=similar`;
    const [players, setPlayers] = useState(() => getCachedProfileData(url)?.similar?.players || []);
    useEffect(() => {
        let active = true;
        loadProfileData(url, data => Object.hasOwn(data, 'similar'))
            .then(data => { if (active) setPlayers(data.similar?.players || []); })
            .catch(() => {});
        return () => { active = false; };
    }, [url]);
    if (!players.length) return null;
    return <section><div className="profile-heading"><h2>유사 선수</h2></div>
        <ol className="profile-similar">{players.map((player, index) => <li key={player.PlayerId}><Link className="profile-alumni-row profile-similar-row" to={`/?pid=${encodeURIComponent(player.PlayerId)}`} onClick={() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' })}>
            <span className="profile-similar-rank">{index + 1}</span>
            <span className="profile-similar-photo"><SimilarPhoto player={player} /></span>
            <span className="profile-alumni-name"><strong>{player.Name}</strong></span>
            <span className="profile-alumni-meta">{player.Team ? (player.IsActive ? player.Team : `前 ${player.Team}`) : ''}</span>
            <span className="profile-similar-score">{Number(player.Score).toFixed(1)}</span>
        </Link></li>)}</ol>
    </section>;
}
