<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__ . '/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$fixture = [];
$fixture['active_ids'] = $db->query('SELECT player_id FROM kbo_player_data WHERE is_kbodle IN (1,2)')->fetchAll(PDO::FETCH_COLUMN);
foreach ([1 => 'generated', 2 => 'excluded', 0 => 'retired'] as $flag => $key) {
    $fixture[$key] = $db->query("SELECT player_id, name, team FROM kbo_player_data WHERE is_kbodle=$flag ORDER BY player_id DESC LIMIT 1")->fetch();
}
$fixture['today'] = $db->query('SELECT PlayerID, PlayerName FROM kbodle_answer WHERE Kbodle_Date=CURDATE() LIMIT 1')->fetch();
$fixture['pick'] = $db->query('SELECT grid_index, row_no, col_no FROM kbobingo_pick WHERE picked>0 ORDER BY grid_index DESC LIMIT 1')->fetch();
$fixture['public_board'] = $db->query('SELECT s.grid_index AS `index`,SHA2(s.user_id,256) AS board_id FROM kbobingo_stat s JOIN kbobingo_grid g ON g.PK=s.grid_index WHERE s.is_public=1 AND JSON_VALID(s.picks) AND JSON_LENGTH(s.picks)=9 LIMIT 1')->fetch();
$fixture['cached_player'] = $db->query("SELECT p.player_id, p.name FROM kbo_player_data p JOIN kbobingo_player_cache c ON c.p_no=p.player_id WHERE c.payload IS NOT NULL AND c.attempted_date=CURDATE() AND JSON_UNQUOTE(JSON_EXTRACT(c.payload,'$.player.Img'))=p.img AND CHAR_LENGTH(p.name)>2 ORDER BY p.player_id DESC LIMIT 1")->fetch();
$fixture['prediction'] = $db->query("SELECT player_id, as_of_date, data_version FROM kbo_player_predictions WHERE season_year=2026 AND season_type='regular' ORDER BY generated_at DESC LIMIT 1")->fetch();
$fixture['roster'] = array_fill_keys(['KIA','SSG','NC','키움','두산','삼성','한화','롯데','LG','KT'],[[],[],[],[]]);
$positions = ['투수'=>0,'포수'=>1,'내야수'=>2,'외야수'=>3];
foreach ($db->query('SELECT name,pos,team FROM kbo_player_data WHERE is_kbodle IN (1,2) ORDER BY name') as $row) {
    if (isset($fixture['roster'][$row['team']],$positions[$row['pos']])) $fixture['roster'][$row['team']][$positions[$row['pos']]][] = $row['name'];
}
// Check joins used by write endpoints without changing picks or scores.
foreach ([
    'SELECT pick.picked, pl.name, pl.img FROM kbobingo_pick pick INNER JOIN kbo_player_data pl ON pick.p_no=pl.player_id WHERE pick.grid_index=0 AND pick.p_no=0 AND pick.row_no=0 AND pick.col_no=0',
    'SELECT name,oldname,player_id,pos,img FROM kbo_player_data WHERE name IN (\'__migration_probe__\') OR oldname IN (\'__migration_probe__\')',
    'SELECT player_id,name FROM kbo_player_data WHERE is_kbodle=1 ORDER BY RAND() LIMIT 1',
] as $sql) $db->query($sql)->fetchAll();
$fixture['lookup_plan'] = $db->query('EXPLAIN SELECT player_id,name FROM kbo_player_data WHERE player_id=52295')->fetchAll();
$fixture['kbodle_age_changes'] = [];
$statePath = __DIR__ . '/player-columns-state.json';
if (is_file($statePath)) {
    $state = json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);
    $backup = '`' . str_replace('`','``',$state['backup']) . '`';
    foreach ($db->query("SELECT p.player_id,p.birth FROM kbo_player_data p JOIN $backup b ON b.id=p.id WHERE p.is_kbodle IN (1,2) AND NOT(BINARY p.birth <=> BINARY b.birth)") as $row) {
        $fixture['kbodle_age_changes'][(string)$row['player_id']] = $row['birth'] ? (new DateTime($row['birth']))->diff(new DateTime())->y : 20;
    }
}
echo json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
