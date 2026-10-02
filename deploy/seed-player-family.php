<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__).'/backend';
require dirname(__DIR__).'/backend/api/kbocandle/common.php';
$apply=in_array('--apply',$argv,true);
$plan=json_decode(file_get_contents(dirname(__DIR__).'/data/player-family/famous-families.json'),true,512,JSON_THROW_ON_ERROR);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$pdo->beginTransaction();
try {
    $before=$pdo->query('SELECT * FROM kbo_player_family ORDER BY PK FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $playerQuery=$pdo->prepare('SELECT name,birth FROM kbo_player_data WHERE player_id=?');
    $insert=$pdo->prepare('INSERT INTO kbo_player_family (player_id,relative_player_id,relationship) VALUES (?,?,?)');
    $added=0;$existing=0;$pairs=[];$resolved=[];
    foreach($plan as $item) {
        foreach ([['player_id','player_name','player_birth'],['relative_player_id','relative_name','relative_birth']] as [$idKey,$nameKey,$birthKey]) {
            $playerQuery->execute([$item[$idKey]]);$player=$playerQuery->fetch(PDO::FETCH_ASSOC);
            if (!$player || $player['name']!==$item[$nameKey] || $player['birth']!==$item[$birthKey]) throw new RuntimeException('Player identity mismatch: '.$item[$idKey]);
        }
        $ids=[$item['player_id'],$item['relative_player_id']];sort($ids);$pair=implode(':',$ids);
        if(isset($pairs[$pair]))throw new RuntimeException('Duplicate pair in plan');$pairs[$pair]=true;
        $matches=array_values(array_filter($before,static fn($r)=>(int)$r['player_id']===$item['player_id']&&(int)$r['relative_player_id']===$item['relative_player_id']||(int)$r['player_id']===$item['relative_player_id']&&(int)$r['relative_player_id']===$item['player_id']));
        if($matches) {
            if(count($matches)!==1)throw new RuntimeException('Duplicate existing pair: '.$pair);
            $row=$matches[0];$expected=(int)$row['player_id']===$item['player_id']?$item['relationship']:$item['reverse_relationship'];
            if($row['relationship']!==$expected)throw new RuntimeException('Conflicting existing relationship: '.$pair);
            $existing++;
        } else {
            $added++;
            if($apply) {
                if($added===1) {
                    $backupDir=dirname(__DIR__).'/.player-career';
                    if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true))throw new RuntimeException('Backup directory unavailable');
                    $backup=$backupDir.'/family-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
                    if(file_put_contents($backup,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Backup failed');
                }
                $insert->execute([$item['player_id'],$item['relative_player_id'],$item['relationship']]);
            }
        }
        $resolved[]=['player'=>$item['player_name'],'relative'=>$item['relative_name'],'relationship'=>$item['relationship']];
    }
    if($apply) {
        $after=$pdo->query('SELECT * FROM kbo_player_family ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC);$byPk=array_column($after,null,'PK');
        foreach($before as $row)if(($byPk[$row['PK']]??null)!==$row)throw new RuntimeException('Existing family row changed');
        if(count($after)!==count($before)+$added)throw new RuntimeException('Family count mismatch');
        foreach($plan as $item) {
            $matches=array_values(array_filter($after,static fn($r)=>
                ((int)$r['player_id']===$item['player_id']&&(int)$r['relative_player_id']===$item['relative_player_id']&&$r['relationship']===$item['relationship'])||
                ((int)$r['player_id']===$item['relative_player_id']&&(int)$r['relative_player_id']===$item['player_id']&&$r['relationship']===$item['reverse_relationship'])));
            if(count($matches)!==1)throw new RuntimeException('Family relationship verification failed');
        }
        $pdo->commit();
    }else $pdo->rollBack();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo json_encode(['mode'=>$apply?'applied':'preview','added'=>$added,'existing'=>$existing,'otherRecordsUnchanged'=>true,'relationships'=>$resolved],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
