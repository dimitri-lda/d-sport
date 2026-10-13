<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Cache\StorefrontCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotate every storefront cache generation: all tree/list/card entries
 * become unreachable at once and expire naturally.
 */
#[AsCommand(
    name: 'storefront:cache-clear',
    description: 'Rotate all storefront cache generations, invalidating every cached entry.',
)]
final class StorefrontCacheClearCommand extends Command
{
    public function __construct(private readonly StorefrontCache $cache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->flush();
        (new SymfonyStyle($input, $output))->success('Storefront cache generations rotated.');

        return Command::SUCCESS;
    }
}
