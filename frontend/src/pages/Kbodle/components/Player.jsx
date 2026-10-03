import { useEffect, useState } from "react";
import PlayerSilhouette from "../../../components/PlayerSilhouette";
import { teamCapByName } from "../../../lib/teamAssets";

// 선수 사진이 없으면 소속팀 모자를 쓴 실루엣을 보여준다.
const PlayerImg = (props) => {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [props.img]);

    if (failed || !props.img) return <PlayerSilhouette className={props.className} cap={teamCapByName(props.team)} />;
    return (
        <img
            key={props.name}
            src={`${import.meta.env.BASE_URL}assets/images/player/kbo/${props.img}.jpg`}
            className={props.className}
            alt={props.name || "선수 이미지 준비 중"}
            onError={() => setFailed(true)}
        ></img>
    );
};

const PlayerName = (props) => {
    return (
        <div
            key={props.name}
            className="text-start font-family-NaSqNe font-bold mx-3"
        >
            <div className="flex items-center">
                <span className="player-img-container">
                    <PlayerImg
                        {...props}
                        className="rounded-circle player-img"
                    />
                </span>
                <span key={props.name} className="mx-2">
                    {props.name ? props.name : "나만의 크보들"}
                </span>
            </div>
        </div>
    );
};

export { PlayerImg, PlayerName };
