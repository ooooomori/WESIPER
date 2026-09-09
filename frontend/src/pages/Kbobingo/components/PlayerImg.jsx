import React, { useState } from "react";

// 1. 컴포넌트 외부에 선언하여 빌드 시 폴더 내 모든 jpg 이미지를 객체 형태로 가져옵니다.
const localImages = import.meta.glob("../../../assets/images/player/*.jpg", { 
    eager: true, 
    import: "default" 
});

const PlayerImg = (props) => {
    const { p_no, p_img } = props;
    const [srcIndex, setSrcIndex] = useState(0);

    // 연도와 이미지명 추출
    const year = p_img.split("_")[0];
    const imgName = p_img.split("_").slice(1).join("_");

    // 2. 미리 로드된 이미지 객체에서 매칭되는 경로를 찾습니다.
    const localImagePath = `../../../assets/images/player/${imgName}.jpg`;
    const localImage = localImages[localImagePath] || "";

    // 소스 순서대로 시도
    const sources = [
        `https://6ptotvmi5753.edge.naverncp.com/KBO_IMAGE/person/middle/${year}/${p_no}.jpg`,
        `${import.meta.env.BASE_URL}assets/images/player/kbo/${p_no}.jpg`,
        localImage,
    ];

    const handleError = () => {
        setSrcIndex((prev) => (prev < sources.length - 1 ? prev + 1 : prev));
    };

    return (
        <img
            src={sources[srcIndex]}
            alt={localImages['ssg_p_r']}
            onError={handleError}
            className="w-full md:h-full md:w-auto bg-white"
        />
    );
};

export default PlayerImg;