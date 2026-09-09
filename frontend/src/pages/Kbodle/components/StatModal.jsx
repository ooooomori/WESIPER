import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import { useEffect, useState } from "react";
import {
    BarChart,
    Bar,
    Rectangle,
    XAxis,
    Tooltip,
    ResponsiveContainer,
} from "recharts";

const renderCustomBarLabel = ({ payload, x, y, width, height, value }) => {
    return (
        value && (
            <text
                x={x + width / 2}
                y={y}
                fill="#000"
                textAnchor="middle"
                dy={-6}
            >
                {value}
            </text>
        )
    );
};

const RecordChart = ({ data }) => {
    return (
        <ResponsiveContainer width="90%" height={150}>
            <BarChart
                margin={{ top: 20, right: 0, left: 0, bottom: 0 }}
                data={data}
            >
                <XAxis dataKey="name" />
                <Bar
                    dataKey="cnt"
                    fill="#4ade90"
                    label={renderCustomBarLabel}
                />
            </BarChart>
        </ResponsiveContainer>
    );
};

const StatModal = (props) => {
    const [stat, setStat] = useState([]);

    useEffect(() => {
        setStat(JSON.parse(localStorage.getItem("kbodle-stats")));
    }, [props]);

    const handleClose = () => {
        props.setShow(false);
    };

    return (
        <>
            <Modal
                show={props.show}
                onHide={handleClose}
                centered
                className="font-family-NaSqNe"
            >
                <Modal.Header style={{ border: "none" }} closeButton>
                    <span className="font-family-kbo text-xl font-bold">
                        KBODLE: 크보들
                    </span>
                </Modal.Header>
                <Modal.Body className="text-sm">
                    <div className="flex flex-col justify-center">
                        <div className="flex gap-2 flex-col text-center">
                            <span className="text-base font-bold mb-1">
                                📒 내 크보들 현황
                            </span>
                            <div>
                                ✔️ 현재{" "}
                                <span className="font-bold">
                                    {stat && stat.playStreak}
                                </span>
                                일 연속 플레이 중이에요.
                            </div>
                            <div>
                                ✔️ 현재{" "}
                                <span className="font-bold">
                                    {stat && stat.winStreak}
                                </span>
                                일 연속 정답을 맞췄어요.
                            </div>
                            <div>
                                ✔️ 정답까지 평균{" "}
                                <span className="font-bold">
                                    {(() => {
                                        if (!stat?.record) return "0.00";

                                        const records = stat.record.slice(
                                            1,
                                            10
                                        );
                                        const numerator = records.reduce(
                                            (acc, curr, index) =>
                                                acc + curr * (index + 1),
                                            0
                                        );
                                        const denominator = records.reduce(
                                            (acc, curr) => acc + curr,
                                            0
                                        );

                                        if (denominator === 0) return "0.00";

                                        return (
                                            numerator / denominator
                                        ).toFixed(2);
                                    })()}
                                </span>
                                번 시도했어요.
                            </div>
                            <div>
                                ✔️ 내 크보들 정답률은{" "}
                                <span className="font-bold">
                                    {(() => {
                                        if (!stat?.record) return "0.00";

                                        const part = stat.record
                                            .slice(1, 10)
                                            .reduce((a, c) => a + c, 0);
                                        const total = stat.record.reduce(
                                            (a, c) => a + c,
                                            0
                                        );

                                        if (total === 0) return "0.00";

                                        return (
                                            Math.round((part / total) * 10000) /
                                            100
                                        ).toFixed(2);
                                    })()}
                                    %
                                </span>
                                예요.
                            </div>
                        </div>

                        <div className="flex justify-center items-center flex-col mt-6">
                            <span className="text-base font-bold mb-2">
                                📊 내 시도 횟수 통계
                            </span>
                            <RecordChart
                                data={
                                    stat &&
                                    stat.record?.map((x, i) => ({
                                        name: i === 0 ? "실패" : i.toString(),
                                        cnt: x,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal.Body>
                <Modal.Footer
                    style={{
                        border: "none",
                        display: "flex",
                        justifyContent: "center",
                    }}
                >
                    <Button
                        variant="outline-primary"
                        className="font-family-kbo mb-3 px-5"
                        onClick={handleClose}
                    >
                        닫기
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
};

export default StatModal;
