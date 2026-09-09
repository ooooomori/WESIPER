import Button from "react-bootstrap/Button";
import Modal from "react-bootstrap/Modal";
import { useEffect, useState } from "react";

const Setting = (props) => {
    const handleClose = () => {
        props.setShow(false);
    };

    return (
        <>
            <Modal
                show={props.show}
                onHide={handleClose}
                centered
                className="font-family-NaBaGo"
            >
                <Modal.Header style={{ border: "none" }} closeButton>
                    <Modal.Title className="font-family-kbo text-xl ">
                        설정
                    </Modal.Title>
                </Modal.Header>
                <Modal.Body></Modal.Body>
            </Modal>
        </>
    );
};

export default Setting;
