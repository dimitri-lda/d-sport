<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Catalog\StorefrontWarmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronously warm the storefront cache: category tree, featured cards
 * and the first unfiltered catalog pages for each selected locale.
 */
#[AsCommand(
    name: 'storefront:cache-warm',
    description: 'Warm the storefront cache (tree, featured and unfiltered list pages).',
)]
final class StorefrontCacheWarmCommand extends Command
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly StorefrontWarmer $warmer,
        private readonly array $locales,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pages', null, InputOption::VALUE_REQUIRED, 'Unfiltered pages to warm per locale', '2')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Locales to warm');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pagesOption = $input->getOption('pages');
        $pages = \is_numeric($pagesOption) ? (int) $pagesOption : 2;
        $localeOption = $input->getOption('locale');
        /** @var list<string> $selected */
        $selected = array_values(array_map(
            'strval',
            array_filter(\is_array($localeOption) ? $localeOption : [], 'is_scalar'),
        ));
        $locales = [] === $selected ? $this->locales : $selected;

        $this->warmer->warm($locales, max(1, $pages));
        $io->success(sprintf(
            'Storefront cache warmed for [%s], %d page(s) each.',
            implode(', ', array_intersect($locales, $this->locales)),
            min(max(1, $pages), 20),
        ));

        return Command::SUCCESS;
    }
}
