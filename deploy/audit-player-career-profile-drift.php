<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
$config=require __DIR__.'/../backend/config/database.php';
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['port']??3306,$config['database']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$state=json_decode(file_get_contents(__DIR__.'/player-career-state.json'),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/^kbo_player_data_backup_career_\d{8}_\d{6}$/',$state['backup'])) throw new RuntimeException('Unexpected backup name');
$report=[];
foreach ($db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN) as $column) {
    if (in_array($column,['oldname','img','is_foreign','fullname'],true)) continue;
    $quoted='`'.str_replace('`','``',$column).'`';
    $sql='FROM kbo_player_data p JOIN `'.$state['backup'].'` b ON b.player_id=p.player_id WHERE NOT(BINARY p.'.$quoted.' <=> BINARY b.'.$quoted.')';
    $count=(int)$db->query('SELECT COUNT(*) '.$sql)->fetchColumn();
    if ($count) $report[]=['column'=>$column,'count'=>$count,'players'=>$db->query('SELECT p.player_id,p.name '.$sql.' ORDER BY p.player_id LIMIT 20')->fetchAll()];
}
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
