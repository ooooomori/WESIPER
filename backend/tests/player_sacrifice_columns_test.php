<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-records.php';
foreach(['희비'=>[1,0],'중희플'=>[1,0],'투희번'=>[0,1],'희타'=>[0,1],'희실'=>[0,1],'좌안'=>[0,0]] as $text=>$expected){
    $event=profileBatEvent(['pa_result'=>$text]);
    if([$event['sf'],$event['sh']]!==$expected)throw new RuntimeException('Sacrifice classification mismatch');
}
$s=profileBatWindow([['pa_result'=>'중희플'],['pa_result'=>'우희비'],['pa_result'=>'투희번'],['pa_result'=>'좌안']],null,7);
if($s['sf']!==2||$s['sh']!==1||$s['pa']!==4||$s['ab']!==1)throw new RuntimeException('Recent sacrifices or AB mismatch');
$s=profileBatWindow([],null,7);
if($s['sf']!==0||$s['sh']!==0)throw new RuntimeException('Empty recent sacrifices');
echo "PASS: SF/SH classification, recent totals, at-bat exclusion and empty windows\n";
