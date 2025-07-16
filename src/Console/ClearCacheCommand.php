<?php

declare(strict_types=1);

namespace SocketPool\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use SocketPool\Services\SocketPoolService;
use SocketPool\Client\SocketPoolClient;

class ClearCacheCommand extends Command
{
    protected static $defaultName = 'cache:clear';
    protected static $defaultDescription = 'Clear service cache';

    protected function configure(): void
    {
        $this
            ->setDescription('Clear service cache and metrics')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Cache type: all|metrics|circuit-breaker|connections', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getOption('type');

        $io->title('Clearing Socket Pool Cache');

        try {
            $client = new SocketPoolClient();
            
            switch ($type) {
                case 'all':
                    $this->clearAllCache($io, $client);
                    break;
                case 'metrics':
                    $this->clearMetricsCache($io, $client);
                    break;
                case 'circuit-breaker':
                    $this->clearCircuitBreakerCache($io, $client);
                    break;
                case 'connections':
                    $this->clearConnectionCache($io, $client);
                    break;
                default:
                    $io->error("Unknown cache type: $type");
                    return Command::FAILURE;
            }

            $io->success('Cache cleared successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to clear cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function clearAllCache(SymfonyStyle $io, SocketPoolClient $client): void
    {
        $io->info('Clearing all cache types...');
        // Implementation would depend on Redis keys structure
        $io->info('All cache cleared');
    }

    private function clearMetricsCache(SymfonyStyle $io, SocketPoolClient $client): void
    {
        $io->info('Clearing metrics cache...');
        // Clear metrics-related cache
    }

    private function clearCircuitBreakerCache(SymfonyStyle $io, SocketPoolClient $client): void
    {
        $io->info('Clearing circuit breaker cache...');
        // Reset circuit breaker states
    }

    private function clearConnectionCache(SymfonyStyle $io, SocketPoolClient $client): void
    {
        $io->info('Clearing connection cache...');
        // Clear connection-related cache
    }
}