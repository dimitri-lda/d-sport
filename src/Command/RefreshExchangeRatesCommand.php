<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Commerce\ExchangeRateRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Force-refresh all external exchange-rate quotes. Each currency is
 * reported independently — rate and source on success, a generic failure
 * otherwise — and any failure fails the command so cron/supervision
 * notices.
 */
#[AsCommand(
    name: 'commerce:refresh-exchange-rates',
    description: 'Fetch and store fresh exchange-rate quotes for all storefront currencies.',
)]
final class RefreshExchangeRatesCommand extends Command
{
    public function __construct(private readonly ExchangeRateRefresher $refresher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $results = $this->refresher->refreshAll(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $failed = false;
        foreach ($results as $currency => $result) {
            if (true === ($result['ok'] ?? false)) {
                $io->writeln(sprintf(
                    '%s %s (%s)',
                    $currency,
                    $result['rate'] ?? '?',
                    $result['source'] ?? 'unknown',
                ));
            } else {
                $failed = true;
                $io->error($currency.' refresh failed ('.($result['error'] ?? 'unknown').').');
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
