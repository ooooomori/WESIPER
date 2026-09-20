<?php
require dirname(__DIR__) . '/api/kbocandle/predictions.php';

class PredictionStatement extends PDOStatement {
    public $row;
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
class PredictionDatabase extends PDO {
    public $row;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $statement = new PredictionStatement();
        $statement->row = $this->row;
        return $statement;
    }
}
function checkPrediction($actual, $expected) {
    if ($actual !== $expected) throw new RuntimeException(json_encode([$actual,$expected]));
}

$schedule = ['2026'=>['regular'=>['2026-03-28','2026-12-31']]];
foreach ([
    ['2026','regular','whole','','',true],
    ['2026','regular','whole','2026-03-28','2026-12-31',true],
    ['2025','regular','whole','','',false],
    ['2026','preseason','whole','','',false],
    ['2026','regular','custom','2026-03-28','2026-12-31',false],
    ['2026','regular','whole','2026-09-01','2026-09-16',false],
    ['2026','regular','7','7','',false],
] as $case) {
    $expected = array_pop($case); $case[] = $schedule;
    checkPrediction(candlePredictionEligible(...$case), $expected);
}

putenv('WESIPER_CANDLE_REVISION_FILE=' . sys_get_temp_dir() . '/nonexistent-candle-test-' . uniqid());
$db = new PredictionDatabase();
$baseline = ['ab'=>100,'h'=>30,'bb'=>10,'hbp'=>1,'sf'=>2,'tb'=>50];
$p = ['status'=>'ready','baseline'=>$baseline,'last_player_date'=>'2026-09-16',
    'as_of_date'=>'2026-09-16','generated_at'=>gmdate('c')];
$db->row = false;
checkPrediction(candlePrediction($db,'1',$baseline,'2026-09-16')['status'],'pending');
$db->row = ['payload'=>json_encode($p),'source_revision'=>'initial'];
checkPrediction(candlePrediction($db,'1',$baseline,'2026-09-16')['status'],'ready');
$wrong = $baseline; $wrong['h']++;
checkPrediction(candlePrediction($db,'1',$wrong,'2026-09-16')['status'],'updating');
checkPrediction(candlePrediction($db,'1',$baseline,'2026-09-17')['status'],'updating');
$db->row['source_revision'] = 'new-revision';
checkPrediction(candlePrediction($db,'1',$baseline,'2026-09-16')['status'],'updating');
$p['generated_at'] = gmdate('c',time()-49*3600);
$db->row = ['payload'=>json_encode($p),'source_revision'=>'initial'];
checkPrediction(candlePrediction($db,'1',$baseline,'2026-09-16')['status'],'stale');
$db->row['payload'] = 'invalid';
checkPrediction(candlePrediction($db,'1',$baseline,'2026-09-16')['status'],'unavailable');
echo "14 prediction API checks passed\n";
