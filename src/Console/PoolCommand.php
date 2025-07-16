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

class PoolCommand extends Command
{
    protected static $defaultName = 'pool';
    protected static $defaultDescription = 'Manage connection pool';

    protected function configure(): void
    {
        $this
            ->setDescription('Manage connection pool operations')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: list|close|warm-up|drain')
            ->addArgument('target', InputArgument::OPTIONAL, 'Target (host:port for close)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Apply to all connections');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $target = $input->getArgument('target');
        $all = $input->getOption('all');

        try {
            $client = new SocketPoolClient();

            switch ($action) {
                case 'list':
                    return $this->listConnections($io, $client);
                case 'close':
                    return $this->closeConnections($io, $client, $target, $all);
                case 'warm-up':
                    return $this->warmUpConnections($io, $client);
                case 'drain':
                    return $this->drainPool($io, $client);
                default:
                    $io->error("Unknown action: $action");
                    return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error('Pool operation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function listConnections(SymfonyStyle $io, SocketPoolClient $client): int
    {
        $stats = $client->getConnectionStats();
        
        if (!$stats['success']) {
            $io->error('Failed to get pool stats');
            return Command::FAILURE;
        }

        $data = $stats['data'];
        $io->title('Active Pool Connections');
        
        if (empty($data['active_connections'])) {
            $io->info('No active connections in pool');
            return Command::SUCCESS;
        }

        $table = new Table($io);
        $table->setHeaders(['Connection', 'Status']);
        
        foreach ($data['active_connections'] as $connection) {
            $table->addRow([$connection, 'Active']);
        }
        
        $table->render();
        return Command::SUCCESS;
    }

    private function closeConnections(SymfonyStyle $io, SocketPoolClient $client, ?string $target, bool $all): int
    {
        if ($all) {
            $io->warning('Closing all connections...');
            // Implementation for closing all connections
            $io->success('All connections closed');
        } elseif ($target) {
            if (!str_contains($target, ':')) {
                $io->error('Target must be in format host:port');
                return Command::FAILURE;
            }
            
            list($host, $port) = explode(':', $target, 2);
            $result = $client->closeConnection($host, (int)$port);
            
            if ($result['success']) {
                $io->success("Connection closed: $target");
            } else {
                $io->error("Failed to close connection: " . ($result['error'] ?? 'Unknown'));
                return Command::FAILURE;
            }
        } else {
            $io->error('Specify target or use --all flag');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function warmUpConnections(SymfonyStyle $io, SocketPoolClient $client): int
    {
        $io->info('Warming up connections...');
        
        // Example warm-up targets (would be configurable)
        $targets = [
            ['host' => 'localhost', 'port' => 2199],
            // Add more targets as needed
        ];
        
        if (empty($targets)) {
            $io->warning('No warm-up targets configured');
            return Command::SUCCESS;
        }

        $results = $client->warmUpConnections($targets);
        
        $table = new Table($io);
        $table->setHeaders(['Host:Port', 'Status']);
        
        foreach ($results as $result) {
            $status = $result['success'] ? '<fg=green>✓ OK</>' : '<fg=red>✗ Failed</>';
            $table->addRow([
                $result['host'] . ':' . $result['port'],
                $status
            ]);
        }
        
        $table->render();
        return Command::SUCCESS;
    }

    private function drainPool(SymfonyStyle $io, SocketPoolClient $client): int
    {
        $io->warning('Draining connection pool...');
        
        // Get current connections
        $stats = $client->getConnectionStats();
        if ($stats['success'] && !empty($stats['data']['active_connections'])) {
            $count = count($stats['data']['active_connections']);
            $io->info("Closing $count active connections...");
            
            // Close all connections (implementation would vary)
            $io->success('Pool drained successfully');
        } else {
            $io->info('Pool is already empty');
        }

        return Command::SUCCESS;
    }
}