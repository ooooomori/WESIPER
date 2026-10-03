// 사진이 없는 선수 자리에 쓰는 실루엣: 모자를 쓴 상반신. 색은 쓰는 쪽 CSS에서 .is-body / .is-cap 으로 입힌다.
// 세로로 긴 칸에서는 어깨 양옆이 잘리며 아래에 붙는다.
// cap({ crown, brim, originalLogo })을 주면 그 팀의 홈 모자 색으로 칠하고, logo를 주면 앞면에 로고를 얹는다.

// 정면에서 본 볼캡의 챙: 가운데가 높고 양 끝이 아래로 휘어 내려오는 ∩ 모양
// 챙은 모자 몸통보다 조금만 넓게 두고, 몸통 아랫단을 챙 안쪽까지 내려서 둘 사이가 끊겨 보이지 않게 한다.
const brim = 'M11.5 21.7C14.2 17.9 17.8 16.4 22 16.4s7.8 1.5 10.5 5.3c.3.5-.2 1-.7.7C29 20.6 25.7 19.7 22 19.7s-7 .9-9.8 2.7c-.5.3-1-.2-.7-.7Z';
// 모자 몸통: 반원이 아니라 옆은 거의 곧게 올라가고 윗부분은 완만한, 앞판이 선 볼캡 모양
const crown = 'M12.4 20.6C12.1 15.4 12.9 11.6 15.9 9.5 17.8 8.2 19.9 7.8 22 7.8s4.2.4 6.1 1.7c3 2.1 3.8 5.9 3.5 11.1C28.9 18.9 25.6 18 22 18s-6.9.9-9.6 2.6Z';

// 앞판 양옆의 봉제선(거의 안 보일 만큼 옅게)
const seams = 'M22 7.9C19.2 9.4 17 13.2 16.6 18.9M22 7.9c2.8 1.5 5 5.3 5.4 11';

export default function PlayerSilhouette({ className, logo, cap }) {
    const crownStyle = cap ? { fill: cap.crown } : undefined;
    return <svg className={`${className}${cap ? ' has-cap-color' : ''}${logo ? ' has-cap-logo' : ''}`} viewBox="0 0 44 44" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
        <path className="is-body" d="M3 46c0-10 8.5-15 19-15s19 5 19 15Z" />
        <circle className="is-body" cx="22" cy="20.5" r="8.3" />
        <path className="is-cap" d={crown} style={crownStyle} />
        <path d={seams} fill="none" stroke={cap ? '#fff' : '#000'} strokeWidth=".3" strokeLinecap="round" opacity=".07" />
        <ellipse className="is-cap" cx="22" cy="7.7" rx="1.2" ry=".65" style={crownStyle} />
        <path className="is-cap" d={brim} transform="translate(0 -.9)" style={cap ? { fill: cap.brim } : undefined} />
        {/* 챙 아랫면의 그늘 */}
        <path d={brim} transform="translate(0 -.9)" fill="#000" opacity=".16" />
        {logo && <image className="is-cap-logo" href={logo} x="18.9" y="9.1" width="6.2" height="6.2" preserveAspectRatio="xMidYMid meet" style={{ filter: cap?.originalLogo ? 'none' : 'brightness(0) invert(1)' }} />}
    </svg>;
}
