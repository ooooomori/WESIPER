import React, { useEffect, useState } from "react";
import { Modal, Nav } from "react-bootstrap";
import { predictionVisible, predictionRows, predictionEvents } from "./predictionData";
import PlayerImg from "./PlayerImg";

const rankMedals = { 1: "🥇", 2: "🥈", 3: "🥉" };
const teamNames = { OB: "두산", HH: "한화", LG: "LG", HT: "KIA", SS: "삼성", LT: "롯데", SK: "SSG", NC: "NC", KT: "KT", WO: "키움", NX: "키움" };

function previewRankings(playerId, playerName) {
    const teams = ["LG", "HH", "SS", "OB", "KT", "NC", "LT", "HT", "SK", "WO"];
    const sample = (metric) => [
        ...teams.map((team_code, index) => ({ player_id: `preview-${index}`, rank: index + 1,
            team_code, name: `예시 선수 ${index + 1}`, avg: 0.345 - index * 0.007, home_runs: 25 - index,
            probability: (metric === "hit" ? 0.79 : 0.31) - index * (metric === "hit" ? 0.021 : 0.019) })),
        { player_id: playerId, rank: 11, team_code: null, name: playerName || "조회 선수", avg: 0.273, home_runs: 12,
            probability: metric === "hit" ? 0.55 : 0.1 },
    ];
    return { preview: true, hit: sample("hit"), home_run: sample("home_run") };
}

function validRankings(value) {
    return value && Array.isArray(value.hit) && Array.isArray(value.home_run);
}

const messages = {
    pending: "예측 데이터를 준비하고 있습니다.",
    unavailable: "예측 데이터를 불러올 수 없습니다.",
    updating: "최신 경기 기록을 반영하고 있습니다.",
    stale: "예측 업데이트가 지연되고 있습니다.",
    insufficient_data: "예측에 필요한 기록이 부족합니다. (과거 5경기·20타석 이상)",
};

export default function PredictionCard({ data, dark = true }) {
    const [rankingsOpen, setRankingsOpen] = useState(false);
    const [rankMetric, setRankMetric] = useState("hit");
    const [rankings, setRankings] = useState(null);
    const [rankingsPreview, setRankingsPreview] = useState(false);
    const [rankingError, setRankingError] = useState("");
    const playerId = String(data?.player_id || "");
    const prediction = data?.prediction;

    useEffect(() => {
        if (!rankingsOpen || prediction?.status !== "ready" || !playerId) return;
        if (!prediction.data_version) {
            setRankingError("예측 순위 데이터가 아직 준비되지 않았습니다.");
            return;
        }
        const controller = new AbortController();
        const params = new URLSearchParams({ player_id: playerId,
            as_of_date: prediction.as_of_date, data_version: prediction.data_version });
        setRankings(null);
        setRankingsPreview(false);
        setRankingError("");
        fetch(`/api/kbocandle/prediction_rankings.php?${params}`, { signal: controller.signal })
            .then(async response => {
                if (import.meta.env.DEV && (response.status === 404 || response.headers.get("content-type")?.includes("text/html"))) {
                    return previewRankings(playerId, data?.name);
                }
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.error || "예측 순위를 불러올 수 없습니다.");
                if (!validRankings(result.rankings)) {
                    if (import.meta.env.DEV) return previewRankings(playerId, data?.name);
                    throw new Error("예측 순위 응답 형식이 올바르지 않습니다.");
                }
                return result.rankings;
            })
            .then(result => { setRankingsPreview(Boolean(result.preview)); setRankings(result); })
            .catch(error => { if (error.name !== "AbortError") setRankingError(error instanceof SyntaxError
                ? "예측 순위 서비스를 아직 사용할 수 없습니다." : error.message); });
        return () => controller.abort();
    }, [rankingsOpen, playerId, data?.name, prediction?.status, prediction?.as_of_date, prediction?.data_version]);

    if (!predictionVisible(data)) return null;
    const rows = predictionRows(prediction);
    const events = predictionEvents(prediction);
    return <section className="candle-prediction" aria-label="다음 경기 예측">
        <div className="candle-prediction-heading"><strong>다음 경기 예측</strong><span className="candle-prediction-help" tabIndex={0} aria-label="예측 안내" aria-describedby="candle-prediction-tooltip">?<span className="candle-prediction-tooltip" id="candle-prediction-tooltip" role="tooltip">타자의 과거 타석 데이터를 바탕으로 다음 경기 기록을 예측합니다. 정확도는 보장하지 않으니 재미로 즐겨주세요!</span></span><span className="candle-prediction-beta">BETA</span></div>
        {rows.length && events ? <>
            <div className="candle-prediction-body">
                <div className="candle-prediction-grid">{rows.map(row => <div className="candle-prediction-row" key={row.metric}>
                    <span className="candle-prediction-metric">{row.metric}</span><span className="up">↑ {row.up}%</span><span className="neutral">― {row.flat}%</span><span className="down">↓ {row.down}%</span>
                </div>)}</div>
                <div className="candle-prediction-events"><div className="candle-prediction-hit"><span className="candle-prediction-card-accent" aria-hidden="true" /><span>안타 칠 확률</span><span className="candle-prediction-event-value">{events.hit}%</span></div><div className="candle-prediction-home-run"><span className="candle-prediction-card-accent" aria-hidden="true" /><span>홈런 칠 확률</span><span className="candle-prediction-event-value">{events.homeRun}%</span></div><p className="candle-prediction-caption"><span>{prediction.as_of_date} 경기까지 반영한 예측값입니다.</span><button type="button" className="candle-prediction-rank-button" onClick={() => { setRankMetric("hit"); setRankingsOpen(true); }}><i className="bi bi-bar-chart-line-fill" aria-hidden="true" />순위 보기</button></p></div>
            </div>
        </> : <p className="candle-prediction-status" role="status">{messages[prediction?.status] || messages.unavailable}</p>}
        <Modal show={rankingsOpen} onHide={() => setRankingsOpen(false)} centered className={`candle-prediction-rank-modal font-family-NaSqNe ${dark ? "theme-dark" : "theme-light"}`}>
            <Modal.Header closeButton><Modal.Title>다음 경기 예측 순위</Modal.Title></Modal.Header>
            <Modal.Body>
                <Nav variant="tabs" activeKey={rankMetric} onSelect={key => setRankMetric(key)} className="candle-prediction-rank-tabs">
                    <Nav.Item><Nav.Link eventKey="hit">안타 칠 확률</Nav.Link></Nav.Item>
                    <Nav.Item><Nav.Link eventKey="home_run">홈런 칠 확률</Nav.Link></Nav.Item>
                </Nav>
                {rankingsPreview && <p className="candle-prediction-rank-status" role="note">배포 전 UI 미리보기용 예시 데이터입니다. 실제 순위가 아닙니다.</p>}
                <p className="candle-prediction-rank-description">다음 경기에 출전해 {rankMetric === "hit" ? "안타를" : "홈런을"} 1개 이상 기록할 확률을 나타냅니다.</p>
                {rankingError ? <p className="candle-prediction-rank-status" role="alert">{rankingError}</p>
                    : !validRankings(rankings) ? <p className="candle-prediction-rank-status" role="status">순위를 불러오는 중입니다.</p>
                    : <div className="candle-prediction-rank-scroll"><table className="candle-prediction-rank-table">
                        <thead><tr><th scope="col">순위</th><th scope="col">선수</th><th scope="col">{rankMetric === "hit" ? "타율" : "홈런"}</th><th scope="col">예상 확률</th></tr></thead>
                        <tbody>{rankings[rankMetric].map(row => <tr key={row.player_id} className={String(row.player_id) === playerId ? "is-current" : ""}>
                            <td>{rankMedals[row.rank] || `${row.rank}위`}</td>
                            <td><span className="candle-prediction-rank-player"><span className="candle-prediction-rank-avatar"><PlayerImg p_no={row.player_id} p_img={row.img || (String(row.player_id) === playerId ? data?.img : "") || ""} /></span><span>{row.name}</span><span className="candle-prediction-rank-team font-medium text-gray-500 text-xs px-1.5 py-0.5 bg-gray-100 rounded">{teamNames[row.team_code] || "미확인"}</span></span></td>
                            <td>{rankMetric === "hit" ? (row.avg != null && Number.isFinite(Number(row.avg)) ? Number(row.avg).toFixed(3) : "—") : (row.home_runs != null && Number.isFinite(Number(row.home_runs)) ? row.home_runs : "—")}</td>
                            <td>{(row.probability * 100).toFixed(1)}%</td>
                        </tr>)}</tbody>
                    </table></div>}
            </Modal.Body>
        </Modal>
    </section>;
}
