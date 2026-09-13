import { useState, useEffect } from "react";
import PlayerImg from "../PlayerImg.jsx";
import axios from "axios";

const MostGrid = (props) => {
    const { status, date, players, loading = false } = props;

    const items = [0, 1, 2, 3, 4, 5, 6, 7, 8];

    return (
        <div className="flex-shrink-0 flex-grow flex flex-col font-family-NaSqNe text-center">
            <div>
                <div className="flex justify-center flex-row items-center my-3.5">
                    <div className="flex flex-col justify-center">
                        <div className="flex justify-center items-center h-16 sm:h-20"></div>
                        <GridCond type="row" cond={status[date]?.grid.row[0]} />
                        <GridCond type="row" cond={status[date]?.grid.row[1]} />
                        <GridCond type="row" cond={status[date]?.grid.row[2]} />
                    </div>
                    <div className="flex flex-col">
                        <div className="grid grid-cols-3 overflow-hidden  place-items-center">
                            <GridCond
                                type="col"
                                cond={status[date]?.grid.col[0]}
                            />
                            <GridCond
                                type="col"
                                cond={status[date]?.grid.col[1]}
                            />
                            <GridCond
                                type="col"
                                cond={status[date]?.grid.col[2]}
                            />
                        </div>
                        <div className="grid grid-cols-3 ">
                            {items.map((e) => (
                                <GridItem
                                    key={e}
                                    no={e}
                                    loading={loading}
                                    directPlayer={players ? players[e] ?? {} : undefined}
                                    gridIndex={status[date]?.grid.index}
                                    item={
                                        status[date]?.correctAnswer?.[
                                            Math.floor(e / 3)
                                        ]?.[e % 3] ?? -1
                                    }
                                />
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

const GridItem = (props) => {
    const { gridIndex, no, item, directPlayer, loading } = props;

    const [rate, setRate] = useState(null);
    const [pImg, setpImg] = useState("ssg_b_l");
    const [pName, setpName] = useState(null);
    const [pNo, setpNo] = useState(null);
    const [bgColor, setBgColor] = useState("bg-white");

    useEffect(() => {
        if (directPlayer !== undefined) {
            setpImg(directPlayer.img ?? "ssg_b_l");
            setpName(directPlayer.name ?? null);
            setpNo(directPlayer.no ?? null);
            setRate(null);
            return;
        }
        if (gridIndex) {
            // setBgColor("bg-[#4ade90]");
            const controller = new AbortController();
            axios
                .post(
                    "/api/kbobingo/pick_data_most.php",
                    {
                        index: gridIndex,
                        row: Math.floor(no / 3),
                        col: no % 3,
                    },
                    { signal: controller.signal },
                )
                .then((response) => {
                    const result = response.data;
                    if (result.code === 200) {
                        if (result.rate) {
                            setpImg(result.img);
                            setpName(result.name);
                            setpNo(result.no);
                            setRate(result.rate);
                        }
                    }
                })
                .catch((error) => {
                    console.error("KBO BINGO 데이터 받아오기 실패:", error);
                });
            return () => {
                controller.abort();
            };
        }
    }, [gridIndex, directPlayer]);

    const applyBorder = () => {
        switch (no) {
            case 0:
                return "rounded-tl-xl border-t border-l";
            case 1:
                return "border-t border-l border-r";
            case 2:
                return "rounded-tr-xl border-t border-r";
            case 3:
                return "border-t border-l border-b";
            case 4:
                return "border-t border-l border-r border-b";
            case 5:
                return "border-t border-r border-b";
            case 6:
                return "rounded-bl-xl border-b border-l";
            case 7:
                return "border-b border-l border-r";
            case 8:
                return "rounded-br-xl border-b border-r";
        }
    };

    return (
        <div
            className={
                bgColor +
                " relative border-zinc-300 cursor-pointer flex items-center justify-center w-20 sm:w-28 h-20 sm:h-28 transition-colors duration-75 overflow-hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary focus-visible:z-10  " +
                applyBorder()
            }
        >
            {loading && <div aria-hidden="true" className="absolute inset-0 animate-pulse bg-gray-200" />}
            {!loading && pName && (
                <div
                    className="relative overflow flex justify-center items-center w-full h-full"
                    onClick={() => {
                        const isBatter = pImg?.split("_")[2] === "b";
                        const pageType = isBatter
                            ? "HitterDetail"
                            : "PitcherDetail";
                        const isMobile = window.matchMedia?.("(max-width: 767px)").matches || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
                        window.location.href = isMobile
                            ? `https://m.koreabaseball.com/Kbo/Player/${isBatter ? "Hitter" : "Pitcher"}.aspx?playerId=${pNo}`
                            : `https://www.koreabaseball.com/Record/Player/${pageType}/Total.aspx?playerId=${pNo}`;
                    }}
                >
                    {rate !== null && <div className="absolute top-0 right-0 text-white text-[0.6rem] md:text-xs bg-zinc-800 opacity-95 pl-1.5 pr-1 sm:pr-1.5 py-0.5 rounded-bl-lg">
                        {rate}%
                    </div>}
                    <PlayerImg p_no={pNo} p_img={pImg} />
                    <div className="absolute left-0 right-0 bottom-0 bg-zinc-800 opacity-95 text-xs sm:text-xs md:text-sm py-px line-clamp-1 text-white text-center">
                        {pName}
                    </div>
                </div>
            )}
        </div>
    );
};

const resolveLogoUrl = (name) => {
    return new URL(
        `../../../../assets/images/logos/${name}-logo.svg`,
        import.meta.url,
    ).href;
};
const GridCond = (props) => {
    const [gridCond, setGridCond] = useState(null);
    const { cond, type } = props;
    useEffect(() => {
        if (cond) {
            const str = cond.split("-");
            if (str[0] === "team") {
                setGridCond(
                    <img
                        src={resolveLogoUrl(str[1])}
                        alt={str[1]}
                        className="w-14 sm:w-20 h-10 sm:h-14"
                    />,
                );
            } else if (str[0] === "award" || str[0] === "profile") {
                let text = "";
                let bigo = "";
                switch (str[1]) {
                    case "as":
                        text = <span className="text-black">KBO 올스타</span>;
                        break;
                    case "mvp":
                        text = "KBO MVP";
                        bigo = "정규시즌 + KS";
                        break;
                    case "gg":
                        text = (
                            <span className="text-black">KBO 골든글러브</span>
                        );
                        break;
                    case "draft_1r":
                        text = "1차 · 1라운드 지명";
                        break;
                    case "one_club":
                        text = "원 클럽 플레이어";
                        break;
                    case "active_2025":
                        text = "2025년 이후 출장";
                        bigo = "1경기 이상";
                        break;
                    case "is_WBC":
                        text = (
                            <img
                                src={resolveLogoUrl("wbc")}
                                alt="WBC"
                                className="block mx-auto w-8 sm:w-10 h-8 sm:h-10"
                            />
                        );
                        bigo = "WBC 대표팀";
                        break;
                    case "is_MLB":
                        text = (
                            <img
                                src={resolveLogoUrl("mlb")}
                                alt="MLB"
                                className="block mx-auto w-10 sm:w-14 h-8 sm:h-10 object-contain"
                            />
                        );
                        bigo = "MLB 1경기 이상";
                        break;
                }
                setGridCond(
                    <div className="px-1 break-keep text-xs sm:text-base font-bold">
                        {text}
                        <div className="text-gray-600 text-[0.625rem] sm:text-sm">
                            {bigo}
                        </div>
                    </div>,
                );
            } else if (str[0] === "stat") {
                const stat = str[1].split("_");
                let text = "";
                switch (stat[0]) {
                    case "war":
                        text = `${stat[1]}+ WAR`;
                        break;
                    case "pa":
                        text = `${stat[1]}+ 타석`;
                        break;
                    case "avg":
                        text = `타율 ${stat[1]}+`;
                        break;
                    case "obp":
                        text = `출루율 ${stat[1]}+`;
                        break;
                    case "slg":
                        text = `장타율 ${stat[1]}+`;
                        break;
                    case "ops":
                        text = `OPS ${stat[1]}+`;
                        break;
                    case "h":
                        text = `${stat[1]}+ 안타`;
                        break;
                    case "2b":
                        text = `${stat[1]}+ 2루타`;
                        break;
                    case "3b":
                        text = `${stat[1]}+ 3루타`;
                        break;
                    case "hr":
                        text = `${stat[1]}+ 홈런`;
                        break;
                    case "rbi":
                        text = `${stat[1]}+ 타점`;
                        break;
                    case "sb":
                        text = `${stat[1]}+ 도루`;
                        break;
                    case "ip":
                        text = `${stat[1]}+ 이닝`;
                        break;
                    case "era":
                        text = `ERA ${stat[1]}-`;
                        break;
                    case "so":
                        text = `${stat[1]}+ 탈삼진`;
                        break;
                    case "win":
                        text = `${stat[1]}+ 승`;
                        break;
                    case "hld":
                        text = `${stat[1]}+ 홀드`;
                        break;
                    case "sv":
                        text = `${stat[1]}+ 세이브`;
                        break;
                }
                setGridCond(
                    <div className="px-1 break-keep text-xs sm:text-base font-bold">
                        {text}
                        <div className="text-gray-600 text-[0.625rem] sm:text-sm">
                            {stat[2] === "season" ? "단일 시즌" : "커리어 통산"}
                        </div>
                    </div>,
                );
            } else if (str[0] === "pos") {
                const pos = str[1];
                let text = "";
                switch (pos) {
                    case "c":
                        text = "포수";
                        break;
                    case "1b":
                        text = "1루수";
                        break;
                    case "2b":
                        text = "2루수";
                        break;
                    case "3b":
                        text = "3루수";
                        break;
                    case "ss":
                        text = "유격수";
                        break;
                    case "lf":
                        text = "좌익수";
                        break;
                    case "cf":
                        text = "중견수";
                        break;
                    case "rf":
                        text = "우익수";
                        break;
                    case "of":
                        text = "외야수";
                        break;
                }
                setGridCond(
                    <div className="px-1 break-keep text-xs sm:text-base font-bold">
                        {text} 출장
                        <div className="text-gray-600 text-[0.625rem] sm:text-sm">
                            '01년 ~ 25년
                        </div>
                    </div>,
                );
            }
        }
    }, [cond]);
    return (
        <div
            className={
                (type === "col"
                    ? "h-16 sm:h-20 w-16 sm:w-20"
                    : "-ml-2 w-16 sm:w-24 h-20 sm:h-28") +
                " flex items-center justify-center"
            }
        >
            {gridCond}
        </div>
    );
};

export default MostGrid;
