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


class StatusCommand extends Command
{
    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Show Socket Pool Service status';

    protected function configure(): void
    {
        $this
            ->setDescription('Show Socket Pool Service status')
            ->addOption('pid-file', 'p', InputOption::VALUE_REQUIRED, 'PID file location', '/var/run/socket-pool/socket-pool.pid')
            ->addOption('detailed', null, InputOption::VALUE_NONE, 'Show detailed status information');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pidFile = $input->getOption('pid-file');
        
        $io->title('Socket Pool Service Status');
        
        // Check PID file
        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            
            if (posix_kill($pid, 0)) {
                $io->success("Service is running with PID: $pid");
                
                // Get process info
                if ($input->getOption('detailed')) {
                    $processInfo = $this->getProcessInfo($pid);
                    if ($processInfo) {
                        $table = new Table($output);
                        $table->setHeaders(['Property', 'Value']);
                        $table->setRows([
                            ['PID', $pid],
                            ['Start Time', $processInfo['start_time']],
                            ['CPU Usage', $processInfo['cpu'] . '%'],
                            ['Memory Usage', $processInfo['memory']],
                            ['Status', 'Running'],
                            ['User', $processInfo['user'] ?? 'N/A'],
                            ['Command', $processInfo['command'] ?? 'N/A']
                        ]);
                        $table->render();
                    }
                }
                
                // Try to get service health
                try {
                    $client = new SocketPoolClient();
                    $health = $client->performHealthCheck();
                    
                    $io->section('Service Health');
                    if ($health['success']) {
                        $io->success('Service is healthy');
                        if (isset($health['data']['checks'])) {
                            $healthTable = new Table($output);
                            $healthTable->setHeaders(['Check', 'Status']);
                            $checks = [];
                            foreach ($health['data']['checks'] as $check => $status) {
                                $checks[] = [$check, $this->formatHealthStatus($status)];
                            }
                            $healthTable->setRows($checks);
                            $healthTable->render();
                        }
                    } else {
                        $io->error('Service health check failed: ' . ($health['error'] ?? 'Unknown error'));
                    }
                    
                    // Show service info if detailed
                    if ($input->getOption('detailed')) {
                        $serviceInfo = $client->getServiceInfo();
                        if ($serviceInfo['success']) {
                            $io->section('Service Information');
                            $infoTable = new Table($output);
                            $infoTable->setHeaders(['Property', 'Value']);
                            $infoTable->setRows([
                                ['Version', $serviceInfo['version'] ?? 'N/A'],
                                ['Instance ID', $serviceInfo['instance_id'] ?? 'N/A'],
                                ['Uptime', $this->formatUptime($serviceInfo['uptime'] ?? 0)],
                                ['Pool Size', $serviceInfo['pool_size'] ?? 'N/A']
                            ]);
                            $infoTable->render();
                        }
                    }
                    
                } catch (\Exception $e) {
                    $io->warning('Could not perform health check: ' . $e->getMessage());
                }
                
                return Command::SUCCESS;
                
            } else {
                $io->error("PID file exists but process $pid is not running");
                return Command::FAILURE;
            }
        } else {
            $io->error('Service is not running (PID file not found)');
            return Command::FAILURE;
        }
    }

    private function getProcessInfo(int $pid): ?array
    {
        $statFile = "/proc/$pid/stat";
        $statusFile = "/proc/$pid/status";
        $cmdlineFile = "/proc/$pid/cmdline";
        
        if (!file_exists($statFile)) {
            return null;
        }
        
        $info = [];
        
        // Get basic stat info
        $stat = file_get_contents($statFile);
        $parts = explode(' ', $stat);
        
        // Calculate start time
        $uptimeFile = '/proc/uptime';
        if (file_exists($uptimeFile)) {
            $uptime = (float) explode(' ', file_get_contents($uptimeFile))[0];
            $starttime = (int)($parts[21] ?? 0) / 100;
           $info['start_time'] = date('Y-m-d H:i:s', (int)(time() - $uptime + $starttime));
        } else {
            $info['start_time'] = 'N/A';
        }
        
        // Get memory info
        if (file_exists($statusFile)) {
            $status = file_get_contents($statusFile);
            if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                $info['memory'] = round($matches[1] / 1024, 2) . ' MB';
            } else {
                $info['memory'] = 'N/A';
            }
            
            // Get user info
            if (preg_match('/Uid:\s+(\d+)/', $status, $matches)) {
                $userInfo = posix_getpwuid((int)$matches[1]);
                $info['user'] = $userInfo['name'] ?? 'N/A';
            }
        } else {
            $info['memory'] = 'N/A';
        }
        
        // Get command line
        if (file_exists($cmdlineFile)) {
            $cmdline = file_get_contents($cmdlineFile);
            $info['command'] = str_replace("\0", ' ', trim($cmdline));
        }
        
        $info['cpu'] = 'N/A'; // Would need more calculation for real-time CPU
        
        return $info;
    }

    private function formatHealthStatus(string $status): string
    {
        return match($status) {
            'ok' => '<fg=green>✓ OK</>',
            'failed' => '<fg=red>✗ Failed</>',
            'degraded' => '<fg=yellow>⚠ Degraded</>',
            default => $status
        };
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
