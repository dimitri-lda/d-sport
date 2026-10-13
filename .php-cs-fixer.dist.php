<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/config', __DIR__.'/migrations', __DIR__.'/tests'])
    ->exclude('performance')
    ->notName('reference.php')
    ->append([__DIR__.'/public/index.php', __DIR__.'/bin/console']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'void_return' => true,
    ])
    ->setCacheFile(__DIR__.'/var/php-cs-fixer.cache')
    ->setFinder($finder);
