<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__).'/backend';
require dirname(__DIR__).'/backend/api/kbocandle/common.php';
require dirname(__DIR__).'/backend/lib/player-season-schedule.php';
require dirname(__DIR__).'/backend/lib/player-year-records.php';
require dirname(__DIR__).'/backend/lib/player-game-seasons.php';
require dirname(__DIR__).'/backend/lib/player-streaks.php';
class ProfileTimingStatement extends PDOStatement {
    public static array $timings=[];
    protected function __construct(){}
    public function execute(?array $params=null): bool {
        $start=microtime(true);$ok=parent::execute($params);
        self::$timings[]=['seconds'=>round(microtime(true)-$start,3),'sql'=>substr(preg_replace('/\s+/',' ',$this->queryString),0,180)];return $ok;
    }
}
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ProfileTimingStatement::class]);
$pid=$argv[1]??'76290';$mode=$argv[2]??'games';
if(!ctype_digit($pid)||!in_array($mode,['games','years'],true))throw new InvalidArgumentException('Usage: php profile-api-performance.php PLAYER_ID games|years');
$q=$pdo->prepare('SELECT player_id AS PlayerId,pos AS Pos,is_kbodle AS IsKbodle,team AS Team FROM kbo_player_data WHERE player_id=?');$q->execute([$pid]);$player=$q->fetch(PDO::FETCH_ASSOC);if(!$player)throw new RuntimeException('Unknown player');
$schedule=profileSchedule();$start=microtime(true);
if($mode==='games'){
    $available=profilePlayerGameSeasons($pdo,$player,$schedule);$year=array_key_first($available);$season=$available[$year][0];
    $streakRows=null;
    $record=profileRecords($pdo,$player,$schedule,(int)$year,$season,true,$streakRows);
    $streak=str_contains($player['Pos'],'투수')?null:profileCurrentStreaks($pdo,$pid,$schedule,$streakRows);
    $result=['games'=>count($record['games']??[]),'year'=>$year,'season'=>$season];
}else{
    $record=profileYearRecords($pdo,$pid,str_contains($player['Pos'],'투수'),$schedule);
    $result=['years'=>count($record['rows'])];
}
usort(ProfileTimingStatement::$timings,static fn($a,$b)=>$b['seconds']<=>$a['seconds']);
echo json_encode(['pid'=>$pid,'mode'=>$mode,'seconds'=>round(microtime(true)-$start,3),'peakMB'=>round(memory_get_peak_usage(true)/1048576,1),'result'=>$result,'slowQueries'=>array_slice(ProfileTimingStatement::$timings,0,5)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
