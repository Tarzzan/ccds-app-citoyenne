<?php

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'development',

        'development' => [
            'adapter' => 'mysql',
            'host'    => getenv('DB_HOST') ?: 'localhost',
            'name'    => getenv('DB_NAME') ?: 'ccds_db',
            'user'    => getenv('DB_USER') ?: 'ccds_user',
            'pass'    => getenv('DB_PASS') ?: 'ccds_pass',
            'port'    => getenv('DB_PORT') ?: '3306',
            'charset' => 'utf8mb4',
        ],

        'testing' => [
            'adapter' => 'mysql',
            'host'    => getenv('DB_HOST') ?: '127.0.0.1',
            'name'    => getenv('DB_NAME') ?: 'ccds_test',
            'user'    => getenv('DB_USER') ?: 'ccds_user',
            'pass'    => getenv('DB_PASS') ?: 'ccds_pass',
            'port'    => getenv('DB_PORT') ?: '3306',
            'charset' => 'utf8mb4',
        ],

        'production' => [
            'adapter' => 'mysql',
            'host'    => getenv('DB_HOST') ?: 'localhost',
            'name'    => getenv('DB_NAME') ?: 'ccds_db',
            'user'    => getenv('DB_USER') ?: 'ccds_user',
            'pass'    => getenv('DB_PASS') ?: 'ccds_pass',
            'port'    => getenv('DB_PORT') ?: '3306',
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
