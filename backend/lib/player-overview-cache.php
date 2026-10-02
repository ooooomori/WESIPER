<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';
require_once __DIR__.'/player-rankings.php';

// The futures crawler and backfills do not bump the revision, so expire hourly as a fallback.
const PROFILE_OVERVIEW_CACHE_TTL=3600;

function profileOverviewCacheRead(array $cached, string $revision, int $now): ?array {
    if(($cached['revision']??null)!==$revision||!isset($cached['createdAt'])||$now-(int)$cached['createdAt']>=PROFILE_OVERVIEW_CACHE_TTL||$now<(int)$cached['createdAt']||!array_key_exists('records',$cached))return null;
    return ['records'=>$cached['records']];
}

function profileChooseOverviewRecords(array $player, int $year, callable $load): ?array {
    $ulsan=in_array(strtoupper(trim((string)($player['Team']??''))),['울산','울산 웨일즈','ULSAN'],true);
    if($ulsan&&($futures=$load($year,'futures'))!==null)return $futures;
    return $load(null,'regular')??$load(null,'futures');
}

function profileComputeOverviewRecords(PDO $db, array $player, array $schedule): ?array {
    $year=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y');
    return profileChooseOverviewRecords($player,$year,static fn($selectedYear,$season)=>profileRecords($db,$player,$schedule,$selectedYear,$season));
}

function profileOverviewRecords(PDO $db, array $player, array $schedule): ?array {
    $dir=sys_get_temp_dir().'/wesiper-profile-overview-'.(function_exists('posix_geteuid')?posix_geteuid():'web');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))return profileComputeOverviewRecords($db,$player,$schedule);
    // Rolling windows change at KST midnight; successful crawls change the revision.
    $day=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    $key=hash('sha256',json_encode([$player['PlayerId'],$player['Pos'],$player['IsKbodle'],$player['Team']??null,$day,$schedule],JSON_THROW_ON_ERROR));
    $path="$dir/v7-$key.json";
    $read=static function()use($path){if(!is_file($path))return null;$cached=json_decode((string)@file_get_contents($path),true);return is_array($cached)?profileOverviewCacheRead($cached,profileRankingRevision(),time()):null;};
    if(($cached=$read())!==null)return $cached['records'];
    $lock=fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);return profileComputeOverviewRecords($db,$player,$schedule);}
    try {
        if(($cached=$read())!==null)return $cached['records'];
        $revision=profileRankingRevision();
        $records=profileComputeOverviewRecords($db,$player,$schedule);
        // Do not label data computed across a crawl as the new revision.
        if($revision!==profileRankingRevision())return $records;
        $tmp=tempnam($dir,'overview-');
        if($tmp!==false){try{if(file_put_contents($tmp,json_encode(['revision'=>$revision,'createdAt'=>time(),'records'=>$records],JSON_THROW_ON_ERROR))!==false)@rename($tmp,$path);}finally{if(is_file($tmp))unlink($tmp);}}
        return $records;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
