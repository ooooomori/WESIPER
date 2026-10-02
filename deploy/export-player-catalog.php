<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port'] ?? 3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo json_encode(['players'=>$db->query('SELECT player_id,name,oldname,pos,birth,team,is_kbodle,school FROM kbo_player_data ORDER BY player_id')->fetchAll(),'ddl'=>$db->query('SHOW CREATE TABLE kbo_player_data')->fetch(),'titleholder_exists'=>$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kbo_player_titleholder'")->fetchColumn()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
