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


class HealthCommand extends Command
{
    protected static $defaultName = 'health';
    protected static $defaultDescription = 'Perform health check';

    protected function configure(): void
    {
        $this
            ->setDescription('Perform comprehensive health check')
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed health information');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $client = new SocketPoolClient();
            $health = $client->performHealthCheck();
            
            if ($health['success']) {
                $io->success('Service is healthy');
                
                if (isset($health['data'])) {
                    $data = $health['data'];
                    
                    $table = new Table($output);
                    $table->setHeaders(['Property', 'Value']);
                    $table->setRows([
                        ['Status', $this->formatStatus($data['status'] ?? 'unknown')],
                        ['Instance ID', substr($data['instance_id'] ?? 'N/A', 0, 8) . '...'],
                        ['Timestamp', date('Y-m-d H:i:s', $data['timestamp'] ?? time())]
                    ]);
                    $table->render();
                    
                    if (!empty($data['checks'])) {
                        $io->section('Health Checks');
                        $checksTable = new Table($output);
                        $checksTable->setHeaders(['Check', 'Status']);
                        $checks = [];
                        foreach ($data['checks'] as $check => $status) {
                            $checks[] = [$check, $this->formatCheckStatus($status)];
                        }
                        $checksTable->setRows($checks);
                        $checksTable->render();
                    }
                    
                    if ($input->getOption('detailed')) {
                        // Additional health metrics
                        $metrics = $client->getMetrics();
                        if ($metrics['success']) {
                            $io->section('System Metrics');
                            $metricsTable = new Table($output);
                            $metricsTable->setHeaders(['Metric', 'Value']);
                            $metricsData = $metrics['data'];
                            $metricsTable->setRows([
                                ['Memory Usage', $this->formatBytes($metricsData['memory_usage'] ?? 0)],
                                ['Peak Memory', $this->formatBytes($metricsData['peak_memory'] ?? 0)],
                                ['Uptime', $this->formatUptime($metricsData['uptime'] ?? 0)]
                            ]);
                            $metricsTable->render();
                        }
                    }
                }
                
                return Command::SUCCESS;
            } else {
                $io->error('Health check failed: ' . ($health['error'] ?? 'Unknown error'));
                return Command::FAILURE;
            }
            
        } catch (\Exception $e) {
            $io->error('Error performing health check: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function formatStatus(string $status): string
    {
        return match($status) {
            'healthy' => '<fg=green>✓ Healthy</>',
            'degraded' => '<fg=yellow>⚠ Degraded</>',
            'unhealthy' => '<fg=red>✗ Unhealthy</>',
            default => $status
        };
    }

    private function formatCheckStatus(string $status): string
    {
        return match($status) {
            'ok' => '<fg=green>✓ OK</>',
            'failed' => '<fg=red>✗ Failed</>',
            'degraded' => '<fg=yellow>⚠ Degraded</>',
            default => $status
        };
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function formatUptime(int $seconds): string
    {
        $units = [
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
            'second' => 1
        ];
        
        $parts = [];
        foreach ($units as $name => $divisor) {
            $quot = intval($seconds / $divisor);
            if ($quot) {
                $parts[] = $quot . ' ' . $name . ($quot > 1 ? 's' : '');
                $seconds %= $divisor;
            }
        }
        
        return empty($parts) ? '0 seconds' : implode(', ', $parts);
    }
}