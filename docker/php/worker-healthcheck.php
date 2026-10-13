<?php

declare(strict_types=1);

// A stopped consumer or a lost database must make the worker unhealthy.
$consumerFound = false;
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
    $command = @file_get_contents($path);
    if (false !== $command && str_contains($command, 'messenger:consume')) {
        $consumerFound = true;
        break;
    }
}
if (!$consumerFound) {
    exit(1);
}
$result = 1;
passthru('php /app/bin/console dbal:run-sql "SELECT 1" --no-interaction >/dev/null 2>&1', $result);
exit($result === 0 ? 0 : 1);
