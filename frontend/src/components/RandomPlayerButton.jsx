import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LuShuffle } from 'react-icons/lu';

// 1군 기록이 있는 선수 중 한 명의 페이지로 이동한다. 지금 보고 있는 선수는 다시 고르지 않는다.
export default function RandomPlayerButton({ className, exclude }) {
    const navigate = useNavigate();
    const [loading, setLoading] = useState(false);
    const pick = async () => {
        setLoading(true);
        try {
            const response = await fetch(`/api/randomPlayer.php${exclude ? `?exclude=${encodeURIComponent(exclude)}` : ''}`, { cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error('random player failed');
            navigate(`/?pid=${encodeURIComponent(result.playerId)}`);
            window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
        } catch { /* 실패하면 버튼만 다시 누를 수 있게 둔다. */ }
        finally { setLoading(false); }
    };
    return <button type="button" className={className} onClick={pick} disabled={loading} aria-label="랜덤 선수 보기" title="랜덤 선수">
        <LuShuffle aria-hidden="true" />
    </button>;
}
