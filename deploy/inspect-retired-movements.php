<?php
declare(strict_types=1);
// 읽기 전용: 은퇴 선수 이동 현황 연결 상태 진단
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$out=[];
$out['mapped_by_status']=$db->query("SELECT p.is_kbodle, COUNT(*) movement_rows, COUNT(DISTINCT m.player_id) players FROM kbo_player_movements m JOIN kbo_player_data p ON p.player_id=m.player_id GROUP BY p.is_kbodle")->fetchAll();
$out['unmapped_rows']=(int)$db->query("SELECT COUNT(*) FROM kbo_player_movements WHERE player_id IS NULL")->fetchColumn();
// 미연결 행의 이름 후보: 같은 이름 선수 수와 은퇴 후보 포함 여부
$out['unmapped_name_candidates']=$db->query("SELECT CASE WHEN c.n IS NULL THEN '0 (선수 테이블에 이름 없음)' WHEN c.n=1 THEN '1' ELSE '2+' END candidates, COUNT(*) rows_count, SUM(c.retired>0) with_retired_candidate
    FROM kbo_player_movements m
    LEFT JOIN (SELECT name, COUNT(*) n, SUM(is_kbodle=0) retired FROM kbo_player_data GROUP BY name) c ON c.name=m.player_name
    WHERE m.player_id IS NULL GROUP BY 1")->fetchAll();
// 은퇴(활동 종료) 기록 형태의 이동 중 미연결 예시
$out['unmapped_samples']=$db->query("SELECT m.event_date,m.event_type,m.team,m.player_text,m.note,(SELECT GROUP_CONCAT(CONCAT(p.player_id,':',p.is_kbodle,':',COALESCE(p.team,'')) SEPARATOR ' | ') FROM kbo_player_data p WHERE p.name=m.player_name) candidates
    FROM kbo_player_movements m WHERE m.player_id IS NULL ORDER BY m.event_date DESC LIMIT 25")->fetchAll();
// 2017년 이후 기록이 있는 은퇴 선수 중 이동 현황이 하나도 연결되지 않은 선수 수
$out['retired_recent_without_movements']=$db->query("SELECT COUNT(*) players FROM kbo_player_data p
    WHERE p.is_kbodle=0 AND EXISTS (SELECT 1 FROM kbo_season_records r WHERE r.player_id=p.player_id AND r.game_date>='2017-01-01' UNION ALL SELECT 1 FROM kbo_season_pitch_records r WHERE r.player_id=p.player_id AND r.game_date>='2017-01-01' LIMIT 1)
      AND NOT EXISTS (SELECT 1 FROM kbo_player_movements m WHERE m.player_id=p.player_id)")->fetch();
// 특정 선수 확인: 박병호(75125)
$s=$db->prepare("SELECT player_id,name,is_kbodle,team,retire FROM kbo_player_data WHERE player_id=75125 OR name='박병호'");$s->execute();$out['park_candidates']=$s->fetchAll();
$s=$db->prepare("SELECT id,event_date,event_type,team,player_id,player_text,note FROM kbo_player_movements WHERE player_name='박병호' OR player_id=75125 ORDER BY event_date DESC");$s->execute();$out['park_movements']=$s->fetchAll();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
