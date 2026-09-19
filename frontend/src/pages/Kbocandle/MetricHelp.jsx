import React, { useState } from "react";
import { Modal } from "react-bootstrap";

export default function MetricHelp({ metric }) {
    const [open, setOpen] = useState(false);
    if (metric !== "eff_ops") return null;

    return <><sup
            className="candle-help-icon"
            aria-label="실질 OPS 계산 설명"
            role="button"
            tabIndex={0}
            onClick={() => setOpen(true)}
            onKeyDown={event => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); setOpen(true); } }}
        >?</sup>
        <Modal show={open} onHide={() => setOpen(false)} centered className="candle-help-modal font-family-NaSqNe" contentClassName="candle-help-modal-content">
            <Modal.Header closeButton><Modal.Title>실질 OPS란?</Modal.Title></Modal.Header>
            <Modal.Body>
                <p>도루 성공은 총 루타수를 증가시킵니다. 예를 들어 1루타 후 2루도루 시 2루타를 친 것으로 간주합니다.</p>
                <p>주자가 대신 아웃되어 출루한 후의 도루 성공은 희생번트를 성공시킨 것으로 간주합니다.</p>
                <p>출루 후 도루 실패는 출루를 하지 못했던 것으로 간주합니다.</p>
                <p className="mb-0">실제로 통용되는 기록은 아니니 재미로만 즐겨주세요!</p>
            </Modal.Body>
        </Modal>
    </>;
}
