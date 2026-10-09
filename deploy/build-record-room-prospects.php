<?php
declare(strict_types=1);
/*
 * 기록실 '유망주만' 필터용 선수 ID 목록을 만든다.
 * data/player-prospects/<season>.json(팀·포지션별 이름)을 kbo_player_data와 맞춰 backend/lib/record-room-prospects.php로 쓴다.
 * 사용법: php build-record-room-prospects.php [--season=2026] [--check] [database-config.php]
 *   --check : 맞춘 결과만 출력하고 파일은 쓰지 않는다.
 * 동명이인은 팀 → 포지션 → 가장 어린 선수 순으로 고르고, 못 찾았거나 애매한 이름은 출력한다.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$season=2026;$check=false;$config=null;
foreach(array_slice($argv,1) as $arg){
    if($arg==='--check')$check=true;
    elseif(preg_match('/^--season=(\d{4})$/',$arg,$m))$season=(int)$m[1];
    else $config=$arg;
}
$root=dirname(__DIR__);
$source=json_decode((string)file_get_contents("$root/data/player-prospects/$season.json"),true,flags:JSON_THROW_ON_ERROR);
$c=require ($config??(getenv('WESIPER_DB_CONFIG')?:"$root/backend/config/database.php"));
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$byName=[];
foreach($db->query("SELECT player_id,name,team,pos,mainPos,birth,is_kbodle FROM kbo_player_data WHERE COALESCE(pos,'')<>'코치'") as $row)$byName[trim((string)$row['name'])][]=$row;
// 고양은 키움의 퓨처스 팀 이름이다.
$franchise=static fn($team)=>match(strtoupper(trim((string)$team))){'SK'=>'SSG','넥센','히어로즈','고양'=>'키움',default=>strtoupper(trim((string)$team))};
$pitcher=static fn(array $row)=>str_contains((string)$row['pos'],'투수');
$exactGroup=static fn(array $row)=>$pitcher($row)?'투수':(str_contains((string)$row['pos'].$row['mainPos'],'포수')?'포수':(str_contains((string)$row['pos'].$row['mainPos'],'외야')||preg_match('/익수|중견수/u',(string)$row['mainPos'])?'외야수':'내야수'));
$ids=[];$missing=[];$ambiguous=[];$otherTeam=[];$pending=[];
$pick=static function(array $candidates,string $team,string $position,string $name,bool $anyPosition=false)use(&$ids,&$ambiguous,$season,$pitcher,$exactGroup): ?array {
    // 이미 다른 줄에 배정한 선수(같은 이름이 두 번 나오는 경우 포함)와 서른을 넘긴 동명이인은 뺀다.
    $candidates=array_values(array_filter($candidates,static fn($row)=>!isset($ids[(int)$row['player_id']])&&(int)substr((string)$row['birth'],0,4)>=$season-30));
    $samePosition=array_values(array_filter($candidates,static fn($row)=>$pitcher($row)===($position==='투수')));
    if(!$samePosition&&$anyPosition)$samePosition=$candidates;
    if(!$samePosition)return null;
    $exact=array_values(array_filter($samePosition,static fn($row)=>$exactGroup($row)===$position));
    $candidates=$exact?:$samePosition;
    usort($candidates,static fn($a,$b)=>strcmp((string)$b['birth'],(string)$a['birth']));
    if(count($candidates)>1)$ambiguous[]="$team $position $name → ".implode(', ',array_map(static fn($row)=>$row['player_id'].'('.$row['team'].' '.$row['pos'].' '.$row['birth'].')',$candidates));
    $ids[(int)$candidates[0]['player_id']]=true;
    return $candidates[0];
};
// 1) 명단의 팀에 있는 선수부터 맞춘다.
foreach($source['teams'] as $team=>$positions)foreach($positions as $position=>$names)foreach($names as $name){
    $candidates=array_values(array_filter($byName[$name]??[],static fn($row)=>(string)$row['is_kbodle']!=='0'&&$franchise($row['team'])===strtoupper($team)));
    if(!$pick($candidates,$team,$position,$name))$pending[]=[$team,$position,$name];
}
// 2) 같은 팀에 그 이름이 있는데 포지션만 다르게 적힌 경우(투타 전향 등)
$stillPending=[];
foreach($pending as [$team,$position,$name]){
    $candidates=array_values(array_filter($byName[$name]??[],static fn($row)=>(string)$row['is_kbodle']!=='0'&&$franchise($row['team'])===strtoupper($team)));
    if(!$pick($candidates,$team,$position,$name,true))$stillPending[]=[$team,$position,$name];
}
$pending=$stillPending;
// 3) 남은 이름은 팀이 달라도(이적·상무·소속 미기재) 포지션이 맞고 아직 배정되지 않은 선수가 있으면 맞춘다.
foreach($pending as [$team,$position,$name]){
    $candidates=$byName[$name]??[];
    $active=array_values(array_filter($candidates,static fn($row)=>(string)$row['is_kbodle']!=='0'));
    $row=$pick($active?:$candidates,$team,$position,$name);
    if($row)$otherTeam[]="$team $position $name → ".$row['player_id'].'('.$row['team'].' '.$row['pos'].' '.$row['birth'].')';
    else $missing[]="$team $position $name";
}
$ids=array_keys($ids);sort($ids);
printf("season %d: %d matched\n",$season,count($ids));
foreach(['missing'=>$missing,'ambiguous (picked the youngest)'=>$ambiguous,'matched on another team'=>$otherTeam] as $label=>$list){printf("%s: %d\n",$label,count($list));foreach($list as $line)echo "  $line\n";}
if($check)exit;
$out="<?php\n// deploy/build-record-room-prospects.php가 data/player-prospects/$season.json에서 만든 파일. 직접 고치지 않는다.\nreturn ".var_export(['season'=>$season,'source'=>$source['source'],'title'=>$source['title'],'revised'=>$source['revised'],'ids'=>$ids],true).";\n";
file_put_contents("$root/backend/lib/record-room-prospects.php",$out);
echo "wrote backend/lib/record-room-prospects.php\n";
