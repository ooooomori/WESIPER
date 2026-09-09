<?php
date_default_timezone_set('Asia/Seoul');

$dbConfig = require dirname(__DIR__, 2) . '/config/database.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $dbConfig['host'],
    $dbConfig['port'] ?? 3306,
    $dbConfig['database'],
    $dbConfig['charset']
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO(
        $dsn,
        $dbConfig['username'],
        $dbConfig['password'],
        $options
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('서버 내부 오류가 발생했습니다.');
}
    
    function image_exists($p_no) {
        return file_exists($_SERVER["DOCUMENT_ROOT"]."/assets/images/player/kbo/$p_no.png");
    }

    function getKBOSchedule() {
        return [
            '2026' => ['preseason' => ['2026-03-12', '2026-03-24'], 'regular' => ['2026-03-28', '2026-12-31'], 'postseason' => ['', '']],
            '2025' => ['preseason' => ['2025-03-08', '2025-03-18'], 'regular' => ['2025-03-22', '2025-10-04'], 'postseason' => ['2025-10-06', '2025-10-31']],
            '2024' => ['preseason' => ['2024-03-09', '2024-03-19'], 'regular' => ['2024-03-23', '2024-10-01'], 'postseason' => ['2024-10-02', '2024-10-28']],
            '2023' => ['preseason' => ['2023-03-13', '2023-03-28'], 'regular' => ['2023-04-01', '2023-10-17'], 'postseason' => ['2023-10-19', '2023-11-13']],
            '2022' => ['preseason' => ['2022-03-12', '2022-03-29'], 'regular' => ['2022-04-02', '2022-10-11'], 'postseason' => ['2022-10-13', '2022-11-08']],
            '2021' => ['preseason' => ['2021-03-21', '2021-03-30'], 'regular' => ['2021-04-03', '2021-10-31'], 'postseason' => ['2021-11-01', '2021-11-18']],
            '2020' => ['preseason' => ['', ''], 'regular' => ['2020-05-05', '2020-10-31'], 'postseason' => ['2020-11-02', '2020-11-24']],
            '2019' => ['preseason' => ['2019-03-12', '2019-03-20'], 'regular' => ['2019-03-23', '2019-10-01'], 'postseason' => ['2019-10-03', '2019-10-26']],
            '2018' => ['preseason' => ['2018-03-13', '2018-03-21'], 'regular' => ['2018-03-24', '2018-10-14'], 'postseason' => ['2018-10-16', '2018-11-12']]
        ];
    }
?>
