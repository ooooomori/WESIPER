import React, { useState } from "react";
import PlayerImg from "./PlayerImg";

const PlayerList = ({ player }) => {
    return (
        player && (
            <div className="flex items-center gap-3">
                <div className="w-9 h-9 shrink-0 rounded-full bg-gray-100 overflow-hidden border border-gray-200 flex items-center justify-center">
                    <PlayerImg p_no={player.PlayerId} p_img={player.Img} />
                </div>
                <div className="flex flex-col text-start">
                    <div className="flex items-center gap-2">
                        <span className="text-base font-bold text-gray-900">
                            {player.Name}
                        </span>
                        <span className="font-medium text-gray-500 text-xs px-1.5 py-0.5 bg-gray-100 rounded">
                            {player.Team}
                        </span>
                    </div>
                    <div className="text-xs text-gray-400">{player.Pos}</div>
                </div>
            </div>
        )
    );
};

export default PlayerList;
