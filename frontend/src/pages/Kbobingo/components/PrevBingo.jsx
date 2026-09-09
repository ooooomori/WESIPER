import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import { Table } from "flowbite-react";
import { Link, useNavigate } from "react-router-dom";

const PrevModal = (props) => {
    const navigate = useNavigate();

    const handleClose = () => {
        props.setShow(false);
    };

    function getDatesInRange(startDate) {
        const dates = [];
        const currentDate = new Date(startDate);
        currentDate.setHours(0, 0, 0, 0);
        const lastDate = new Date();
        lastDate.setHours(0, 0, 0, 0);
        const tzoffset = new Date().getTimezoneOffset() * 60000;

        while (currentDate <= lastDate) {
            const tzoffset = new Date().getTimezoneOffset() * 60000;
            dates.push(
                new Date(currentDate - tzoffset).toISOString().split("T")[0]
            );

            currentDate.setDate(currentDate.getDate() + 1);
        }

        return dates;
    }

    const datesInRange = getDatesInRange("2025-06-15");
    const makeCell = (e, i) => {
        if (props.status[e]) {
            if (props.status[e].chance === 0) {
                return (
                    <>
                        <Table.Cell className="!px-2 text-center !py-2">
                            {props.status[e].correctAnswer.reduce(
                                (total, subArray) =>
                                    total +
                                    subArray.filter((item) => item !== null)
                                        .length,
                                0
                            )}
                            /9
                        </Table.Cell>
                        <Table.Cell className="!px-2 text-center !py-2">
                            {props.status[e].score}
                        </Table.Cell>
                    </>
                );
            } else {
                return (
                    <>
                        <Table.Cell
                            colSpan={2}
                            className="!px-2 text-center !py-2 "
                        >
                            <Link
                                to={`/bingo?index=${i}`}
                                className="text-blue-600 hover:underline hover: underline-offset-2"
                            >
                                계속 풀기
                            </Link>
                        </Table.Cell>
                    </>
                );
            }
        } else {
            return (
                <>
                    <Table.Cell colspan={2} className="!px-2 text-center !py-2">
                        <Link
                            to={`/bingo?index=${i}`}
                            className="text-blue-600 hover:underline hover: underline-offset-2"
                        >
                            풀러 가기
                        </Link>
                    </Table.Cell>
                </>
            );
        }
    };
    return (
        <Modal
            show={props.show}
            onHide={handleClose}
            centered
            className="font-family-NaSqNe"
        >
            <Modal.Header style={{ border: "none" }} closeButton>
                <span className="font-family-kbo text-xl font-bold">
                    KBO BINGO
                </span>
            </Modal.Header>
            <Modal.Body className="text-sm">
                <div className="overflow-x-auto">
                    <Table striped>
                        <Table.Head>
                            <Table.HeadCell className="!px-3 text-center">
                                #
                            </Table.HeadCell>
                            <Table.HeadCell className="!px-2 text-center">
                                날짜
                            </Table.HeadCell>
                            <Table.HeadCell className="!px-2 text-center">
                                기록
                            </Table.HeadCell>
                            <Table.HeadCell className="!px-2 text-center">
                                점수
                            </Table.HeadCell>
                        </Table.Head>
                        <Table.Body className="divide-y">
                            {datesInRange
                                .slice(0)
                                .reverse()
                                .map((e, index) => (
                                    <Table.Row
                                        className="bg-white hover:bg-gray-200 cursor-pointer"
                                        key={index}
                                        onClick={() =>
                                            navigate(
                                                `/bingo?index=${
                                                    datesInRange.length - index
                                                }`
                                            )
                                        }
                                    >
                                        <Table.Cell className="whitespace-nowrap !px-3 !py-2 text-center">
                                            <Link
                                                to={`/bingo?index=${
                                                    datesInRange.length - index
                                                }`}
                                                className="text-blue-600 hover:underline hover: underline-offset-2"
                                            >
                                                {datesInRange.length - index}
                                            </Link>
                                        </Table.Cell>
                                        <Table.Cell className="whitespace-nowrap !px-2 !py-2 text-center">
                                            {e}
                                        </Table.Cell>
                                        {makeCell(
                                            e,
                                            datesInRange.length - index
                                        )}
                                    </Table.Row>
                                ))}
                        </Table.Body>
                    </Table>
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
                    className="font-family-kbo mb-3 !px-10"
                    onClick={handleClose}
                >
                    목록 닫기
                </Button>
            </Modal.Footer>
        </Modal>
    );
};

export default PrevModal;
