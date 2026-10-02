<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../backend/lib/player-search.php';
$c=require $argv[1];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach (['김민','이승엽','김민'] as $keyword) {
    $start=microtime(true); $rows=searchKboPlayers($db,$keyword,($argv[2]??'')!=='--uncached');
    echo json_encode(['keyword'=>$keyword,'milliseconds'=>round((microtime(true)-$start)*1000,1),'results'=>count($rows),'first'=>array_map(static fn($r)=>[$r['name'],$r['player_id'],$r['first_team_games'],$r['futures_games']],array_slice($rows,0,5))],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
