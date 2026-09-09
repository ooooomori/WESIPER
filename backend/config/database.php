<?php

declare(strict_types=1);

$externalConfig = getenv('WESIPER_DB_CONFIG');

if ($externalConfig !== false && $externalConfig !== '') {
    if (!is_file($externalConfig)) {
        throw new RuntimeException('WESIPER_DB_CONFIG does not point to a readable file.');
    }

    return require $externalConfig;
}

$lightsailConfig = '/opt/bitnami/apache/conf/wesiper-db.php';

if (is_file($lightsailConfig)) {
    return require $lightsailConfig;
}

$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];

foreach ($required as $name) {
    if (getenv($name) === false) {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }
}

return [
    'host' => getenv('DB_HOST'),
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_NAME'),
    'username' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'charset' => 'utf8mb4',
];
