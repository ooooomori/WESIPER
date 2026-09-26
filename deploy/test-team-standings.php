<?php
require $argv[1];
$games = [];
foreach ([[2,1], [1,1], [0,1], [2,0], [3,1], [null,null]] as [$away, $home]) {
    $games[] = ['away_team'=>'LG', 'home_team'=>'KT', 'away_score'=>$away, 'home_score'=>$home];
}
$rows = calculateStandings($games);
$byTeam = [];
foreach ($rows as $row) { $values = array_column($row['row'], 'Text'); $byTeam[$values[1]] = $values; }
if (array_slice($byTeam['LG'], 2, 8) !== ['5','3','1','1','0.750','-','🔥 2','승무패승승']) throw new RuntimeException('LG mismatch');
if ($byTeam['KT'][8] !== '❄️ 2' || $byTeam['KT'][7] !== '2.0') throw new RuntimeException('KT mismatch');
$draw = calculateStandings(array_slice($games, 0, 2));
if ($draw[0]['row'][8]['Text'] !== '🔥 1') throw new RuntimeException('Draw streak mismatch');
echo "PASS: wins, losses, draws, rate, gap, streak, recent five, scheduled exclusion\n";

function teamFixture($name, $wins, $losses, $draws) {
    return ['team'=>$name, 'wins'=>$wins, 'losses'=>$losses, 'draws'=>$draws];
}
$a = teamFixture('A', 80, 60, 2);
$b = teamFixture('B', 79, 61, 2);
if (pairMagic($a, $b) !== 2) throw new RuntimeException('Tied finish must remain unsafe');
$a = teamFixture('A', 80, 59, 3);
$b = teamFixture('B', 78, 62, 2);
$number = pairMagic($a, $b);
for ($w = 0; $w <= seasonRemaining($a); $w++) {
    for ($l = 0; $l <= seasonRemaining($b); $l++) {
        if ($w + $l < $number) continue;
        for ($drawA=0; $drawA<=seasonRemaining($a)-$w; $drawA++) {
            for ($drawB=0; $drawB<=seasonRemaining($b)-$l; $drawB++) {
                $wa = $a['wins']+$w;
                $wb = $b['wins']+seasonRemaining($b)-$l-$drawB;
                if ($wa*(144-$b['draws']-$drawB) <= $wb*(144-$a['draws']-$drawA)) {
                    throw new RuntimeException('Future draws invalidate guarantee');
                }
            }
        }
    }
}
$finished = [];
for ($i=0; $i<10; $i++) $finished[] = teamFixture((string)$i, 90-$i*4, 54+$i*4, 0);
if (autumnNumbers($finished[0],$finished) !== [0,0,0,'X','1위 확보']) throw new RuntimeException('Clinched first');
if (autumnNumbers($finished[1],$finished)[4] !== '2위 확보') throw new RuntimeException('Clinched second');
if (autumnNumbers($finished[9],$finished) !== [0,'X','X',0,'가을야구 불가']) throw new RuntimeException('Eliminated');
echo "PASS: magic numbers, future draws, strict ties, clinched and eliminated\n";
$race = [];
for ($i=0; $i<10; $i++) $race[] = $i < 3 ? teamFixture((string)$i,100,44,0) : teamFixture((string)$i,60,60,0);
$race[3] = teamFixture('3',70,60,0);
if (autumnNumbers($race[3],$race)[4] !== '3위 불가') throw new RuntimeException('Best possible fourth');
$race[0] = teamFixture('0',84,60,0);
if (autumnNumbers($race[3],$race)[4] !== '2위 불가') throw new RuntimeException('Tied ceiling remains possible');
echo "PASS: unreachable rank and tied ceiling\n";
