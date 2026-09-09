import { Table } from "flowbite-react";
import NicknameChanger from "./NicknameChanger.jsx";

const LeaderboardTable = ({ status, score, uuid }) => {
    return (
        <div className="overflow-x-auto w-full mt-3.5">
            <Table hoverable className="text-center">
                <Table.Head>
                    <Table.HeadCell className="px-0">순위</Table.HeadCell>
                    <Table.HeadCell className="px-0">닉네임</Table.HeadCell>
                    <Table.HeadCell className="px-0">점수</Table.HeadCell>
                    <Table.HeadCell className="px-0">보기</Table.HeadCell>
                </Table.Head>
                <Table.Body className="divide-y">
                    {status &&
                        status?.top.map((user, idx) => (
                            <Table.Row key={idx} className="bg-white">
                                <Table.Cell
                                    className={`px-0 py-2 ${
                                        user.user_id === uuid ? "font-bold" : ""
                                    }`}
                                >
                                    {user.rank === 1
                                        ? "🥇"
                                        : user.rank === 2
                                          ? "🥈"
                                          : user.rank === 3
                                            ? "🥉"
                                            : user.rank}
                                </Table.Cell>
                                <Table.Cell
                                    className={`px-0 py-2 ${
                                        user.user_id === uuid ? "font-bold" : ""
                                    }`}
                                >
                                    {user.nickname}
                                </Table.Cell>
                                <Table.Cell
                                    className={`px-0 py-2 ${
                                        user.user_id === uuid ? "font-bold" : ""
                                    }`}
                                >
                                    {user.score}
                                </Table.Cell>
                                <Table.Cell className="px-0 py-2">
                                    {user.user_id === uuid ? (
                                        <div className="inline-block rounded-full bg-green-400 text-white py-0.5 px-2">
                                            MY
                                        </div>
                                    ) : (
                                        "준비 중"
                                    )}
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    {status?.rank > 5 && (
                        <Table.Row key={6} className="bg-gray-100">
                            <Table.Cell className="px-0 py-2 font-bold">
                                {status?.rank === 1
                                    ? "🥇"
                                    : status?.rank === 2
                                      ? "🥈"
                                      : status?.rank === 3
                                        ? "🥉"
                                        : status?.rank}
                            </Table.Cell>
                            <Table.Cell className="px-0 py-2 font-bold">
                                {status?.nickname}
                            </Table.Cell>
                            <Table.Cell className="px-0 py-2 font-bold">
                                {score}
                            </Table.Cell>
                            <Table.Cell className="px-0 py-2">
                                <div className="inline-block rounded-full bg-green-400 text-white py-0.5 px-2">
                                    MY
                                </div>
                            </Table.Cell>
                        </Table.Row>
                    )}
                </Table.Body>
            </Table>
            <NicknameChanger uuid={uuid} currentNickname={status?.nickname} />
        </div>
    );
};

export default LeaderboardTable;
