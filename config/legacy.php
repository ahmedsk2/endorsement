<?php

/*
|--------------------------------------------------------------------------
| Legacy import configuration
|--------------------------------------------------------------------------
|
| Everything the `legacy:import` command needs to reach the OLD procedural
| PHP app's MySQL database (the migration SOURCE) and to record what it did.
|
| The `database` array below is the single source of truth for the read-only
| `legacy` connection: config/database.php pulls it in verbatim. In production
| the LEGACY_DB_* env vars point this at the real legacy MySQL schema — with a
| SELECT-only grant; the importer only ever READS from this connection. In
| tests the connection is overridden to an isolated in-memory sqlite fixture.
|
*/

return [

    // Connection name the importer reads from (matches config/database.php).
    'connection' => 'legacy',

    // Where the reconciliation table is written after each run. Counts/ids only,
    // never PHI. Under `testing` it resolves INSIDE storage/ (gitignored) so no
    // test can dirty the tracked docs/RECONCILIATION.md.
    'reconciliation_path' => env('LEGACY_RECONCILIATION_PATH') ?: (
        env('APP_ENV') === 'testing'
            ? storage_path('framework/testing/RECONCILIATION.md')
            : base_path('docs/RECONCILIATION.md')
    ),

    // Read-only connection to the legacy MySQL database (the migration source).
    'database' => [
        'driver' => env('LEGACY_DB_DRIVER', 'mysql'),
        'url' => env('LEGACY_DB_URL'),
        'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
        'port' => env('LEGACY_DB_PORT', '3306'),
        'database' => env('LEGACY_DB_DATABASE', 'qch_legacy'),
        'username' => env('LEGACY_DB_USERNAME', 'root'),
        'password' => env('LEGACY_DB_PASSWORD', ''),
        'unix_socket' => env('LEGACY_DB_SOCKET', ''),
        'charset' => env('LEGACY_DB_CHARSET', 'utf8mb4'),
        'collation' => env('LEGACY_DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
    ],

];
