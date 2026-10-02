<?php
if (PHP_SAPI !== 'cli') exit;
$c=require '/opt/bitnami/apache/conf/wesiper-db.php';
$db=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['database']),$c['username'],$c['password']);
echo json_encode(['preseasonCompleted'=>$db->query('SELECT COUNT(*) FROM kbo_schedule WHERE game_date BETWEEN "2026-03-01" AND "2026-03-27" AND away_score IS NOT NULL AND home_score IS NOT NULL')->fetchColumn(),'regularCompleted'=>$db->query('SELECT COUNT(*) FROM kbo_schedule WHERE game_date BETWEEN "2026-03-28" AND "2026-12-31" AND away_score IS NOT NULL AND home_score IS NOT NULL')->fetchColumn()],JSON_UNESCAPED_UNICODE);
