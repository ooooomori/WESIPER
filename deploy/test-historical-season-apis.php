<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$release=$argv[1]??'/home/bitnami/wesiper-season-api-release-20261001';
require '/opt/bitnami/apache/htdocs/api/kbocandle/common.php';
require $release.'/lib/player-year-records.php';
require $release.'/lib/player-season-schedule.php';
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
// Select official linked players: historical-only and careers crossing 2001.
$ids=[20001];
$q=$pdo->query("SELECT DISTINCT t.player_id FROM kbo_player_season_batting_totals t JOIN kbo_player_data p ON p.player_id=t.player_id WHERE p.pos NOT LIKE '%투수%' AND t.row_scope='total' AND t.series_id=0 AND EXISTS (SELECT 1 FROM kbo_season_records r WHERE r.league_level=1 AND r.player_id=t.player_id) ORDER BY t.player_id LIMIT 2");
foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[]=(int)$id;
$q=$pdo->query("SELECT DISTINCT t.player_id FROM kbo_player_season_pitching_totals t JOIN kbo_player_data p ON p.player_id=t.player_id WHERE p.pos LIKE '%투수%' AND t.row_scope='total' AND t.series_id=0 AND EXISTS (SELECT 1 FROM kbo_season_pitch_records r WHERE r.league_level=1 AND r.player_id=t.player_id) ORDER BY t.player_id LIMIT 2");
$pitchIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
$q=$pdo->query("SELECT player_id FROM kbo_player_season_pitching_totals WHERE row_scope='total' AND series_id=0 ORDER BY year,player_id LIMIT 1");$pitchIds[]=(int)$q->fetchColumn();
foreach([[false,array_unique($ids)],[true,array_unique($pitchIds)],[false,[78168]],[true,[77829]]] as [$pitcher,$players])foreach($players as $id){
    $modern=profileComputeGameYearRecords($pdo,(string)$id,$pitcher,profileSchedule());
    $data=profileYearRecords($pdo,(string)$id,$pitcher,profileSchedule());
    $historical=profileHistoricalRows($pdo,(string)$id,$pitcher);
    if(!$historical){check($data===$modern,'modern-only player changed');continue;}
    $newModern=array_values(array_filter($data['rows'],static fn($r)=>$r['year']>=2001));check($newModern===$modern['rows'],'modern season row changed');
    foreach($pitcher?['games','wins','losses','saves','holds','so','h','bb','r','er']:['games','pa','ab','h','hr','rbi','sb','cs','so','bb'] as $key){
        $sum=profileSum(array_column($data['rows'],'stats'),$key);check($sum===$data['career'][$key],'career mismatch '.$key);
    }
    if($pitcher){$outs=array_sum(array_map(static fn($r)=>profileInningOuts($r['stats']['innings']),$data['rows']));check($outs===profileInningOuts($data['career']['innings']),'fractional innings mismatch');}
    else {check($data['career']['effectiveOps']===null,'unsupported historical rate fabricated');if($data['career']['ab']>0)check($data['career']['obp']!==null&&$data['career']['ops']!==null,'supported historical rate missing');}
    $post=profileYearRecords($pdo,(string)$id,$pitcher,profileSchedule(),'postseason');
    foreach($post['rows'] as $row)if($row['year']<=2000){check(count($row['series'])>0,'postseason stages missing');foreach($row['series'] as $series)check(in_array($series['series_id'],[3,5,7],true),'wrong postseason stage');}
    $stmt=$pdo->prepare('SELECT player_id AS PlayerId,pos AS Pos,is_kbodle AS IsKbodle FROM kbo_player_data WHERE player_id=?');$stmt->execute([$id]);$player=$stmt->fetch(PDO::FETCH_ASSOC);
    $overview=profileRecords($pdo,$player,profileSchedule());check($overview!==null,'historical overview missing');
    if((string)$player['IsKbodle']==='0'){ $display=profileSeasonDisplayStats($data['career'],$pitcher);check($overview['stats']===$display,'overview career disagrees with year API');}
    echo json_encode(['id'=>$id,'pitcher'=>$pitcher,'years'=>array_column($data['rows'],'year'),'career_games'=>$data['career']['games']],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
$ranks=profileRankings($pdo,1982,profileSchedule(),false);check(count($ranks)>0,'historical counting ranks missing');
check(isset($ranks[20001])===false,'wrong year player leaked');
echo "HISTORICAL SEASON API TESTS PASSED\n";
