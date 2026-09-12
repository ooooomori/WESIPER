import { Fragment, useEffect, useState } from "react";
import { Table } from "flowbite-react";
import Collapse from "react-bootstrap/Collapse";
import Form from "react-bootstrap/Form";
import axios from "axios";
import NicknameChanger from "./NicknameChanger.jsx";
import MostGrid from "./MostGrid.jsx";

const LeaderboardTable = ({ status, score, uuid, gridIndex, onVisibilityChange, visibilityBusy }) => {
    const [selected, setSelected] = useState(null);
    const [board, setBoard] = useState(null);
    const [error, setError] = useState("");
    useEffect(() => {
        setBoard(null);
        setError("");
        if (!selected || selected.user_id === uuid) return;
        const controller = new AbortController();
        axios.post("/api/kbobingo/get_user_board.php", {
            index: gridIndex, board_id: selected.board_id,
        }, { signal: controller.signal }).then(({ data }) => {
            if (data.code !== 200) throw new Error(data.error);
            setBoard(data);
        }).catch((err) => {
            if (!axios.isCancel(err)) setError("공개된 빙고판을 불러오지 못했습니다.");
        });
        return () => controller.abort();
    }, [selected, gridIndex, uuid]);

    const entries = [...(status?.top ?? [])];
    if (status?.rank > 5) entries.push({
        user_id: uuid, nickname: status.nickname, rank: status.rank, score,
        is_public: status.is_public, has_board: true,
        board_id: status.board_id,
    });
    return <div className="overflow-x-auto w-full mt-3.5">
        <Table hoverable className="text-center">
            <Table.Head>{["순위", "닉네임", "점수", "보기"].map((label) => <Table.HeadCell key={label} className="px-0">{label}</Table.HeadCell>)}</Table.Head>
            <Table.Body className="divide-y">
                {entries.map((user) => <Fragment key={user.board_id}><Table.Row className={user.user_id === uuid ? "bg-gray-100 font-bold" : selected?.board_id === user.board_id ? "bg-gray-50" : "bg-white"}>
                    <Table.Cell className="px-0 py-2">{user.rank === 1 ? "🥇" : user.rank === 2 ? "🥈" : user.rank === 3 ? "🥉" : user.rank}</Table.Cell>
                    <Table.Cell className="px-0 py-2">{user.nickname}</Table.Cell>
                    <Table.Cell className="px-0 py-2">{user.score}</Table.Cell>
                    <Table.Cell className="px-0 py-2">
                        {user.user_id === uuid ? <div className="inline-block rounded-full bg-green-400 text-white py-0.5 px-2">MY</div> : <button type="button" onClick={() => { setBoard(null); setError(""); setSelected(selected?.board_id === user.board_id ? null : user); }} disabled={!user.is_public || !user.has_board}
                            aria-label={user.nickname + "님의 빙고판 " + (selected?.board_id === user.board_id ? "접기" : "펼치기")}
                            aria-expanded={selected?.board_id === user.board_id}
                            aria-controls={`board-${user.board_id}`}
                            title={!user.is_public ? "비공개 빙고판" : !user.has_board ? "저장된 빙고판 없음" : selected?.board_id === user.board_id ? "빙고판 접기" : "빙고판 펼치기"}
                            className="inline-flex align-middle items-center justify-center rounded-lg px-2 py-0.5 text-blue-600 hover:bg-blue-50 disabled:text-gray-300 disabled:cursor-not-allowed disabled:hover:bg-transparent">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
                                className={`transition-transform duration-200 ${selected?.board_id === user.board_id ? "rotate-180" : ""}`}>
                                <path d="M4 8h16l-8 9z" />
                            </svg>
                        </button>}
                    </Table.Cell>
                </Table.Row>
                    {selected?.board_id === user.board_id && user.user_id !== uuid && <Table.Row className="bg-gray-50 !border-0">
                        <Table.Cell colSpan={4} className="!p-0">
                            <Collapse in={selected?.board_id === user.board_id} mountOnEnter unmountOnExit>
                                <div id={`board-${user.board_id}`}>
                                    <div className="px-3 pb-4">
                                        {selected?.board_id === user.board_id && (error
                                            ? <p role="alert" className="text-center text-sm text-red-600">{error}</p>
                                            : board ? <MostGrid status={{ shared: { grid: board.grid } }} date="shared" players={board.players} />
                                            : <p role="status" className="text-center text-sm text-gray-500">빙고판을 불러오는 중…</p>)}
                                    </div>
                                </div>
                            </Collapse>
                        </Table.Cell>
                    </Table.Row>}
                </Fragment>)}
            </Table.Body>
        </Table>
        <div className="flex flex-wrap items-center justify-center gap-3 mt-4">
            <Form.Check type="switch" id="bingo-board-public" label="내 빙고판 공개"
                checked={status?.is_public ?? true} disabled={visibilityBusy}
                className="mx-2 text-gray-500 mb-0" style={{ fontSize: "14px" }}
                onChange={(event) => onVisibilityChange(event.target.checked)} />
            <NicknameChanger uuid={uuid} currentNickname={status?.nickname} className="text-center" />
        </div>
    </div>;
};

export default LeaderboardTable;
