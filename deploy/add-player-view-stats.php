<?php
declare(strict_types=1);
// 인기 선수 집계 테이블 생성. 사용법: php deploy/add-player-view-stats.php --check|--apply <database-config.php>
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true))throw new InvalidArgumentException('Invalid mode');
$c=require ($argv[2]??dirname(__DIR__).'/backend/config/database.php');
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET SESSION lock_wait_timeout=10');
// 기존 선수 테이블과 같은 타입을 써야 JOIN 시 형변환 없이 인덱스를 탄다.
$t=$db->query("SELECT COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='kbo_player_data' AND column_name='player_id'")->fetch(PDO::FETCH_ASSOC);
if(!$t)throw new RuntimeException('kbo_player_data.player_id not found');
$pidType=$t['COLUMN_TYPE'].($t['COLLATION_NAME']?' CHARACTER SET '.$t['CHARACTER_SET_NAME'].' COLLATE '.$t['COLLATION_NAME']:'');
echo json_encode(['player_id_type'=>$pidType]).PHP_EOL;
$tables=[
    // 날짜별 선수 조회수 (방문자 중복 제거 후)
    'kbo_player_views_daily'=>"CREATE TABLE kbo_player_views_daily (
        view_date DATE NOT NULL,
        player_id $pidType NOT NULL,
        views INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (view_date, player_id),
        KEY idx_player_date (player_id, view_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // 같은 날 같은 방문자의 중복 조회 차단용. 원본 IP 대신 하루 단위 해시만 저장하고 이틀 뒤 지운다.
    'kbo_player_view_visitors'=>"CREATE TABLE kbo_player_view_visitors (
        view_date DATE NOT NULL,
        player_id $pidType NOT NULL,
        visitor_hash BINARY(32) NOT NULL,
        PRIMARY KEY (view_date, player_id, visitor_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach($tables as $table=>$ddl){
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);
    $present=(bool)$q->fetchColumn();
    if(!$present&&$mode==='--apply'){$db->exec($ddl);$present=true;}
    echo json_encode(['table'=>$table,'present'=>$present,'mode'=>$mode]).PHP_EOL;
}
