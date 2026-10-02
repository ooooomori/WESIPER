<?php
declare(strict_types=1);
if(PHP_SAPI !== 'cli') exit;
$config=require (getenv('WESIPER_DB_CONFIG') ?: __DIR__.'/../backend/config/database.php');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$column=$db->query("SHOW COLUMNS FROM kbo_player_data LIKE 'retire'")->fetch(PDO::FETCH_ASSOC);
if(!$column)$db->exec('ALTER TABLE kbo_player_data ADD COLUMN retire SMALLINT UNSIGNED NULL DEFAULT NULL');
echo json_encode(['retireColumn'=>$db->query("SHOW COLUMNS FROM kbo_player_data LIKE 'retire'")->fetch(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE).PHP_EOL;
