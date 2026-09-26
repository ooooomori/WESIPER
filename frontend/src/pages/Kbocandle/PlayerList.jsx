import React, { useState } from "react";
import PlayerImg from "./PlayerImg";

function highlightName(name, keyword) {
    const parts = [];
    let start = 0;
    let match;
    while ((match = name.toLocaleLowerCase().indexOf(keyword.toLocaleLowerCase(), start)) !== -1) {
        parts.push(name.slice(start, match));
        parts.push(<strong key={match} className="font-bold">{name.slice(match, match + keyword.length)}</strong>);
        start = match + keyword.length;
    }
    parts.push(name.slice(start));
    return parts;
}

const PlayerList = ({ player, showBackNo = false, highlight = "" }) => {
    const keyword = highlight.trim();
    return (
        player && (
            <div className="flex items-center gap-3">
                <div className="candle-player-thumb w-9 h-9 shrink-0 rounded-full overflow-hidden border flex items-center justify-center">
                    <PlayerImg p_no={player.PlayerId} p_img={player.Img} />
                </div>
                <div className="candle-player-info flex flex-col text-start">
                    <div className="candle-player-name-row flex items-center gap-2">
                        <span className={`text-base ${keyword ? "font-normal" : "font-bold"} text-gray-900`}>
                            {keyword ? highlightName(player.Name, keyword) : player.Name}
                        </span>
                        <span className="font-medium text-gray-500 text-xs px-1.5 py-0.5 bg-gray-100 rounded">
                            {player.Team}
                        </span>
                    </div>
                    <div className="candle-player-position text-xs text-gray-400">{player.Pos}{showBackNo && player.BackNo != null && String(player.BackNo).trim() !== "" && <> · No.{player.BackNo}</>}</div>
                </div>
            </div>
        )
    );
};

export default PlayerList;
