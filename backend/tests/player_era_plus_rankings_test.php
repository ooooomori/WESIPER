<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/player-rankings.php';
if(profileEraPlusValue([2025=>9,2026=>27],2,[2025=>['era'=>6],2026=>['era'=>3]])!==250)throw new RuntimeException('ERA+ weighting mismatch');
if(profileEraPlusValue([2026=>27],0,[2026=>['era'=>3]])!==null)throw new RuntimeException('Zero ERA');
if(profileEraPlusValue([2025=>9],1,[])!==null)throw new RuntimeException('Missing league ERA');
$metrics=[1=>[['ERA+',150]],2=>[['ERA+',150]],3=>[['ERA+',100]],4=>[['ERA+',200]],5=>[['ERA+',null]]];
$ranks=profileMetricRanks($metrics,[1=>true,2=>true,3=>true,4=>false,5=>true],['ERA+']);
if(($ranks[1]['ERA+']??null)!==1||($ranks[2]['ERA+']??null)!==1||($ranks[3]['ERA+']??null)!==3)throw new RuntimeException('ERA+ descending ranks or tie mismatch');
if(isset($ranks[4])||isset($ranks[5]))throw new RuntimeException('Unqualified or missing ERA+ should be excluded');
echo "PASS: ERA+ weighting, missing/zero ERA, descending competition ranks, ties and qualification\n";
