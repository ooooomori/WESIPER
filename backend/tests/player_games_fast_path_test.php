<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-streaks.php';
class GamesFixtureStatement extends PDOStatement {
    public function __construct(private array $rows){}
    public function execute(?array $params=null): bool { return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->rows; }
}
class GamesFixtureDb extends PDO {
    public array $queries=[];
    public function __construct(private array $events,private array $scheduleRows){}
    public function quote(string $string,int $type=PDO::PARAM_STR): string { return "'".str_replace("'","''",$string)."'"; }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->queries[]=$query;
        if(str_contains($query,'SELECT * FROM `kbo_season_records`'))return new GamesFixtureStatement($this->events);
        if(str_contains($query,'FROM kbo_schedule'))return new GamesFixtureStatement($this->scheduleRows);
        throw new RuntimeException('Unexpected DB work on games-only path: '.$query);
    }
}
$today=new DateTimeImmutable('today',new DateTimeZone('Asia/Seoul'));$year=(int)$today->format('Y');$date=$today->format('Y-m-d');
$game=$today->format('Ymd').'LGSS0';
$events=[];
foreach(['좌안','중희비','투희번'] as $i=>$result)$events[]=['PK'=>$i+1,'game_id'=>$game,'game_date'=>$date,'team'=>'LG','pa_result'=>$result,'sb'=>0,'cs'=>0,'r'=>0,'rbi'=>0,'is_gs'=>1,'pos'=>'좌'];
$db=new GamesFixtureDb($events,[['game_code'=>$game,'stadium'=>'대구','away_team'=>'LG','home_team'=>'삼성','away_score'=>2,'home_score'=>1]]);
$schedule=[$year=>['regular'=>[$year.'-01-01',$year.'-12-31']]];
$player=['PlayerId'=>'test','Pos'=>'외야수','IsKbodle'=>'1','Team'=>'LG'];
$source=null;$record=profileRecords($db,$player,$schedule,$year,'regular',true,$source);
if(count($db->queries)!==2||count($record['games'])!==1||$record['games'][0]['h']!==1||$record['games'][0]['sf']!==1||$record['games'][0]['sh']!==1||$record['rolling']!==[])throw new RuntimeException('Games-only result changed');
$before=count($db->queries);$streak=profileCurrentStreaks($db,'test',$schedule,$source);
if(count($db->queries)!==$before||$streak['rows'][0]['count']!==1||$streak['rows'][0]['positive']!==true)throw new RuntimeException('Streak did not reuse current-season events');
// Future PAs from the reusable season data must not extend today's streak.
$future=$events[0];$future['game_date']=$today->modify('+1 day')->format('Y-m-d');$future['game_id']='future';$source[]=$future;
$streak=profileCurrentStreaks($db,'test',$schedule,$source);
if($streak['rows'][0]['count']!==1)throw new RuntimeException('Future game included');
echo "PASS: games retain H/SF/SH and metadata, skip league/rolling work, reuse streak PAs and exclude future games\n";
