<?php
/**
 * PSMS configuration.
 * Defaults match a fresh XAMPP install (MySQL user "root", empty password).
 * Change these if your MySQL/MariaDB setup is different.
 */
return [
    'db' => [
        'driver'      => getenv('PSMS_DB_DRIVER') ?: 'mysql',   // 'mysql' (XAMPP / MariaDB) or 'sqlite' (testing only)
        'host'        => 'localhost',
        'port'        => 3306,
        'name'        => 'psms_db',
        'user'        => 'root',
        'pass'        => '',
        'sqlite_path' => getenv('PSMS_SQLITE_PATH') ?: __DIR__ . '/database/psms.sqlite',
    ],
    'session_idle_seconds' => 1800,   // auto sign-out after 30 minutes of inactivity
    'page_size'            => 20,
];
