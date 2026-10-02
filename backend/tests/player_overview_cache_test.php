<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-overview-cache.php';
$cache=['revision'=>'a','createdAt'=>1000,'records'=>['year'=>2026,'stats'=>[['안타',100]]]];
if(profileOverviewCacheRead($cache,'a',1299)!==['records'=>$cache['records']])throw new RuntimeException('Fresh cache mismatch');
if(profileOverviewCacheRead($cache,'a',1300)!==null)throw new RuntimeException('Cache expiration mismatch');
if(profileOverviewCacheRead($cache,'b',1001)!==null)throw new RuntimeException('Crawl revision mismatch');
if(profileOverviewCacheRead($cache,'a',999)!==null)throw new RuntimeException('Clock rollback mismatch');
$cache['records']=null;if(profileOverviewCacheRead($cache,'a',1001)!==['records'=>null])throw new RuntimeException('Empty player cache mismatch');
unset($cache['records']);if(profileOverviewCacheRead($cache,'a',1001)!==null)throw new RuntimeException('Invalid cache mismatch');
echo "PASS: overview cache hits, expiry, crawler revision, clock rollback, null and malformed records\n";
