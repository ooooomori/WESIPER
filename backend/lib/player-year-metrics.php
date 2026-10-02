<?php
declare(strict_types=1);
require_once __DIR__.'/player-records.php';

function profileAgeOnJulyFirst(?string $birth, int $year): ?int {
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',trim($birth??''),$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;
    $age=$year-(int)$m[1]-((sprintf('%02d-%02d',(int)$m[2],(int)$m[3])>'07-01')?1:0);
    return $age>=0?$age:null;
}

function profileMetricRatio(?float $numerator, ?float $denominator, int $precision=3): ?string {
    return $numerator!==null&&$denominator!==null&&$denominator>0?number_format($numerator/$denominator,$precision,'.',''):null;
}

function profileAdvancedBatEvent(array $row): array {
    $event=profileBatEvent($row);$text=trim((string)($row['pa_result']??''));
    $event['so']=(int)(str_contains($text,'삼진')||str_contains($text,'스낫'));
    $event['ibb']=(int)(in_array($text,['고4','고의4구','고의사구','고의볼넷'],true)||str_contains($text,'고의4구')||str_contains($text,'고의볼넷'));
    if($event['ibb']){$event['bb']=1;$event['hbp']=0;$event['ab']=0;}
    $event['sh']=(int)(!$event['sf']&&(str_contains($text,'희번')||str_contains($text,'희타')||str_contains($text,'희실')));
    $event['roe']=(int)str_ends_with($text,'실');
    $event['go']=(int)(str_ends_with($text,'땅')||str_ends_with($text,'병'));
    $event['fo']=(int)(str_ends_with($text,'비')||str_ends_with($text,'파'));
    $event['runOut']=isset($row['run_out'])?(int)$row['run_out']:null;
    foreach(['sb','cs'] as $key)$event[$key]=isset($row[$key])?(int)$row[$key]:null;
    return $event;
}

function profileSpeedScore(array $s): ?string {
    // User-selected five-factor variant: omit fielding F6 and average F1..F5.
    $singles=$s['h']-$s['doubles']-$s['triples']-$s['hr'];
    $onBase=$singles+$s['bb']+$s['hbp'];$balls=$s['ab']-$s['hr']-$s['so'];
    $runDen=$s['h']+$s['bb']+$s['hbp']-$s['hr'];
    if($s['r']===null||$s['sb']===null||$s['cs']===null||$s['gdp']===null||$onBase<=0||$balls<=0||$runDen<=0)return null;
    $factors=[20*(($s['sb']+3)/($s['sb']+$s['cs']+7)-.4),sqrt(($s['sb']+$s['cs'])/$onBase)/.07,
        625*$s['triples']/$balls,25*(($s['r']-$s['hr'])/$runDen-.1),(.063-$s['gdp']/$balls)/.007];
    return number_format(array_sum(array_map(static fn($v)=>max(0,min(10,$v)),$factors))/5,1,'.','');
}

function profileBatterAdvancedStats(array $parsed, array $s): array {
    foreach(['so','ibb','sh','roe','go','fo','runOut'] as $key)$s[$key]=profileSum($parsed,$key);
    $s['isoObp']=$s['isoSlg']=null;
    // Rates use unrounded counts; iso rates must not subtract rounded displays.
    if($s['ab']>0){$den=$s['ab']+$s['bb']+$s['hbp']+$s['sf'];$s['isoObp']=$den>0?number_format(($s['h']+$s['bb']+$s['hbp'])/$den-$s['h']/$s['ab'],3,'.',''):null;$s['isoSlg']=number_format(($s['tb']-$s['h'])/$s['ab'],3,'.','');}
    $s['babip']=profileMetricRatio($s['h']-$s['hr'],$s['ab']-$s['so']-$s['hr']+$s['sf']);
    $s['groundFly']=profileMetricRatio($s['go'],$s['fo'],2);
    $s['bbPct']=profileMetricRatio($s['bb']*100,$s['pa'],1);
    $s['kPct']=profileMetricRatio($s['so']*100,$s['pa'],1);
    $s['bbK']=profileMetricRatio($s['bb'],$s['so'],2);
    $s['sbAttempts']=$s['sb']!==null&&$s['cs']!==null?$s['sb']+$s['cs']:null;
    $s['sbPct']=profileMetricRatio($s['sb']===null?null:$s['sb']*100,$s['sbAttempts'],1);
    $s['sbSecond']=$s['sbThird']=$s['sbHome']=null; // Destination bases were not stored.
    // User-specified weights, including running value and reached-on-error.
    $singles=$s['h']-$s['doubles']-$s['triples']-$s['hr'];
    $numerator=$s['sb']!==null&&$s['cs']!==null?.7*($s['bb']-$s['ibb']+$s['hbp'])+.9*($singles+($s['roe']??0))+1.25*$s['doubles']+1.6*$s['triples']+2*$s['hr']+.25*$s['sb']-.5*$s['cs']:null;
    $s['woba']=profileMetricRatio($numerator,$s['pa']-$s['ibb']-$s['sh']);
    $s['spd']=profileSpeedScore($s);
    if($s['sb']===null||$s['cs']===null)$s['effectiveOps']=null;
    return $s;
}

function profilePitcherAdvancedStats(array $rows, array $s, array $events, bool $known, array $leagueYears, array $context): array {
    $outs=0;$startOuts=0;$reliefOuts=0;$finishes=0;$qs=0;$qsPlus=0;$ds=0;$complete=0;$shutouts=0;
    $rolesKnown=$finishKnown=$completeKnown=$shutoutKnown=$qsKnown=$qsPlusKnown=$dsKnown=true;
    $weightedEra=0;$weightedConstant=0;$eraKnown=$constantKnown=true;
    foreach($rows as $row){
        $ip=profileInningOuts($row['inning']);$outs+=$ip;$order=isset($row['order'])?(int)$row['order']:null;$start=$order===1;
        if($order===null)$rolesKnown=$finishKnown=$completeKnown=$shutoutKnown=$qsKnown=$qsPlusKnown=$dsKnown=false;
        elseif($start)$startOuts+=$ip;else $reliefOuts+=$ip;
        $meta=$context[$row['game_id'].'|'.profileTeam($row['team'])]??null;
        if(!$start){if(!$meta||!isset($meta['last_order']))$finishKnown=false;elseif($order===(int)$meta['last_order'])$finishes++;}
        if($start){
            foreach([18=>['qs',3],21=>['qsPlus',3],24=>['ds',1]] as $threshold=>[$key,$maxEr]){
                if($ip<$threshold)continue;
                if(!isset($row['er'])){$flag=$key.'Known';$$flag=false;}
                elseif((int)$row['er']<=$maxEr)$$key++;
            }
            if(!$meta||!isset($meta['pitchers'])||!array_key_exists('completed',$meta))$completeKnown=$shutoutKnown=false;
            elseif((int)$meta['pitchers']===1&&$meta['completed']){$complete++;if(!isset($row['r']))$shutoutKnown=false;elseif((int)$row['r']===0)$shutouts++;}
        }
        $year=(int)substr($row['game_date'],0,4);$league=$leagueYears[$year]??null;
        if($ip>0){if(!isset($league['era']))$eraKnown=false;else $weightedEra+=$ip*$league['era'];if(!isset($league['fipConstant']))$constantKnown=false;else $weightedConstant+=$ip*$league['fipConstant'];}
    }
    $s['reliefs']=$rolesKnown?$s['games']-$s['starts']:null;$s['finishes']=$finishKnown?$finishes:null;
    $s['starterInnings']=$rolesKnown?profileInningText($startOuts):null;$s['reliefInnings']=$rolesKnown?profileInningText($reliefOuts):null;
    $s['pitches']=profileSum($rows,'pitched');$s['pitchesPerInning']=profileMetricRatio($s['pitches']===null?null:$s['pitches']*3,$outs,2);$s['pitchesPerGame']=profileMetricRatio($s['pitches'],$s['games'],2);
    foreach(['qs','qsPlus','ds','complete','shutouts'] as $key){$flag=($key==='shutouts'?'shutout':$key).'Known';$s[$key]=$$flag?$$key:null;}
    $s['k9']=profileMetricRatio($known?$s['so']*27:null,$outs,2);$s['bb9']=profileMetricRatio($known?$s['bb']*27:null,$outs,2);
    $s['h9']=profileMetricRatio($known?$s['h']*27:null,$outs,2);$s['hr9']=profileMetricRatio($known?$s['hr']*27:null,$outs,2);
    $totals=[];foreach(['pa','ab','h','hr','bb','hbp','sf','so','tb','go','fo'] as $key)$totals[$key]=$known?profileSum($events,$key):null;
    $s['kPct']=profileMetricRatio($known?$totals['so']*100:null,$totals['pa'],1);$s['bbPct']=profileMetricRatio($known?$totals['bb']*100:null,$totals['pa'],1);
    $s['kBb']=profileMetricRatio($totals['so'],$totals['bb'],2);$s['opponentAvg']=profileMetricRatio($totals['h'],$totals['ab']);
    $s['opponentObp']=$known?profileMetricRatio($totals['h']+$totals['bb']+$totals['hbp'],$totals['ab']+$totals['bb']+$totals['hbp']+$totals['sf']):null;
    $s['opponentSlg']=profileMetricRatio($totals['tb'],$totals['ab']);
    $obpDen=($totals['ab']??0)+($totals['bb']??0)+($totals['hbp']??0)+($totals['sf']??0);
    $s['opponentOps']=$known&&$obpDen>0&&$totals['ab']>0?number_format(($totals['h']+$totals['bb']+$totals['hbp'])/$obpDen+$totals['tb']/$totals['ab'],3,'.',''):null;
    $s['groundFly']=profileMetricRatio($totals['go'],$totals['fo'],2);
    $s['babip']=$known?profileMetricRatio($totals['h']-$totals['hr'],$totals['ab']-$totals['so']-$totals['hr']+$totals['sf']):null;
    $s['fip']=$known&&$constantKnown&&$outs>0?number_format((13*$s['hr']+3*($s['bb']+$s['hbp'])-2*$s['so'])*3/$outs+$weightedConstant/$outs,2,'.',''):null;
    // League-adjusted ERA; park factors are not available in this database.
    $s['eraPlus']=$eraKnown&&$outs>0&&$s['er']!==null&&$s['er']>0?(int)round(100*$weightedEra/($s['er']*27)):null;
    // Preserve exact weighting inputs when merging historical season totals.
    $s['eraWeighted']=$eraKnown?$weightedEra:null;
    $s['fipConstantWeighted']=$constantKnown?$weightedConstant:null;
    return $s;
}
