import React, { useState, useEffect } from "react";

// 컴포넌트 파일의 물리적 위치와 상관없이 /src를 기준으로 완전히 고정합니다.
const localImages = import.meta.glob("/src/assets/images/player/*.jpg", {
    eager: true,
    import: "default",
});

const PlayerImg = (props) => {
    const { p_no, p_img } = props;
    const [srcIndex, setSrcIndex] = useState(0);

    useEffect(() => {
        setSrcIndex(0);
    }, [p_no, p_img]);

    const imgName = p_img ? p_img.split("_").slice(1).join("_") : "ssg_b_r";

    // glob에 선언된 /src 절대 경로와 정확히 일치시킵니다.
    const localImagePath = `/src/assets/images/player/${imgName}.jpg`;
    const localImage = localImages[localImagePath] || "";

    const sources = [
        `${import.meta.env.BASE_URL}assets/images/player/kbo/${p_no}.jpg`,
        localImage,
    ].filter(Boolean);

    const handleError = () => {
        setSrcIndex((prev) => (prev < sources.length - 1 ? prev + 1 : prev));
    };

    return (
        <img
            src={sources[srcIndex]}
            alt={localImage}
            onError={handleError}
            className="w-full md:h-full md:w-auto bg-white"
        />
    );
};

export default PlayerImg;
