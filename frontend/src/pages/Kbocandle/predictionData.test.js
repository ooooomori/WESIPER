import test from "node:test";
import assert from "node:assert/strict";
import { predictionVisible, predictionRows, predictionEvents } from "./predictionData.js";

test("prediction is restricted to 2026 regular whole-season requests", () => {
    const data = { success: true, year: "2026", season: "regular", date_preset: "whole", prediction_eligible: true };
    assert.equal(predictionVisible(data), true);
    for (const patch of [{year:2025},{season:"preseason"},{season:"postseason"},{date_preset:"custom"},{date_preset:"7"},{prediction_eligible:false}]) {
        assert.equal(predictionVisible({...data,...patch}),false);
    }
});
test("rise, hold, and fall stay separate and rounded values sum to 100", () => {
    const probabilities = Object.fromEntries(["avg","obp","slg","ops"].map(m=>[m,{up:.41855,down:.5,flat:.08145}]));
    probabilities.hit = .6372;
    probabilities.home_run = .0315;
    const rows = predictionRows({status:"ready",probabilities});
    assert.equal(rows.length,4);
    assert.equal(rows[0].up,"41.9");
    assert.equal(rows[0].flat,"8.1");
    assert.equal(rows[0].down,"50.0");
    assert.equal(rows.reduce((sum,row)=>sum+Number(row.up)+Number(row.flat)+Number(row.down),0),400);
    assert.deepEqual(predictionEvents({status:"ready",probabilities}),{hit:"63.7",homeRun:"3.2"});
    assert.deepEqual(predictionRows({status:"pending"}),[]);
    assert.deepEqual(predictionRows({status:"ready",probabilities:{}}),[]);
    assert.equal(predictionEvents({status:"ready",probabilities:{hit:.01,home_run:.1}}),null);
});
