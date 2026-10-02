<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config=require '/opt/bitnami/apache/htdocs/config/database.php';
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// Public player identity and existing game-profile flags only; no SELECT *,
// schema, credentials, user records, event definitions or unrelated data export.
echo json_encode(['players'=>$db->query('SELECT player_id,name,oldname,birth,pos,team,is_WBC,is_GG,is_AS FROM kbo_player_data ORDER BY player_id')->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
