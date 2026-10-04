<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (count($argv)<2) throw new RuntimeException('Usage: php apply-player-family-wiki.php PRIVATE_DB_CONFIG [--apply]');
$config=require $argv[1];
$pdo=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$root=dirname(__DIR__);
$plan=json_decode(file_get_contents($root.'/data/player-family/namu-families.json'),true,512,JSON_THROW_ON_ERROR);
$apply=in_array('--apply',$argv,true);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$pdo->beginTransaction();
try {
    $before=$pdo->query('SELECT * FROM kbo_player_family ORDER BY PK FOR UPDATE')->fetchAll();
    $lookup=$pdo->prepare('SELECT * FROM kbo_player_data WHERE player_id=? FOR UPDATE');
    $originalPlayers=[];$newPlayers=[];
    $fields=['player_id','name','pos','team','birth','body','backNo','throw','bat','draft','is_kbodle','is_foreign'];
    $addPlayer=$pdo->prepare('INSERT INTO kbo_player_data (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
    foreach($plan['verified_players'] as $verified) {
        $profile=$verified['profile'];$id=(int)$profile['player_id'];
        if($verified['source_url']!=='https://www.koreabaseball.com/Record/Player/HitterDetail/Basic.aspx?playerId='.$id)throw new RuntimeException('Invalid official profile source');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$profile['birth'])||!in_array($profile['throw'],['우투','좌투','양투','우언','우사','좌언','좌사'],true)||!in_array($profile['bat'],['우타','좌타','양타'],true))throw new RuntimeException('Incomplete official profile');
        $lookup->execute([$id]);$current=$lookup->fetch();
        if($current) {
            if($current['name']!==$profile['name']||$current['birth']!==$profile['birth'])throw new RuntimeException('Official identity conflict: '.$id);
            $originalPlayers[$id]=$current;
        } else {
            $newPlayers[$id]=$profile;
            if($apply)$addPlayer->execute(array_map(static fn($field)=>$profile[$field],$fields));
        }
    }
    $addFamily=$pdo->prepare('INSERT INTO kbo_player_family (player_id,relative_player_id,relationship) VALUES (?,?,?)');
    $pairs=[];$added=0;$existing=0;
    $records=array_merge($plan['inserts'],$plan['existing']);
    foreach($records as $item) {
        foreach([['player_id','player_name','player_birth'],['relative_player_id','relative_name','relative_birth']] as [$idKey,$nameKey,$birthKey]) {
            $id=(int)$item[$idKey];$lookup->execute([$id]);$current=$lookup->fetch();
            $player=$current?:($newPlayers[$id]??null);
            if(!$player||$player['name']!==$item[$nameKey]||$player['birth']!==$item[$birthKey])throw new RuntimeException('Player identity changed: '.$id);
            if($current&&!isset($newPlayers[$id]))$originalPlayers[$id]=$current;
        }
        $ids=[(int)$item['player_id'],(int)$item['relative_player_id']];sort($ids);$pair=implode(':',$ids);
        if($ids[0]===$ids[1]||isset($pairs[$pair]))throw new RuntimeException('Invalid or duplicate planned pair: '.$pair);
        $pairs[$pair]=true;
        $matches=array_values(array_filter($before,static fn($row)=>
            ((int)$row['player_id']===$item['player_id']&&(int)$row['relative_player_id']===$item['relative_player_id'])||
            ((int)$row['relative_player_id']===$item['player_id']&&(int)$row['player_id']===$item['relative_player_id'])));
        if($matches) {
            if(count($matches)!==1)throw new RuntimeException('Duplicate existing pair: '.$pair);
            $row=$matches[0];$expected=(int)$row['player_id']===$item['player_id']?$item['relationship']:$item['reverse_relationship'];
            if($row['relationship']!==$expected&&!($expected==='삼촌'&&$row['relationship']==='외삼촌'))throw new RuntimeException('Existing relationship conflict: '.$pair);
            $existing++;
        } else {
            if($apply)$addFamily->execute([$item['player_id'],$item['relative_player_id'],$item['relationship']]);
            $added++;
        }
    }
    if($apply) {
        $after=$pdo->query('SELECT * FROM kbo_player_family ORDER BY PK')->fetchAll();$byPk=array_column($after,null,'PK');
        foreach($before as $row)if(($byPk[$row['PK']]??null)!==$row)throw new RuntimeException('Existing family row changed');
        if(count($after)!==count($before)+$added)throw new RuntimeException('Family count mismatch');
        foreach($records as $item) {
            $matches=array_values(array_filter($after,static fn($row)=>
                ((int)$row['player_id']===$item['player_id']&&(int)$row['relative_player_id']===$item['relative_player_id'])||
                ((int)$row['relative_player_id']===$item['player_id']&&(int)$row['player_id']===$item['relative_player_id'])));
            if(count($matches)!==1)throw new RuntimeException('Pair verification failed');
        }
        foreach($originalPlayers as $id=>$row){$lookup->execute([$id]);if($lookup->fetch()!==$row)throw new RuntimeException('Existing player changed: '.$id);}
        foreach($newPlayers as $id=>$profile){$lookup->execute([$id]);$actual=$lookup->fetch();foreach($fields as $field)if((string)$actual[$field]!== (string)$profile[$field])throw new RuntimeException('New player verification failed: '.$id);}
        if($added||$newPlayers) {
            $cache=$root.'/.family-enrichment';if(!is_dir($cache)&&!mkdir($cache,0700,true))throw new RuntimeException('Snapshot folder unavailable');
            $snapshot=$cache.'/apply-before-'.substr(hash_file('sha256',$root.'/data/player-family/namu-families.json'),0,12).'.json.gz';
            if(file_exists($snapshot))throw new RuntimeException('Existing snapshot must be retained; inspect prior run before applying');
            $content=gzencode(json_encode(['family'=>$before,'existing_players'=>$originalPlayers,'new_player_ids'=>array_keys($newPlayers)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            if(file_put_contents($snapshot,$content)===false)throw new RuntimeException('Snapshot failed');
        }
        $pdo->commit();
    }else $pdo->rollBack();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
echo json_encode(['mode'=>$apply?'applied':'preview','added_family'=>$added,'existing_family'=>$existing,'added_player_ids'=>array_keys($newPlayers),'family_total'=>count($before)+($apply?$added:0),'existing_rows_unchanged'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
