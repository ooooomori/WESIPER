<?php
declare(strict_types=1);
/*
 * 연도별 기록 '기본' 탭의 리그 1위 표를 미리 계산해 kbo_player_year_leaders에 저장한다.
 * 사용법: php build-year-leaders.php --check|--apply [--year=2026] <database-config.php>
 *   --check : 테이블·계산만 확인(저장 안 함)   --apply : 저장
 *   --year  : 한 시즌만 다시 계산(매일 올해 시즌 갱신용). 생략하면 1982년~올해 전체.
 * lib/ 폴더(backend/lib)와 같은 위치에서 실행한다(run-year-leaders.ps1이 함께 올려 실행).
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
@set_time_limit(0);ini_set('memory_limit','1024M');
$mode='--check';$onlyYear=null;$config=null;
foreach(array_slice($argv,1) as $arg){
    if(in_array($arg,['--check','--apply'],true))$mode=$arg;
    elseif(preg_match('/^--year=(\d{4})$/',$arg,$m))$onlyYear=(int)$m[1];
    else $config=$arg;
}
$lib=__DIR__.'/lib';
foreach(['player-season-schedule.php','player-year-leaders.php'] as $file)require_once "$lib/$file";
$c=require ($config??dirname(__DIR__).'/backend/config/database.php');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET SESSION lock_wait_timeout=10');

$t=$db->query("SELECT COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='kbo_player_data' AND column_name='player_id'")->fetch(PDO::FETCH_ASSOC);
if(!$t)throw new RuntimeException('kbo_player_data.player_id not found');
$pidType=$t['COLUMN_TYPE'].($t['COLLATION_NAME']?' CHARACTER SET '.$t['CHARACTER_SET_NAME'].' COLLATE '.$t['COLLATION_NAME']:'');
$exists=(bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='kbo_player_year_leaders'")->fetchColumn();
echo json_encode(['mode'=>$mode,'table_exists'=>$exists,'player_id_type'=>$pidType],JSON_UNESCAPED_UNICODE).PHP_EOL;
if($mode==='--apply'&&!$exists){
    $db->exec("CREATE TABLE kbo_player_year_leaders (
        year SMALLINT UNSIGNED NOT NULL COMMENT '시즌',
        role ENUM('batter','pitcher') NOT NULL COMMENT '타자/투수 기록',
        stat VARCHAR(20) NOT NULL COMMENT '연도별 기록 열 키(avg, hr, era ...)',
        player_id $pidType NOT NULL COMMENT '그 해 리그 1위(공동 포함) 선수',
        computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (year, role, stat, player_id),
        KEY idx_year_leaders_player (player_id, role, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='연도별 리그 1위(정규시즌, 기본 탭 기록)'");
    echo "created kbo_player_year_leaders\n";
}

$schedule=profileSchedule();
$currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
$years=$onlyYear!==null?[$onlyYear]:array_values(array_unique(array_merge(range(1982,2000),array_map('intval',array_keys($schedule)))));
sort($years);
$insert=$mode==='--apply'?$db->prepare('INSERT INTO kbo_player_year_leaders (year,role,stat,player_id) VALUES (?,?,?,?)'):null;
$delete=$mode==='--apply'?$db->prepare('DELETE FROM kbo_player_year_leaders WHERE year=?'):null;
$total=0;
foreach($years as $year){
    if($year>$currentYear)continue;
    $started=microtime(true);
    $leaders=$year<=2000?profileComputeHistoricalYearLeaders($db,$year):(isset($schedule[$year])?profileComputeModernYearLeaders($db,$year,$schedule):['batter'=>[],'pitcher'=>[]]);
    $rows=[];foreach($leaders as $role=>$stats)foreach($stats as $stat=>$ids)foreach(array_unique($ids) as $id)$rows[]=[$year,$role,$stat,$id];
    if($mode==='--apply'){
        $db->beginTransaction();
        try{$delete->execute([$year]);foreach($rows as $row)$insert->execute($row);$db->commit();}
        catch(Throwable $e){$db->rollBack();throw $e;}
    }
    $total+=count($rows);
    // 확인용: 타율·홈런·ERA 1위 선수 ID
    $sample=['avg'=>$leaders['batter']['avg']??[],'hr'=>$leaders['batter']['hr']??[],'era'=>$leaders['pitcher']['era']??[]];
    printf("%d rows=%d %.1fs %s\n",$year,count($rows),microtime(true)-$started,json_encode($sample));
}
echo "DONE total=$total\n";
