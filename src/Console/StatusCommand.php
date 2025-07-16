<?php

declare(strict_types=1);

namespace SocketPool\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

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
            
            // Try to test socket connection directly
            try {
                $socketStatus = $this->testSocketHealthDirect();
                $io->section('Socket Health');
                if ($socketStatus['success']) {
                    $io->success('Socket is responding: ' . $socketStatus['message']);
                } else {
                    $io->error('Socket not responding: ' . $socketStatus['error']);
                }
            } catch (\Exception $e) {
                $io->warning('Could not test socket connection: ' . $e->getMessage());
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
     * Test socket connection directly without SocketPoolClient
     */
    private function testSocketHealthDirect(): array
    {
        $socketPath = $_ENV['SOCKET_POOL_UNIX_PATH'] ?? '/tmp/socket_pool_service.sock';
        
        try {
            $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            if (!$socket) {
                throw new \Exception("Failed to create socket: " . socket_strerror(socket_last_error()));
            }
            
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 3, "usec" => 0]);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 3, "usec" => 0]);
            
            if (!socket_connect($socket, $socketPath)) {
                throw new \Exception("Failed to connect to socket: " . socket_strerror(socket_last_error()));
            }
            
            // Send health check request
            $request = json_encode(['action' => 'health_check']);
            $bytesWritten = socket_write($socket, $request, strlen($request));
            
            if ($bytesWritten === false) {
                throw new \Exception("Failed to write to socket: " . socket_strerror(socket_last_error()));
            }
            
            // Read response
            $response = socket_read($socket, 1024);
            if ($response === false) {
                throw new \Exception("Failed to read from socket: " . socket_strerror(socket_last_error()));
            }
            
            socket_close($socket);
            
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON response: " . json_last_error_msg());
            }
            
            return [
                'success' => true,
                'message' => $data['message'] ?? 'Health check passed',
                'data' => $data
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
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
                socket_close($socket);
                return true;
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
            $owner = posix_getpwuid(fileowner($socketPath))['name'] ?? 'unknown';
            $group = posix_getgrgid(filegroup($socketPath))['name'] ?? 'unknown';
            $io->text("  Permissions: $perms (owner: $owner, group: $group)");
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
        $extensions = ['sockets', 'pcntl', 'json', 'posix'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $io->text("✓ PHP extension '$ext' loaded");
            } else {
                $io->text("✗ PHP extension '$ext' missing");
            }
        }
        
        // Check service directory
        $serviceDir = dirname(__DIR__, 2);
        if (is_dir($serviceDir)) {
            $io->text("✓ Service directory exists: $serviceDir");
        } else {
            $io->text("✗ Service directory missing: $serviceDir");
        }
        
        // Check log files
        $logFile = $_ENV['SOCKET_POOL_LOG_FILE'] ?? 'logs/socket_pool_service.log';
        if (file_exists($logFile)) {
            $io->text("✓ Log file exists: $logFile");
            $size = filesize($logFile);
            $io->text("  Size: " . number_format($size) . " bytes");
        } else {
            $io->text("✗ Log file missing: $logFile");
        }
        
        $io->newLine();
        $io->text("To start the service, run: ./bin/socket-pool start");
        $io->text("To view logs, run: tail -f $logFile");
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
            $starttime = (int)($parts[21] ?? 0) / 100; // Convert from jiffies to seconds
            $processUptime = $uptime - $starttime;
            $info['start_time'] = date('Y-m-d H:i:s', (int)(time() - $uptime + $starttime));
        }
        
        // Get CPU and memory info from /proc/[pid]/status
        if (file_exists($statusFile)) {
            $status = file_get_contents($statusFile);
            preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches);
            if ($matches) {
                $memoryKB = (int) $matches[1];
                $info['memory'] = number_format($memoryKB / 1024, 2) . ' MB';
            }
        }
        
        // Get command line
        if (file_exists($cmdlineFile)) {
            $cmdline = file_get_contents($cmdlineFile);
            $info['command'] = str_replace("\0", ' ', $cmdline);
        }
        
        // Get user
        $info['user'] = posix_getpwuid(fileowner("/proc/$pid"))['name'] ?? 'N/A';
        
        // CPU usage would require sampling, so we'll skip it for now
        $info['cpu'] = 'N/A';
        
        return $info;
    }
}