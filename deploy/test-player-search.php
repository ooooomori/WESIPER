<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../backend/lib/player-search.php';
$config = require ($argv[1] ?? __DIR__ . '/../backend/config/database.php');
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
// Temporary tables shadow the real tables only on this connection; no live rows are changed.
$db->exec('CREATE TEMPORARY TABLE kbo_player_data (player_id INT, name VARCHAR(100), fullname VARCHAR(100), oldname VARCHAR(100), img VARCHAR(100), pos VARCHAR(50), mainPos VARCHAR(50), draft VARCHAR(100), retire INT, team VARCHAR(50), backNo VARCHAR(10), is_kbodle INT DEFAULT 0, is_number_retired INT DEFAULT 0) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$db->exec('CREATE TEMPORARY TABLE kbo_player_nicknames (player_id INT, nickname VARCHAR(100)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
foreach (['kbo_season_records','kbo_season_pitch_records'] as $table) $db->exec("CREATE TEMPORARY TABLE `$table` (player_id INT,game_id VARCHAR(30),game_date DATE,league_level INT)");
foreach (['kbo_player_season_batting_totals','kbo_player_season_pitching_totals'] as $table) $db->exec("CREATE TEMPORARY TABLE `$table` (player_id INT,year INT,games INT,league_level INT,row_scope VARCHAR(10),series_id INT)");
$db->exec("INSERT INTO kbo_season_records VALUES (1,'20250401LGOB0-a','2025-04-01',1),(1,'20250401LGOB0-b','2025-04-01',1),(1,'20251010LGOB0','2025-10-10',1),(1,'20260401LGOB0','2026-04-01',2)");
$db->exec("INSERT INTO kbo_season_pitch_records VALUES (1,'20250401LGOB0','2025-04-01',1)");
foreach (['kbo_season_records','kbo_season_pitch_records'] as $table) $db->exec("ALTER TABLE `$table` ADD team VARCHAR(10) DEFAULT 'LG'");
$db->exec("UPDATE kbo_season_records SET team='SS' WHERE game_date='2025-10-10'");
$db->exec("UPDATE kbo_season_records SET team='NC' WHERE league_level=2");
$db->exec("INSERT INTO kbo_player_season_batting_totals VALUES (1,1999,6,1,'total',0),(1,1999,500,1,'team',0),(1,2000,500,2,'total',0),(1,2000,500,1,'total',3)");
$db->exec("INSERT INTO kbo_player_season_pitching_totals VALUES (1,1999,8,1,'total',0)");
$insert = $db->prepare('INSERT INTO kbo_player_data (player_id,name,fullname,oldname) VALUES (?,?,?,?)');
foreach ([[1,'앞순위뒤',null,null],[2,'가','순위',null],[3,'나',null,'순위'],[4,'다',null,null],[5,'라',null,null],[6,'순위',null,null]] as $row) $insert->execute($row);
$insert = $db->prepare('INSERT INTO kbo_player_nicknames VALUES (?,?)');
foreach ([[4,'순위 하나'],[4,'순위 둘'],[5,'순위 하나'],[4,'KK'],[5,'100%'],[5,'A_B'],[4,'챗 지피티'],[5,'챗 지피티'],[4,"탭\t별명"]] as $row) $insert->execute($row);
foreach (['순위'=>[6,1,2,3,4,5],'kk'=>[4],'%'=>[5],'_'=>[5],''=>[],'없는선수'=>[],'챗지피티'=>[4,5],'챗 지 피 티'=>[4,5],"챗\t지 피티"=>[4,5],'탭별명'=>[4]] as $keyword=>$expected) {
    $actual = array_map('intval', array_column(searchKboPlayers($db,$keyword,false), 'player_id'));
    if ($actual !== $expected) throw new RuntimeException('Search regression for ' . $keyword . ': ' . json_encode($actual));
}
$stats=searchPlayerRecordStats($db,[1,6],false);
if ($stats[1]!==['games'=>9,'last_year'=>2026,'futures_games'=>0] || $stats[6]!==['games'=>0,'last_year'=>null,'futures_games'=>0]) throw new RuntimeException('Game count or all-league last-year mismatch');
if (searchPlayerLastTeams($db,[1,6])!==[1=>'NC']) throw new RuntimeException('Latest futures team lookup mismatch');
$insert=$db->prepare('INSERT INTO kbo_player_data (player_id,name) VALUES (?,?)');
foreach ([[7,'순위'],[8,'순위'],[9,'순위긴이름'],[10,'순위가']] as $row) $insert->execute($row);
$db->exec("INSERT INTO kbo_player_season_batting_totals VALUES (7,1999,20,1,'total',0),(8,1999,5,1,'total',0),(9,1999,100,1,'total',0),(10,1999,10,1,'total',0)");
$actual=array_map('intval',array_column(searchKboPlayers($db,'순위',false),'player_id'));
if ($actual!==[7,8,6,9,10,1,2,3,4,5]) throw new RuntimeException('Name priority / descending games mismatch: '.json_encode($actual));
$db->exec("INSERT INTO kbo_season_pitch_records VALUES (2,'20250801LGOB0','2025-08-01',2,'KT'),(3,'20250401LGOB0','2025-04-01',2,'NC'),(3,'20250901LGOB0','2025-09-01',1,'LG'),(4,'20250901LGOB0','2025-09-01',2,''),(4,'20250801LGOB0','2025-08-01',1,'SS')");
$teams=searchPlayerLastTeams($db,[2,3,4,6]);
if ($teams!==[2=>'KT',3=>'LG',4=>'SS']) throw new RuntimeException('Futures-only / newest first-team / empty team fallback mismatch');
$insert=$db->prepare('INSERT INTO kbo_player_data (player_id,name) VALUES (?,?)');
foreach ([[11,'순위'],[12,'순위']] as $row) $insert->execute($row);
$bat=$db->prepare('INSERT INTO kbo_season_records VALUES (?,?,?,?,?)');
$pitch=$db->prepare('INSERT INTO kbo_season_pitch_records VALUES (?,?,?,?,?)');
for ($day=1;$day<=10;$day++) {
    $game=sprintf('202508%02dLGOB0',$day); $date=sprintf('2025-08-%02d',$day);
    $bat->execute([11,$game.'-a',$date,2,'LG']); $bat->execute([11,$game.'-b',$date,2,'LG']);
    $pitch->execute([11,$game,$date,2,'LG']);
    if ($day<=2) $pitch->execute([12,$game,$date,2,'LG']);
}
$stats=searchPlayerRecordStats($db,[11,12],false);
if ($stats[11]['games']!==0 || $stats[11]['futures_games']!==10 || $stats[12]['futures_games']!==2) throw new RuntimeException('Distinct futures game count mismatch');
$actual=array_map('intval',array_column(searchKboPlayers($db,'순위',false),'player_id'));
if ($actual!==[7,8,11,12,6,9,10,1,2,3,4,5]) throw new RuntimeException('Futures fallback / first-team priority / name relevance mismatch: '.json_encode($actual));
echo "PASS: name relevance, first-team games then futures-only fallback, distinct games across plate appearances/roles, historical total deduplication, excluded seasons, all-league last year, recent teams, nicknames and literal wildcards\n";
