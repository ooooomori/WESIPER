<?php
declare(strict_types=1);
// 읽기 전용: 이동 현황 표기 확인용 샘플 출력
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$out=[];
foreach($db->query("SELECT event_type,COUNT(*) n,SUM(note IS NOT NULL AND note<>'') with_note FROM kbo_player_movements GROUP BY event_type ORDER BY n DESC") as $r){
    $s=$db->prepare("SELECT event_date,team,player_text,note,old_back_no,new_back_no FROM kbo_player_movements WHERE event_type=? ORDER BY (note IS NULL OR note=''),event_date DESC LIMIT 4");
    $s->execute([$r['event_type']]);$r['samples']=$s->fetchAll();$out['types'][]=$r;
}
$out['teams']=$db->query("SELECT team,COUNT(*) n FROM kbo_player_movements GROUP BY team ORDER BY n DESC")->fetchAll();
$out['per_player']=$db->query("SELECT MAX(n) max_rows,AVG(n) avg_rows FROM (SELECT player_id,COUNT(*) n FROM kbo_player_movements WHERE player_id IS NOT NULL GROUP BY player_id) t")->fetch();
$top=$db->query("SELECT player_id,COUNT(*) n FROM kbo_player_movements WHERE player_id IS NOT NULL GROUP BY player_id ORDER BY n DESC LIMIT 1")->fetch();
$s=$db->prepare("SELECT id,event_date,event_type,team,note,source_page,source_row FROM kbo_player_movements WHERE player_id=? ORDER BY event_date DESC,id DESC");$s->execute([$top['player_id']]);$out['busiest']=['player_id'=>$top['player_id'],'rows'=>$s->fetchAll()];
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
