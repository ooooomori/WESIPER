import React from "react";
import axios from "axios";
import { Button } from "react-bootstrap";

const NicknameChanger = ({ uuid, currentNickname }) => {
    const handleClick = () => {
        const nickname = prompt(
            "새 닉네임을 입력하세요.",
            currentNickname || ""
        );
        if (nickname === null) return; // 취소 버튼 누른 경우

        if (nickname.trim().length < 2) {
            alert("닉네임이 너무 짧아요");
            return;
        }
        if (nickname.trim().length > 12) {
            alert("닉네임이 너무 길어요");
            return;
        }

        axios
            .post("/api/kbobingo/set_nickname.php", { uuid, nickname })
            .then((res) => {
                if (res.data?.code === 200) {
                    alert("닉네임이 바뀌었어요!");
                } else {
                    alert(res.data?.error || "알 수 없는 오류");
                }
            })
            .catch((e) => {
                console.log(e);
            });
    };

    return (
        <div className="text-center mt-1">
            <Button
                variant="outline-secondary"
                size="sm"
                onClick={handleClick}
                className="!rounded-full !px-3"
            >
                ✏️ 닉네임 변경
            </Button>
        </div>
    );
};

export default NicknameChanger;
