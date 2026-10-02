<?php
if(PHP_SAPI!=='cli')exit;
require '/home/bitnami/wesiper-weather-preview/api/kbocandle/common.php';
require '/home/bitnami/wesiper-weather-preview/lib/player-year-records.php';
foreach([[78168,false],[77829,true],[76849,false]] as [$id,$pitcher]){
    $data=profileYearRecords($pdo,(string)$id,$pitcher,getKBOSchedule());
    foreach($data['rows'] as $row)if($row['teams']){
        foreach($pitcher?['wins','losses','holds','saves']:['h','hr','sb','cs'] as $key){
            if(array_sum(array_map(static fn($child)=>$child['stats'][$key],$row['teams']))!==$row['stats'][$key])throw new RuntimeException('Team sum mismatch');
        }
    }
    foreach($pitcher?['wins','losses','holds','saves']:['h','hr','sb','cs'] as $key){
        if(array_sum(array_map(static fn($row)=>$row['stats'][$key],$data['rows']))!==$data['career'][$key])throw new RuntimeException('Career sum mismatch');
    }
    echo json_encode(['id'=>$id,'years'=>count($data['rows']),'transfers'=>array_column(array_filter($data['rows'],static fn($r)=>count($r['teams'])>1),'year'),'latest'=>$data['rows'][0]??null,'career'=>$data['career']],JSON_UNESCAPED_UNICODE)."\n";
}
$events=[['game_id'=>'test','pa_result'=>'좌안','sb'=>1,'cs'=>0,'rbi'=>0,'r'=>0,'is_gs'=>1],['game_id'=>'test','pa_result'=>'4구','sb'=>0,'cs'=>1,'rbi'=>0,'r'=>0,'is_gs'=>1]];
$s=profileYearTotals($events,false,[],[]);
if($s['effectiveOps']!=='1.500'||$s['games']!==1||$s['starts']!==1)throw new RuntimeException('Effective OPS fixture failed');
echo "PASS\n";
