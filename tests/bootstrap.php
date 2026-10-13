<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Tests do not require a local .env or live dependencies. Any future database
// integration uses Doctrine's mandatory _test database suffix.
$defaults = [
    'APP_SECRET' => 'test-only-infrastructure-secret',
    'APP_NAME' => 'Sport E',
    'BUILD_VERSION' => 'test',
    'DATABASE_URL' => 'postgresql://sport_e:test@127.0.0.1:1/sport_e?serverVersion=18&charset=utf8',
    'REDIS_URL' => 'redis://127.0.0.1:1',
    'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=false',
    'PGCONNECT_TIMEOUT' => '2',
    'PGOPTIONS' => '-c statement_timeout=2000',
];

foreach ($defaults as $name => $value) {
    if (!isset($_SERVER[$name]) && false === getenv($name)) {
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }
}
