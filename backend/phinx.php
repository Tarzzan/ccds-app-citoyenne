<?php
/**
 * CCDS — Configuration Phinx (migrations)
 * Supporte les variables Railway (MYSQLHOST, MYSQLDATABASE...) et les variables standard (DB_HOST...)
 */

// Railway: MYSQLHOST, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD, MYSQLPORT
// Dev local: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
$dbHost = getenv('MYSQLHOST')     ?: getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'ccds_db';
$dbUser = getenv('MYSQLUSER')     ?: getenv('DB_USER') ?: 'ccds_user';
$dbPass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: 'ccds_pass';
$dbPort = getenv('MYSQLPORT')     ?: getenv('DB_PORT') ?: '3306';

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
