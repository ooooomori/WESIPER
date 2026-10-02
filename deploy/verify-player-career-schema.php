<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__.'/../backend/config/database.php';
$db = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$columns = $db->query('SHOW COLUMNS FROM kbo_player_data')->fetchAll(PDO::FETCH_COLUMN);
foreach (['is_WBC','is_GG','is_AS'] as $old) if (in_array($old, $columns, true)) throw new RuntimeException('Old physical column remains: '.$old);
foreach (['fullname','is_foreign','player_id'] as $new) if (!in_array($new, $columns, true)) throw new RuntimeException('Missing column: '.$new);
if ($db->query('SHOW COLUMNS FROM kbo_player_career')->fetchAll(PDO::FETCH_COLUMN) !== ['PK','player_id','category','type','team','country','year','month','pos','note']) throw new RuntimeException('Career columns differ');
$checks = [
    'invalid_country' => "SELECT COUNT(*) FROM kbo_player_career WHERE (category='national' AND (country IS NULL OR TRIM(country)='')) OR (category<>'national' AND country IS NOT NULL)",
    'invalid_foreign' => 'SELECT COUNT(*) FROM kbo_player_data WHERE is_foreign IS NOT NULL AND is_foreign<>1',
    'foreign_oldname_remaining' => "SELECT COUNT(*) FROM kbo_player_data WHERE is_foreign=1 AND oldname IS NOT NULL AND TRIM(oldname)<>''",
    'missing_player' => 'SELECT COUNT(*) FROM kbo_player_career c LEFT JOIN kbo_player_data p ON p.player_id=c.player_id WHERE p.player_id IS NULL',
    'invalid_month' => "SELECT COUNT(*) FROM kbo_player_career WHERE (type='월간 MVP' AND (month IS NULL OR month NOT BETWEEN 1 AND 12)) OR (type<>'월간 MVP' AND month IS NOT NULL)",
    'invalid_position' => "SELECT COUNT(*) FROM kbo_player_career WHERE (type IN ('골든글러브','수비상') AND pos IS NULL) OR (type NOT IN ('골든글러브','수비상') AND pos IS NOT NULL)",
    'invalid_note' => "SELECT COUNT(*) FROM kbo_player_career WHERE note IS NOT NULL AND NOT(category='award' AND type='올스타' AND note='MVP')",
    'invalid_national' => "SELECT COUNT(*) FROM kbo_player_career WHERE category='national' AND (type<>'WBC' OR team IS NOT NULL OR month IS NOT NULL OR pos IS NOT NULL OR note IS NOT NULL)",
];
foreach ($checks as $name=>$sql) if ((int)$db->query($sql)->fetchColumn() !== 0) throw new RuntimeException('Failed: '.$name);
$legacy = $db->query('SELECT COUNT(*) total,SUM(is_WBC=1) wbc FROM kbo_playerlist_20250613')->fetch();
if ((int)$legacy['total']!==6002 || (int)$legacy['wbc']!==159) throw new RuntimeException('Legacy view compatibility failed');
if (is_file('/opt/bitnami/apache/htdocs/api/kbobingo/search_test.php')) throw new RuntimeException('Deleted search_test still exists');
echo json_encode(['schema'=>'PASS','field_rules'=>'PASS','legacy_view'=>'PASS','search_test'=>'deleted','allstar_mvp_rows'=>(int)$db->query("SELECT COUNT(*) FROM kbo_player_career WHERE type='올스타' AND note='MVP'")->fetchColumn()], JSON_UNESCAPED_UNICODE).PHP_EOL;
