<?php
// 운영 서버에서 수동 실행. 웹 문서 루트에 배포하지 않습니다.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$columns = $db->query("SHOW COLUMNS FROM kbobingo_stat")->fetchAll(PDO::FETCH_ASSOC);
$byName = array_column($columns, null, 'Field');
if (!isset($byName['picks'])) {
    $db->exec("ALTER TABLE kbobingo_stat ADD COLUMN picks LONGTEXT NULL");
} elseif (!preg_match('/text|json|varchar/i', $byName['picks']['Type'])) {
    throw new RuntimeException('picks 컬럼 타입 확인 필요: ' . $byName['picks']['Type']);
}
if (!isset($byName['is_public'])) {
    $db->exec("ALTER TABLE kbobingo_stat ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1");
}
echo "Bingo sharing schema ready\n";
