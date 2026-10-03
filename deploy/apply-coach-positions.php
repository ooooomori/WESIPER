<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$c=require getenv('WESIPER_DB_CONFIG');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->beginTransaction();
try{
 $before=$db->query("SELECT player_id,name,draft,pos FROM kbo_player_data WHERE draft LIKE '%코치' ORDER BY player_id FOR UPDATE")->fetchAll();
 if(!in_array('--apply',$argv,true)){$db->rollBack();echo json_encode(['targets'=>count($before),'change_needed'=>count(array_filter($before,fn($r)=>$r['pos']!=='코치')),'preview'=>array_slice($before,0,8)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
 $backup=dirname(__DIR__).'/output/coach-positions-before-'.gmdate('Ymd-His').'.json';file_put_contents($backup,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 $changed=$db->exec("UPDATE kbo_player_data SET pos='코치' WHERE draft LIKE '%코치' AND COALESCE(pos,'')<>'코치'");
 $after=$db->query("SELECT player_id,name,draft,pos FROM kbo_player_data WHERE draft LIKE '%코치' ORDER BY player_id")->fetchAll();
 $expected=$before;foreach($expected as &$r)$r['pos']='코치';unset($r);
 if($after!==$expected)throw new RuntimeException('Coach position verification failed');
 $db->commit();echo json_encode(['targets'=>count($before),'changed'=>$changed,'verified'=>count($after),'backup'=>$backup],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
