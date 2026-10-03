import React, { useEffect, useState } from "react";
import PlayerSilhouette from "../../../components/PlayerSilhouette";
import { teamCapByCode, teamCapLogoByCode } from "../../../lib/teamAssets";

// 선수 사진은 public의 로컬 이미지에서 불러오고, 없으면 팀 모자를 쓴 실루엣을 보여준다.
// p_img("2026_ssg_b_r" 또는 "ssg_b_r")는 모자 색을 정할 팀 코드를 찾는 데만 쓴다.
const PlayerImg = (props) => {
    const { p_no, p_img } = props;
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [p_no]);

    if (failed || !p_no) {
        const parts = String(p_img || "").split("_");
        const teamCode = parts.length >= 4 ? parts[1] : parts[0];
        const cap = teamCapByCode(teamCode);
        return <PlayerSilhouette className="bingo-player-silhouette w-full h-full" cap={cap} logo={cap && !cap.noLogo ? teamCapLogoByCode(teamCode) : null} />;
    }
    return (
        <img
            src={`${import.meta.env.BASE_URL}assets/images/player/kbo/${p_no}.jpg`}
            alt=""
            onError={() => setFailed(true)}
            className="w-full md:h-full md:w-auto bg-white"
        />
    );
};

export default PlayerImg;
