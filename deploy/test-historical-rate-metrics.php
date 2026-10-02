<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$root=$argv[1]??'/home/bitnami/wesiper-season-api-release-20261001';
require '/opt/bitnami/apache/htdocs/api/kbocandle/common.php';
require $root.'/lib/player-year-records.php';require $root.'/lib/player-season-schedule.php';
function assertRate(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$q=$pdo->query("SELECT * FROM kbo_player_season_batting_totals WHERE league_level=1 AND row_scope='total' AND series_id=0 ORDER BY year,player_id");$checked=0;
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
    assertRate(isset($row['sf'],$row['sh'],$row['tb']), 'missing official supplement');
    $s=profileSeasonStats([$row],false);
    foreach(['obp','slg','ops'] as $field)assertRate($s[$field]===$row[$field], 'official annual rate mismatch '.$row['year'].' '.$row['player_id'].' '.$field);
    $original=json_decode($row['raw_record'],true,512,JSON_THROW_ON_ERROR);
    assertRate(isset($original['_rate_supplement'][0]['source_sha256']), 'supplement provenance missing');$checked++;
}
assertRate($checked===3648,'wrong supplement coverage');
$q=$pdo->query("SELECT * FROM kbo_player_season_pitching_totals WHERE league_level=1 AND row_scope='total' AND series_id=0 AND year=1982");$leagueRows=$q->fetchAll(PDO::FETCH_ASSOC);
$contexts=profileHistoricalPitchingContexts($pdo,'regular');$aggregate=profileSeasonStats($leagueRows,true,$contexts);
$expectedEra=number_format(array_sum(array_column($leagueRows,'er'))*27/array_sum(array_column($leagueRows,'innings_outs')),2,'.','');
assertRate($aggregate['era']===$expectedEra&&$aggregate['fip']===$expectedEra&&$aggregate['eraPlus']===100,'league FIP normalization or ERA+ baseline wrong');
foreach([95576,70122,70121,10082] as $pid){$pitcher=in_array($pid,[70121,10082],true);$data=profileYearRecords($pdo,(string)$pid,$pitcher,profileSchedule());$career=$data['career'];
    if($pitcher){
        $outs=array_sum(array_map(static fn($r)=>profileInningOuts($r['stats']['innings']),$data['rows']));
        $weights=array_column(array_column($data['rows'],'stats'),'eraWeighted');$constants=array_column(array_column($data['rows'],'stats'),'fipConstantWeighted');
        if(!in_array(null,$weights,true)&&$career['er']>0)assertRate($career['eraPlus']===(int)round(100*array_sum($weights)/($career['er']*27)),'career ERA+ is not innings weighted');
        if(!in_array(null,$constants,true)&&isset($career['hr'],$career['bb'],$career['hbp'],$career['so'])&&$outs>0){$expected=number_format((13*$career['hr']+3*($career['bb']+$career['hbp'])-2*$career['so'])*3/$outs+array_sum($constants)/$outs,2,'.','');assertRate($career['fip']===$expected,'career FIP not innings weighted');}
        foreach($data['rows'] as $row)if($row['year']<=2000&&profileInningOuts($row['stats']['innings'])>0)assertRate($row['stats']['fip']!==null,'historical FIP missing');
    }else{
        $s=$career;$den=$s['ab']+$s['bb']+$s['hbp']+$s['sf'];$obp=($s['h']+$s['bb']+$s['hbp'])/$den;$slg=$s['tb']/$s['ab'];
        assertRate($s['obp']===number_format($obp,3,'.','')&&$s['slg']===number_format($slg,3,'.','')&&$s['ops']===number_format($obp+$slg,3,'.',''),'career batting rate not recomputed from counts');
    }
    echo json_encode(['pid'=>$pid,'pitcher'=>$pitcher,'career'=>array_intersect_key($career,array_flip(['obp','slg','ops','era','fip','eraPlus']))],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
echo "PASS: $checked official annual batting rates; pitching normalization and mixed-career rates\n";
