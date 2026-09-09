const PlayerImg = (props) => {
    const teamCode = (hangul) => {
        switch (hangul) {
            case "삼성":
                return "sam";
            case "SSG":
                return "ssg";
            case "롯데":
                return "lot";
            case "두산":
                return "doo";
            case "KIA":
                return "kia";
            case "LG":
                return "lg";
            case "키움":
                return "kiw";
            case "NC":
                return "nc";
            case "한화":
                return "han";
            default:
                return "kt";
        }
    };
    return (
        <img
            key={props.name}
            src={
                "https://6ptotvmi5753.edge.naverncp.com/KBO_IMAGE/person/middle/2026/" +
                props.img +
                ".jpg"
            }
            className={props.className}
            alt={props.name}
            onError={(e) => {
                e.target.onerror = null;
                e.target.src = require(
                    `../../../assets/images/player/${teamCode(
                        props.team ?? "SSG",
                    )}_${
                        props.pos === "선발" || props.pos === "구원" ? "p" : "b"
                    }_${props.hand?.[0] === "우" ? "r" : "l"}.jpg`,
                );
            }}
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
