<?php
/**
 * Ma Commune — Configuration Phinx (migrations)
 * Supporte les variables Railway (MYSQLHOST, MYSQLDATABASE...) et les variables standard (DB_HOST...)
 */

$configFile = __DIR__ . '/config/config.php';
if (is_file($configFile)) {
    require_once $configFile;
}

// Railway: MYSQLHOST, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD, MYSQLPORT
// Dev local / VPS: DB_HOST, DB_NAME, DB_USER, DB_PASSWORD|DB_PASS, DB_PORT
$dbHost = getenv('MYSQLHOST')     ?: getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
$dbName = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'ma_commune_db');
$dbUser = getenv('MYSQLUSER')     ?: getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'ma_commune_user');
$dbPass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: (defined('DB_PASSWORD') ? DB_PASSWORD : 'ma_commune_pass');
$dbPort = getenv('MYSQLPORT')     ?: getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : '3306');

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'production',

        'development' => [
            'adapter' => 'mysql',
            'host'    => $dbHost,
            'name'    => $dbName,
            'user'    => $dbUser,
            'pass'    => $dbPass,
            'port'    => $dbPort,
            'charset' => 'utf8mb4',
        ],

        'testing' => [
            'adapter' => 'mysql',
            'host'    => $dbHost,
            'name'    => $dbName,
            'user'    => $dbUser,
            'pass'    => $dbPass,
            'port'    => $dbPort,
            'charset' => 'utf8mb4',
        ],

        'production' => [
            'adapter' => 'mysql',
            'host'    => $dbHost,
            'name'    => $dbName,
            'user'    => $dbUser,
            'pass'    => $dbPass,
            'port'    => $dbPort,
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
