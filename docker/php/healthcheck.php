<?php

declare(strict_types=1);

$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:8080/api/health/live', false, $context);
exit(false !== $body && json_decode($body, true) === ['status' => 'ok'] ? 0 : 1);
