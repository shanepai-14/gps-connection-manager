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


class TestCommand extends Command
{
    protected static $defaultName = 'test';
    protected static $defaultDescription = 'Run service tests';

    protected function configure(): void
    {
        $this
            ->setDescription('Run comprehensive service tests')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Test host', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Test port', '2199')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Number of test requests', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $count = (int) $input->getOption('count');

        $io->title('Socket Pool Service Test Suite');

        try {
            $client = new SocketPoolClient();

            // Test 1: Service Health
            $io->section('1. Service Health Check');
            $health = $client->performHealthCheck();
            if ($health['success']) {
                $io->success('Service is healthy');
            } else {
                $io->error('Service health check failed: ' . ($health['error'] ?? 'Unknown'));
                return Command::FAILURE;
            }

            // Test 2: Connection Test
            $io->section('2. Connection Test');
            $connectionTest = $client->testConnection($host, $port);
            if ($connectionTest['success']) {
                $io->success("Connection successful (Response time: {$connectionTest['response_time']}ms)");
            } else {
                $io->warning('Connection test failed: ' . ($connectionTest['error'] ?? 'Unknown'));
            }

            // Test 3: Load Test
            $io->section('3. Load Test');
            $io->info("Sending $count test requests...");
            
            $progressBar = new ProgressBar($output, $count);
            $progressBar->start();
            
            $results = [];
            $startTime = microtime(true);
            
            for ($i = 0; $i < $count; $i++) {
                $testData = "TEST_DATA_" . ($i + 1) . "_" . time();
                $result = $client->sendGpsData($testData, $host, $port, "TEST_VEHICLE_$i");
                $results[] = $result;
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $output->writeln('');
            
            $totalTime = microtime(true) - $startTime;
            $successful = count(array_filter($results, fn($r) => $r['success']));
            $failed = $count - $successful;
            $avgTime = $totalTime / $count;
            $requestsPerSecond = $count / $totalTime;

            // Display results
            $table = new Table($output);
            $table->setHeaders(['Metric', 'Value']);
            $table->setRows([
                ['Total Requests', $count],
                ['Successful', $successful],
                ['Failed', $failed],
                ['Success Rate', round(($successful / $count) * 100, 2) . '%'],
                ['Total Time', round($totalTime, 3) . 's'],
                ['Average Time/Request', round($avgTime * 1000, 2) . 'ms'],
                ['Requests/Second', round($requestsPerSecond, 2)]
            ]);
            $table->render();

            // Test 4: Statistics
            $io->section('4. Service Statistics');
            $stats = $client->getConnectionStats();
            if ($stats['success']) {
                $io->info("Pool size: " . ($stats['data']['pool_size'] ?? 'N/A'));
                $io->info("Active connections: " . count($stats['data']['active_connections'] ?? []));
            }

            if ($failed === 0) {
                $io->success('All tests passed!');
                return Command::SUCCESS;
            } else {
                $io->warning("Tests completed with $failed failures");
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error('Test failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}