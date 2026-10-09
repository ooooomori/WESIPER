<?php
declare(strict_types=1);
/*
 * 1982~2000년 타자의 시즌별 주 포지션을 kbo_player_season_positions에 넣는다.
 * 이 시기는 경기별 기록이 없어 포지션을 알 수 없으므로, data/player-positions/1982-2000.json(게임 DB에서 옮긴 추정값)을 쓴다.
 * 공식 시즌 합계 표(kbo_player_season_batting_totals)는 수집 스크립트가 다시 쓰는 표라서 열을 더하지 않고 따로 둔다.
 * 사용법: php import-season-positions.php --check|--apply [database-config.php]
 *   --check : 표 유무와 넣을 행 수만 확인(저장 안 함)   --apply : 표를 만들고(없으면) 파일의 연도 범위를 통째로 다시 넣는다.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode='--check';$config=null;
foreach(array_slice($argv,1) as $arg){if(in_array($arg,['--check','--apply'],true))$mode=$arg;else $config=$arg;}
$root=dirname(__DIR__);
$source=json_decode((string)file_get_contents("$root/data/player-positions/1982-2000.json"),true,flags:JSON_THROW_ON_ERROR);
$rows=$source['positions'];
$c=require ($config??(getenv('WESIPER_DB_CONFIG')?:"$root/backend/config/database.php"));
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET SESSION lock_wait_timeout=10');
$t=$db->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='kbo_player_data' AND column_name='player_id'")->fetchColumn();
if(!$t)throw new RuntimeException('kbo_player_data.player_id not found');
$exists=(bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='kbo_player_season_positions'")->fetchColumn();
$years=array_map(static fn($row)=>(int)$row[0],$rows);$from=min($years);$to=max($years);
// 시즌 합계 표에 없는 선수-시즌이 섞이지 않았는지 확인한다.
$known=[];foreach($db->query("SELECT year,player_id FROM kbo_player_season_batting_totals WHERE league_level=1 AND row_scope='total' AND series_id=0 AND year BETWEEN $from AND $to") as $row)$known[$row['year'].'|'.$row['player_id']]=true;
$unknown=array_values(array_filter($rows,static fn($row)=>!isset($known[$row[0].'|'.$row[1]])));
echo json_encode(['mode'=>$mode,'table_exists'=>$exists,'rows'=>count($rows),'years'=>[$from,$to],'not_in_season_totals'=>count($unknown),'unresolved'=>count($source['unresolved']??[])],JSON_UNESCAPED_UNICODE).PHP_EOL;
if($unknown)throw new RuntimeException('Rows without a matching season: '.json_encode(array_slice($unknown,0,5),JSON_UNESCAPED_UNICODE));
if($mode!=='--apply')exit;
if(!$exists){
    $db->exec("CREATE TABLE kbo_player_season_positions (
        year SMALLINT UNSIGNED NOT NULL COMMENT '시즌',
        player_id $t NOT NULL COMMENT '선수',
        position VARCHAR(3) NOT NULL COMMENT '주 포지션 코드(C, 1B, 2B, 3B, SS, LF, CF, RF, DH)',
        source VARCHAR(20) NOT NULL COMMENT '출처(game-db = 게임 DB 시즌 카드에서 옮긴 추정값)',
        matched_by VARCHAR(80) NOT NULL COMMENT '선수를 맞춘 근거',
        source_name VARCHAR(40) NOT NULL COMMENT '출처에 적힌 이름(가명일 수 있다)',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (year, player_id),
        KEY idx_season_positions_player (player_id, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='경기 기록이 없는 시즌(1982~2000)의 타자 주 포지션'");
    echo "created kbo_player_season_positions\n";
}
$insert=$db->prepare("INSERT INTO kbo_player_season_positions (year,player_id,position,source,matched_by,source_name) VALUES (?,?,?,'game-db',?,?)");
$db->beginTransaction();
try{
    $db->exec("DELETE FROM kbo_player_season_positions WHERE source='game-db' AND year BETWEEN $from AND $to");
    foreach($rows as [$year,$id,$position,$name,$gameName,$via])$insert->execute([$year,$id,$position,$via,$gameName]);
    $db->commit();
}catch(Throwable $e){$db->rollBack();throw $e;}
echo 'DONE inserted='.count($rows).PHP_EOL;
