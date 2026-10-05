<?php
// 운영 서버에서 수동 실행. 웹 문서 루트에 배포하지 않습니다.
// 라인업 맞추기: 문제(경기·팀·난이도)별 결과 테이블과 오늘의 라인업 랭킹 테이블을 만든다.
// 같은 사람(브라우저가 만든 임의 식별자)이 같은 문제를 다시 풀어도 처음 결과만 남는다. IP 등 개인 정보는 저장하지 않는다.
$config = require '/opt/bitnami/apache/conf/wesiper-db.php';
$db = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lineup_results` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_code` VARCHAR(17) NOT NULL,
    `side` ENUM('away', 'home') NOT NULL,
    `difficulty` ENUM('easy', 'normal', 'hard', 'extreme') NOT NULL,
    `client_id` BINARY(16) NOT NULL,
    `solved` TINYINT(1) UNSIGNED NOT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL,
    `milliseconds` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lineup_result` (`game_code`, `side`, `difficulty`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

// 오늘의 라인업: 하루에 한 사람당 처음 결과 하나. 랭킹은 맞힌 사람끼리 제출 횟수, 시간 순이다.
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lineup_daily_results` (
    `daily_date` DATE NOT NULL,
    `client_id` BINARY(16) NOT NULL,
    `solved` TINYINT(1) UNSIGNED NOT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL,
    `milliseconds` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`daily_date`, `client_id`),
    KEY `idx_lineup_daily_rank` (`daily_date`, `solved`, `attempts`, `milliseconds`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

echo "lineup_results, lineup_daily_results ready\n";
