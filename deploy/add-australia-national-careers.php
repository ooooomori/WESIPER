<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__).'/backend';
require dirname(__DIR__).'/backend/api/kbocandle/common.php';
$apply = in_array('--apply', $argv, true);
$premier2019 = 'https://www.japan-baseball.jp/en/team/topteam/2019/premier12/overview.html';
$premier2024 = 'https://baseball.com.au/premier12/';
$apbc2023 = 'https://baseball.com.au/news/japan-crowd-loves-us/';
$plan = [];
foreach ([56168 => '케네디', 56464 => '오러클린', 31012 => '홀'] as $pid => $name) {
    foreach ([2023 => '8강', 2026 => '1라운드 탈락'] as $year => $note) {
        $plan[] = ['player_id'=>$pid, 'name'=>$name, 'type'=>'WBC', 'year'=>$year, 'note'=>$note,
            'source'=>"https://statsapi.mlb.com/api/v1/schedule?sportId=51&startDate=$year-03-01&endDate=$year-03-31"];
    }
}
$plan[] = ['player_id'=>56168,'name'=>'케네디','type'=>'프리미어12','year'=>2019,'note'=>'6위','source'=>$premier2019];
$plan[] = ['player_id'=>56168,'name'=>'케네디','type'=>'프리미어12','year'=>2024,'note'=>'1라운드 탈락','source'=>$premier2024];
$plan[] = ['player_id'=>31012,'name'=>'홀','type'=>'프리미어12','year'=>2024,'note'=>'1라운드 탈락','source'=>$premier2024];
$plan[] = ['player_id'=>31012,'name'=>'홀','type'=>'APBC','year'=>2023,'note'=>'4위','source'=>$apbc2023];
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$pdo->beginTransaction();
try {
    $before = $pdo->query('SELECT * FROM kbo_player_career ORDER BY PK FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $lookup = $pdo->prepare("SELECT player_id,name FROM kbo_player_data WHERE player_id=?");
    $insert = $pdo->prepare("INSERT INTO kbo_player_career (player_id,category,type,team,country,year,month,pos,note) VALUES (?,'national',?,NULL,'호주',?,NULL,NULL,?)");
    $added = 0; $resolved = [];
    foreach ($plan as $item) {
        $lookup->execute([$item['player_id']]); $player = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$player || $player['name'] !== $item['name']) throw new RuntimeException('Player identity mismatch: '.$item['player_id']);
        $matches = array_values(array_filter($before, static fn($r) => (int)$r['player_id']===$item['player_id'] && $r['category']==='national' && $r['type']===$item['type'] && (int)$r['year']===$item['year']));
        if (count($matches)>1) throw new RuntimeException('Duplicate existing career: '.$item['player_id'].'/'.$item['type'].'/'.$item['year']);
        if ($matches) {
            $row = $matches[0];
            if ($row['country']!=='호주' || $row['note']!==$item['note']) throw new RuntimeException('Existing career differs: '.$row['PK']);
            $item['PK'] = (int)$row['PK'];
        } else {
            $added++;
            if ($apply) {
                if ($added===1) {
                    $backup = dirname(__DIR__).'/.player-career/australia-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
                    if (file_put_contents($backup,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Backup failed');
                }
                $insert->execute([$item['player_id'],$item['type'],$item['year'],$item['note']]);
                $item['PK'] = (int)$pdo->lastInsertId();
            }
        }
        $resolved[] = $item;
    }
    if ($apply) {
        $after = $pdo->query('SELECT * FROM kbo_player_career ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC);
        $afterByPk = array_column($after,null,'PK');
        foreach ($before as $row) if (($afterByPk[$row['PK']]??null)!==$row) throw new RuntimeException('Existing row changed: '.$row['PK']);
        if (count($after)!==count($before)+$added) throw new RuntimeException('Inserted count mismatch');
        foreach ($resolved as $item) {
            $row=$afterByPk[$item['PK']]??null;
            if (!$row || (int)$row['player_id']!==$item['player_id'] || $row['type']!==$item['type'] || (int)$row['year']!==$item['year'] || $row['country']!=='호주' || $row['note']!==$item['note']) throw new RuntimeException('Inserted career verification failed');
        }
        $pdo->commit();
    } else $pdo->rollBack();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
// Keep the country/result manifests reusable after adding new foreign careers.
if ($apply) {
    $countryPath=dirname(__DIR__).'/data/player-career/national-countries.json';
    $resultPath=dirname(__DIR__).'/data/player-career/wbc-country-results.json';
    $countries=json_decode(file_get_contents($countryPath),true,512,JSON_THROW_ON_ERROR);
    $results=json_decode(file_get_contents($resultPath),true,512,JSON_THROW_ON_ERROR);
    foreach ($resolved as $item) {
        if (!in_array($item['PK'],array_column($countries,'PK'),true)) $countries[]=['PK'=>$item['PK'],'player_id'=>$item['player_id'],'name'=>$item['name'],'type'=>$item['type'],'year'=>$item['year'],'country'=>'호주','sources'=>[$item['source']]];
        if ($item['type']==='WBC') {
            $found=false;
            foreach ($results as &$group) {
                if ($group['year']===$item['year'] && $group['country']==='호주') {
                    if ($group['note']!==$item['note']) throw new RuntimeException('Result manifest conflict');
                    if (!in_array($item['PK'],$group['rows'],true)) $group['rows'][]=$item['PK'];
                    $found=true;
                }
            }
            unset($group);
            if (!$found) throw new RuntimeException('Missing Australia result group');
        }
    }
    foreach ([$countryPath=>$countries,$resultPath=>$results] as $path=>$data) {
        if (file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL,LOCK_EX)===false) throw new RuntimeException('Manifest save failed; rerun to recover');
    }
}
echo json_encode(['mode'=>$apply?'applied':'preview','added'=>$added,'existing'=>count($plan)-$added,'otherRecordsUnchanged'=>true,'careers'=>$resolved],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
