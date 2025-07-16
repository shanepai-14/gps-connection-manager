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
        
        // Check multiple indicators to determine if service is running
        $serviceStatus = $this->checkServiceStatus($pidFile);
        
        if ($serviceStatus['running']) {
            $io->success($serviceStatus['message']);
            
            // Show process information if available
            if ($serviceStatus['pid'] && $input->getOption('detailed')) {
                $processInfo = $this->getProcessInfo($serviceStatus['pid']);
                if ($processInfo) {
                    $table = new Table($output);
                    $table->setHeaders(['Property', 'Value']);
                    $table->setRows([
                        ['PID', $serviceStatus['pid']],
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
                            ['Instance ID', substr($serviceInfo['instance_id'] ?? 'N/A', 0, 8) . '...'],
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
            $io->error($serviceStatus['message']);
            
            // Show diagnostic information
            $this->showDiagnostics($io);
            
            return Command::FAILURE;
        }
    }

    /**
     * Check service status using multiple methods
     */
    private function checkServiceStatus(string $pidFile): array
    {
        // Method 1: Check PID file
        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            if (posix_kill($pid, 0)) {
                return [
                    'running' => true,
                    'pid' => $pid,
                    'message' => "Service is running with PID: $pid (from PID file)"
                ];
            } else {
                // PID file exists but process is dead
                unlink($pidFile);
            }
        }
        
        // Method 2: Check for socket file and try to connect
        $socketPath = $_ENV['SOCKET_POOL_UNIX_PATH'] ?? '/tmp/socket_pool_service.sock';
        if (file_exists($socketPath)) {
            // Try to connect to the socket
            if ($this->testSocketConnection($socketPath)) {
                // Find the PID of the process using the socket
                $pid = $this->findProcessUsingSocket($socketPath);
                return [
                    'running' => true,
                    'pid' => $pid,
                    'message' => "Service is running" . ($pid ? " with PID: $pid" : "") . " (socket responsive)"
                ];
            }
        }
        
        // Method 3: Check for socket-pool processes
        $pids = $this->findSocketPoolProcesses();
        if (!empty($pids)) {
            $pid = $pids[0]; // Use first found PID
            return [
                'running' => true,
                'pid' => $pid,
                'message' => "Service is running with PID: $pid (found by process name)"
            ];
        }
        
        return [
            'running' => false,
            'pid' => null,
            'message' => 'Service is not running'
        ];
    }

    /**
     * Test if socket connection is working
     */
    private function testSocketConnection(string $socketPath): bool
    {
        try {
            $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            if (!$socket) {
                return false;
            }
            
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 2, "usec" => 0]);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 2, "usec" => 0]);
            
            if (socket_connect($socket, $socketPath)) {
                // Send a simple health check
                $request = json_encode(['action' => 'health_check']);
                socket_write($socket, $request, strlen($request));
                
                $response = socket_read($socket, 1024);
                socket_close($socket);
                
                if ($response) {
                    $data = json_decode($response, true);
                    return $data && isset($data['success']);
                }
            }
            
            socket_close($socket);
            return false;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Find process using the socket file
     */
    private function findProcessUsingSocket(string $socketPath): ?int
    {
        $output = shell_exec("lsof '$socketPath' 2>/dev/null | grep -v COMMAND | head -1");
        if ($output) {
            $parts = preg_split('/\s+/', trim($output));
            return isset($parts[1]) ? (int) $parts[1] : null;
        }
        return null;
    }

    /**
     * Find socket-pool processes
     */
    private function findSocketPoolProcesses(): array
    {
        $output = shell_exec("pgrep -f 'socket-pool' 2>/dev/null");
        if ($output) {
            return array_map('intval', array_filter(explode("\n", trim($output))));
        }
        return [];
    }

    /**
     * Show diagnostic information
     */
    private function showDiagnostics(SymfonyStyle $io): void
    {
        $io->section('Diagnostics');
        
        $socketPath = $_ENV['SOCKET_POOL_UNIX_PATH'] ?? '/tmp/socket_pool_service.sock';
        
        // Check socket file
        if (file_exists($socketPath)) {
            $io->text("✓ Socket file exists: $socketPath");
            $perms = substr(sprintf('%o', fileperms($socketPath)), -4);
            $io->text("  Permissions: $perms");
        } else {
            $io->text("✗ Socket file missing: $socketPath");
        }
        
        // Check for socket-pool processes
        $pids = $this->findSocketPoolProcesses();
        if (!empty($pids)) {
            $io->text("✓ Found socket-pool processes: " . implode(', ', $pids));
        } else {
            $io->text("✗ No socket-pool processes found");
        }
        
        // Check PHP extensions
        $extensions = ['sockets', 'pcntl', 'json'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $io->text("✓ PHP extension '$ext' loaded");
            } else {
                $io->text("✗ PHP extension '$ext' missing");
            }
        }
        
        // Check if service directory exists
        $serviceDir = dirname(__DIR__, 2);
        if (is_dir($serviceDir)) {
            $io->text("✓ Service directory exists: $serviceDir");
        } else {
            $io->text("✗ Service directory missing: $serviceDir");
        }
        
        $io->newLine();
        $io->text("To start the service, run: ./bin/socket-pool start");
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