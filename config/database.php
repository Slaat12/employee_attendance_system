
<?php

function getDatabaseConnection(): PDO
{
    $localConfig = __DIR__ . '/database.local.php';

    if (file_exists($localConfig)) {
        $config = require $localConfig;
    } else {
        $config = [
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT') ?: '5432',
            'name' => getenv('DB_NAME'),
            'user' => getenv('DB_USER'),
            'pass' => getenv('DB_PASSWORD'),
        ];
    }

    foreach (['host', 'name', 'user', 'pass'] as $key) {
        if (empty($config[$key])) {
            throw new RuntimeException(
                "Missing database configuration: " . $key
            );
        }
    }

    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['name']};sslmode=require";

    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
