<?php
declare(strict_types=1);
/*
 * 크롤러 갱신(revision) 직후 리그 전체 집계 캐시를 미리 채운다.
 * 첫 방문자가 수십 초~수 분짜리 전체 집계를 떠안고 다른 요청이 락을 기다리며 php-fpm 워커가 막히는 것을 막는다.
 * 운영 PHP와 같은 lib·같은 사용자(daemon)로 실행해야 같은 캐시 파일을 채운다.
 * 사용: sudo -u daemon php warm-profile-caches.php <htdocs lib 디렉터리> <DB 설정 파일>
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
[$lib,$config]=[$argv[1]??'',$argv[2]??''];
if(!is_dir($lib)||!is_file($config)){fwrite(STDERR,"Usage: php warm-profile-caches.php LIB_DIR DB_CONFIG\n");exit(2);}
date_default_timezone_set('Asia/Seoul');
foreach(['player-season-schedule','player-records','player-year-records','player-overview-cache','player-rankings','player-year-qualified'] as $file)require_once "$lib/$file.php";
$c=require $config;
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

$log=static fn(string $message)=>print(date('c').' '.$message.PHP_EOL);
$failed=0;
$step=static function(string $name,callable $work)use($log,&$failed):void{
    $start=microtime(true);
    try{$work();$log(sprintf('ok   %-40s %7.1fs',$name,microtime(true)-$start));}
    catch(Throwable $error){$failed++;$log(sprintf('FAIL %-40s %7.1fs %s',$name,microtime(true)-$start,$error->getMessage()));}
};

// 표본 선수: 가장 최근 완료된 1군 경기의 타자·투수. 선수별 계산 경로가 실제 API와 같은 인자로 리그 집계를 부르게 한다.
$code=$db->query('SELECT game_code FROM kbo_schedule WHERE league_level=1 AND away_score IS NOT NULL AND home_score IS NOT NULL ORDER BY game_date DESC,game_code DESC LIMIT 1')->fetchColumn();
if(!$code){$log('no completed game');exit(1);}
$pick=static function(string $table)use($db,$code):?string{$q=$db->prepare("SELECT player_id FROM `$table` WHERE league_level=1 AND game_id LIKE ? AND player_id IS NOT NULL LIMIT 1");$q->execute([$code.'%']);$id=$q->fetchColumn();return $id===false?null:(string)$id;};
$samples=['batter'=>$pick('kbo_season_records'),'pitcher'=>$pick('kbo_season_pitch_records')];
$log('revision='.profileRankingRevision().' game='.$code.' batter='.($samples['batter']??'-').' pitcher='.($samples['pitcher']??'-'));
// 새 경기가 없어 revision이 그대로면 캐시도 그대로 유효하므로 건너뛴다(--force로 강제 실행).
$marker=sys_get_temp_dir().'/wesiper-cache-warm-'.(function_exists('posix_geteuid')?posix_geteuid():'web').'.done';
$stamp=profileRankingRevision().'|'.PROFILE_HISTORY_VERSION;
if(!in_array('--force',$argv,true)&&is_file($marker)&&trim((string)file_get_contents($marker))===$stamp){$log('unchanged since last warm, skipped');exit(0);}
$players=[];
$q=$db->prepare('SELECT player_id AS PlayerId,pos AS Pos,is_kbodle AS IsKbodle,team AS Team FROM kbo_player_data WHERE player_id=?');
foreach($samples as $role=>$pid)if($pid!==null){$q->execute([$pid]);if($row=$q->fetch())$players[$role]=$row;}

$schedule=profileSchedule();
$year=(int)date('Y');

// 연도별 기록: 시즌 종류마다 리그 타격·투수 집계(player-batting-league, player-year-league)를 채운다.
foreach(['regular','preseason','postseason','futures'] as $season){
    $level=$season==='futures'?2:1;$seasonSchedule=profileYearRecordsSchedule($schedule,$season);
    foreach($samples as $role=>$pid)if($pid!==null)$step("year-records $season $role",static fn()=>profileComputeYearRecords($db,$pid,$role==='pitcher',$seasonSchedule,$level,$season));
}
$step('team games by year',static fn()=>profileTeamGamesByYear($db,$schedule));
// 개요(주요 기록): 선수별 캐시를 거치지 않고 리그 집계 경로를 실행한다.
foreach($players as $role=>$player)$step("overview $role",static fn()=>profileComputeOverviewRecords($db,$player,$schedule));
// 리그 순위(ranks 탭): 올해 시즌·통산, 1군·퓨처스.
foreach([[false,1],[true,1],[false,2]] as [$career,$level])$step(sprintf('rankings %d %s league%d',$year,$career?'career':'season',$level),static fn()=>profileRankings($db,$year,$schedule,$career,$level));

if(!$failed)@file_put_contents($marker,$stamp);
$log($failed?"done with $failed failure(s)":'done');
exit($failed?1:0);
