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



class StatsCommand extends Command
{
    protected static $defaultName = 'stats';
    protected static $defaultDescription = 'Show connection statistics';

    protected function configure(): void
    {
        $this
            ->setDescription('Show connection statistics and metrics')
            ->addOption('watch', 'w', InputOption::VALUE_OPTIONAL, 'Refresh every N seconds', false)
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table|json)', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watchInterval = $input->getOption('watch');
        $format = $input->getOption('format');
        
        try {
            $client = new SocketPoolClient();
            
            do {
                if ($watchInterval !== false) {
                    // Clear screen for watch mode
                    system('clear');
                }
                
                $stats = $client->getConnectionStats();
                
                if (!$stats['success']) {
                    $io->error('Failed to get stats: ' . ($stats['error'] ?? 'Unknown error'));
                    return Command::FAILURE;
                }
                
                if ($format === 'json') {
                    $output->writeln(json_encode($stats['data'], JSON_PRETTY_PRINT));
                } else {
                    $this->displayStatsTable($io, $output, $stats['data']);
                }
                
                if ($watchInterval !== false) {
                    $io->note("Refreshing every {$watchInterval} seconds... (Press Ctrl+C to stop)");
                    sleep((int)$watchInterval);
                }
                
            } while ($watchInterval !== false);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error getting statistics: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function displayStatsTable(SymfonyStyle $io, OutputInterface $output, array $data): void
    {
        $io->title('Socket Pool Statistics');
        
        // General stats
        $generalTable = new Table($output);
        $generalTable->setHeaders(['Metric', 'Value']);
        $generalTable->setRows([
            ['Pool Size', $data['pool_size'] ?? 'N/A'],
            ['Max Pool Size', $data['max_pool_size'] ?? 'N/A'],
            ['Instance ID', substr($data['instance_id'] ?? 'N/A', 0, 8) . '...'],
            ['Active Connections', count($data['active_connections'] ?? [])]
        ]);
        $generalTable->render();
        
        // Connection statistics
        if (!empty($data['connection_stats'])) {
            $io->section('Connection Statistics');
            $connectionTable = new Table($output);
            $connectionTable->setHeaders(['Host:Port', 'Success', 'Failed', 'Total', 'Success Rate']);
            $connectionStats = [];
            
            foreach ($data['connection_stats'] as $host => $stats) {
                $total = $stats['total'] ?? 0;
                $success = $stats['success'] ?? 0;
                $failed = $stats['failed'] ?? 0;
                $successRate = $total > 0 ? round(($success / $total) * 100, 1) . '%' : 'N/A';
                
                $connectionStats[] = [
                    $host,
                    $success,
                    $failed,
                    $total,
                    $successRate
                ];
            }
            $connectionTable->setRows($connectionStats);
            $connectionTable->render();
        }
        
        // Active connections
        if (!empty($data['active_connections'])) {
            $io->section('Active Connections');
            $activeTable = new Table($output);
            $activeTable->setHeaders(['Connection']);
            $activeConnections = array_map(fn($conn) => [$conn], $data['active_connections']);
            $activeTable->setRows($activeConnections);
            $activeTable->render();
        }
    }
}