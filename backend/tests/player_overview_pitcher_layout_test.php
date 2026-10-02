<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-season-totals.php';
$stats=profileSeasonDisplayStats(['era'=>'2.50','wins'=>100,'holds'=>5,'saves'=>20,'games'=>500,'innings'=>'1200','so'=>1000,'whip'=>'1.20','eraPlus'=>150],true);
if(array_column($stats,0)!==['ERA','승리','홀드','세이브','이닝','삼진','WHIP','ERA+'])throw new RuntimeException('Retired pitcher overview order mismatch');
if(array_column(array_slice($stats,4),1)!==['1200',1000,'1.20',150])throw new RuntimeException('Retired pitcher overview values mismatch');
$stats=profileSeasonDisplayStats([],true);
if(count($stats)!==8||$stats[7]!==['ERA+',null])throw new RuntimeException('Missing ERA+ should retain its slot');
echo "PASS: retired/historical pitcher overview labels, order, values and missing ERA+ slot\n";
