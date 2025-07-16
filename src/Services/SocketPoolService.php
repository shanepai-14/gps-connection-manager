<?php

declare(strict_types=1);

namespace SocketPool\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Predis\Client as RedisClient;
use Ramsey\Uuid\Uuid;
use SocketPool\Exceptions\SocketPoolException;
use SocketPool\Exceptions\ConnectionException;
use Dotenv\Dotenv;

/**
 * Enhanced Socket Pool Service with Composer Dependencies
 * Manages reusable socket connections with advanced features
 */
class SocketPoolService
{
    private static ?SocketPoolService $instance = null;
    private array $socketPool = [];
    private array $connectionStats = [];
    private array $config = [];
    private Logger $logger;
    private ?RedisClient $redis = null;
    private string $unixSocketPath;
    private $unixSocket;
    private bool $running = true;
    private string $instanceId;

    private function __construct()
    {
        $this->instanceId = Uuid::uuid4()->toString();
        $this->loadConfiguration();
        $this->initializeLogger();
        $this->initializeRedis();
        $this->initializeUnixSocket();
        
        $this->logger->info("Socket Pool Service initialized", [
            'instance_id' => $this->instanceId,
            'config' => $this->config
        ]);
    }

    public static function getInstance(): SocketPoolService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration(): void
    {
        // Load environment variables
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->load();
        }

        $this->config = [
            'max_pool_size' => (int) ($_ENV['SOCKET_POOL_MAX_SIZE'] ?? 100),
            'connection_timeout' => (int) ($_ENV['SOCKET_POOL_TIMEOUT'] ?? 30),
            'max_retries' => (int) ($_ENV['SOCKET_POOL_MAX_RETRIES'] ?? 3),
            'unix_socket_path' => $_ENV['SOCKET_POOL_UNIX_PATH'] ?? '/tmp/socket_pool_service.sock',
            'log_level' => $_ENV['SOCKET_POOL_LOG_LEVEL'] ?? 'INFO',
            'log_file' => $_ENV['SOCKET_POOL_LOG_FILE'] ?? __DIR__ . '/../../logs/socket_pool_service.log',
            'redis_enabled' => filter_var($_ENV['SOCKET_POOL_REDIS_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'redis_host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'redis_port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'redis_password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'metrics_enabled' => filter_var($_ENV['SOCKET_POOL_METRICS_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'health_check_interval' => (int) ($_ENV['SOCKET_POOL_HEALTH_INTERVAL'] ?? 60),
        ];

        $this->unixSocketPath = $this->config['unix_socket_path'];
    }

    private function initializeLogger(): void
    {
        $this->logger = new Logger('socket_pool');
        
        // Console handler for development
        if (php_sapi_name() === 'cli') {
            $consoleHandler = new StreamHandler('php://stdout', $this->config['log_level']);
            $consoleHandler->setFormatter(new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context%\n",
                'Y-m-d H:i:s'
            ));
            $this->logger->pushHandler($consoleHandler);
        }

        // File handler with rotation
        $fileHandler = new RotatingFileHandler(
            $this->config['log_file'],
            30, // Keep 30 days
            $this->config['log_level']
        );
        $fileHandler->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s'
        ));
        $this->logger->pushHandler($fileHandler);
    }

    private function initializeRedis(): void
    {
        if (!$this->config['redis_enabled']) {
            return;
        }

        try {
            $redisConfig = [
                'scheme' => 'tcp',
                'host' => $this->config['redis_host'],
                'port' => $this->config['redis_port'],
            ];

            if ($this->config['redis_password']) {
                $redisConfig['password'] = $this->config['redis_password'];
            }

            $this->redis = new RedisClient($redisConfig);
            $this->redis->ping();
            
            $this->logger->info("Redis connection established");
        } catch (\Exception $e) {
            $this->logger->error("Failed to connect to Redis", ['error' => $e->getMessage()]);
            $this->redis = null;
        }
    }

    private function initializeUnixSocket(): void
    {
        // Remove existing socket file if it exists
        if (file_exists($this->unixSocketPath)) {
            if (!unlink($this->unixSocketPath)) {
                // If normal unlink fails, try different approaches
                $this->logger->warning("Failed to remove existing socket file, trying alternative methods");
                
                // Try to change permissions first
                @chmod($this->unixSocketPath, 0666);
                
                if (!@unlink($this->unixSocketPath)) {
                    // If still fails, try to use different socket path
                    $this->unixSocketPath = '/tmp/socket_pool_service_' . getmypid() . '.sock';
                    $this->logger->warning("Using alternative socket path: " . $this->unixSocketPath);
                }
            }
        }

        // Create Unix domain socket for Laravel communication
        $this->unixSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (!$this->unixSocket) {
            throw new SocketPoolException("Failed to create Unix socket: " . socket_strerror(socket_last_error()));
        }

        // Set socket options before binding
        socket_set_option($this->unixSocket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($this->unixSocket, $this->unixSocketPath)) {
            $error = socket_strerror(socket_last_error());
            
            // If bind fails, try alternative socket path
            if (strpos($error, 'Address already in use') !== false) {
                $this->unixSocketPath = '/tmp/socket_pool_service_' . getmypid() . '.sock';
                $this->logger->warning("Address in use, trying alternative path: " . $this->unixSocketPath);
                
                if (!socket_bind($this->unixSocket, $this->unixSocketPath)) {
                    throw new SocketPoolException("Failed to bind Unix socket: " . socket_strerror(socket_last_error()));
                }
            } else {
                throw new SocketPoolException("Failed to bind Unix socket: " . $error);
            }
        }

        if (!socket_listen($this->unixSocket, 5)) {
            throw new SocketPoolException("Failed to listen on Unix socket: " . socket_strerror(socket_last_error()));
        }

        // Set socket permissions
        @chmod($this->unixSocketPath, 0666);
        $this->logger->info("Unix socket initialized", ['path' => $this->unixSocketPath]);
    }

    public function run(): void
    {
        $this->logger->info("Socket Pool Service started", ['instance_id' => $this->instanceId]);
        
        // Set up signal handlers for graceful shutdown
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);

        // Start background tasks
        $this->startBackgroundTasks();

        while ($this->running) {
            try {
                // Handle incoming connections from Laravel
                $read = [$this->unixSocket];
                $write = null;
                $except = null;

                $ready = socket_select($read, $write, $except, 1);
                
                if ($ready > 0) {
                    $clientSocket = socket_accept($this->unixSocket);
                    if ($clientSocket) {
                        $this->handleClientRequest($clientSocket);
                    }
                }

                // Clean up expired connections
                $this->cleanupExpiredConnections();
                
                // Update metrics
                $this->updateMetrics();
                
                // Process signals
                pcntl_signal_dispatch();
                
            } catch (\Exception $e) {
                $this->logger->error("Error in main loop", ['error' => $e->getMessage()]);
                sleep(1); // Prevent tight loop on persistent errors
            }
        }
    }

    private function startBackgroundTasks(): void
    {
        // Register periodic tasks
        $this->schedulePeriodicTask('cleanup', 30, [$this, 'cleanupExpiredConnections']);
        $this->schedulePeriodicTask('metrics', 60, [$this, 'publishMetrics']);
        $this->schedulePeriodicTask('health_check', $this->config['health_check_interval'], [$this, 'performHealthCheck']);
    }

    private function schedulePeriodicTask(string $name, int $interval, callable $callback): void
    {
        // Simple task scheduling (can be enhanced with proper job queue)
        $lastRun = time();
        register_tick_function(function() use ($name, $interval, $callback, &$lastRun) {
            if ((time() - $lastRun) >= $interval) {
                try {
                    call_user_func($callback);
                    $lastRun = time();
                } catch (\Exception $e) {
                    $this->logger->error("Error in periodic task", [
                        'task' => $name,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });
    }

    private function handleClientRequest($clientSocket): void
    {
        $requestId = Uuid::uuid4()->toString();
        $startTime = microtime(true);
        
        try {
            $request = socket_read($clientSocket, 4096);
            if ($request === false) {
                throw new SocketPoolException("Failed to read from client socket");
            }

            $data = json_decode($request, true);
            if (!$data) {
                throw new SocketPoolException("Invalid JSON received");
            }

            $data['request_id'] = $requestId;
            $this->logger->debug("Processing request", ['request_id' => $requestId, 'action' => $data['action'] ?? 'unknown']);

            $response = $this->processRequest($data);
            $response['request_id'] = $requestId;
            $response['processing_time'] = round((microtime(true) - $startTime) * 1000, 2); // ms

            $this->sendResponse($clientSocket, $response);
            
        } catch (\Exception $e) {
            $this->logger->error("Error handling client request", [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            
            $this->sendResponse($clientSocket, [
                'success' => false,
                'error' => $e->getMessage(),
                'request_id' => $requestId
            ]);
        } finally {
            socket_close($clientSocket);
        }
    }

    private function processRequest(array $data): array
    {
        $action = $data['action'] ?? '';
        
        switch ($action) {
            case 'send_gps':
                return $this->sendGpsData($data);
            case 'get_stats':
                return $this->getConnectionStats();
            case 'get_metrics':
                return $this->getMetrics();
            case 'close_connection':
                return $this->closeConnection($data['host'] ?? '', $data['port'] ?? 0);
            case 'health_check':
                return $this->performHealthCheck();
            case 'get_config':
                return $this->getConfiguration();
            default:
                throw new SocketPoolException("Unknown action: $action");
        }
    }

    private function sendGpsData(array $data): array
    {
        $host = $data['host'] ?? '';
        $port = $data['port'] ?? 0;
        $message = $data['message'] ?? '';
        $vehicleId = $data['vehicle_id'] ?? '';
        $requestId = $data['request_id'] ?? '';

        if (empty($host) || empty($port) || empty($message)) {
            throw new SocketPoolException('Missing required parameters: host, port, message');
        }

        $this->logger->debug("Sending GPS data", [
            'request_id' => $requestId,
            'vehicle_id' => $vehicleId,
            'host' => $host,
            'port' => $port
        ]);

        try {
            $socket = $this->getOrCreateSocket($host, $port);
            $result = $this->sendMessage($socket, $message, $vehicleId);
            
            if ($result['success']) {
                $this->updateConnectionStats($host, $port, 'success');
                $this->recordMetric('gps_send_success', 1, ['host' => $host, 'port' => $port]);
            } else {
                // Try to reconnect on failure
                $this->removeFromPool($host, $port);
                $socket = $this->getOrCreateSocket($host, $port);
                $result = $this->sendMessage($socket, $message, $vehicleId);
                $status = $result['success'] ? 'success' : 'failed';
                $this->updateConnectionStats($host, $port, $status);
                $this->recordMetric('gps_send_' . $status, 1, ['host' => $host, 'port' => $port]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->updateConnectionStats($host, $port, 'failed');
            $this->recordMetric('gps_send_failed', 1, ['host' => $host, 'port' => $port]);
            $this->logger->error("Error sending GPS data", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'host' => $host,
                'port' => $port
            ]);
            throw new ConnectionException($e->getMessage());
        }
    }

    private function getOrCreateSocket(string $host, int $port)
    {
        $key = $host . ':' . $port;
        
        // Check if we have a valid connection in pool
        if (isset($this->socketPool[$key])) {
            $pooledSocket = $this->socketPool[$key];
            
            // Test if connection is still alive
            if ($this->isSocketAlive($pooledSocket['socket'])) {
                $pooledSocket['last_used'] = time();
                $pooledSocket['usage_count']++;
                $this->socketPool[$key] = $pooledSocket;
                $this->recordMetric('socket_pool_hit', 1, ['host' => $host, 'port' => $port]);
                return $pooledSocket['socket'];
            } else {
                // Remove dead connection
                $this->removeFromPool($host, $port);
                $this->recordMetric('socket_pool_expired', 1, ['host' => $host, 'port' => $port]);
            }
        }

        // Create new connection
        $socket = $this->createSocket($host, $port);
        $this->addToPool($host, $port, $socket);
        $this->recordMetric('socket_pool_miss', 1, ['host' => $host, 'port' => $port]);
        return $socket;
    }

    private function createSocket(string $host, int $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) {
            throw new ConnectionException("Could not create socket: " . socket_strerror(socket_last_error()));
        }

        // Set socket options
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 2, "usec" => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 2, "usec" => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);

        $retries = 0;
        while ($retries < $this->config['max_retries']) {
            if (socket_connect($socket, $host, $port)) {
                $this->logger->debug("Connected to server", ['host' => $host, 'port' => $port]);
                return $socket;
            }
            $retries++;
            if ($retries < $this->config['max_retries']) {
                usleep(100000); // 100ms delay between retries
            }
        }

        socket_close($socket);
        throw new ConnectionException("Could not connect to $host:$port after {$this->config['max_retries']} attempts");
    }

    private function sendMessage($socket, string $message, string $vehicleId): array
    {
        $formattedMessage = $message . "\r";
        $bytesWritten = socket_write($socket, $formattedMessage, strlen($formattedMessage));
        
        if ($bytesWritten === false) {
            throw new ConnectionException('Failed to write to socket: ' . socket_strerror(socket_last_error($socket)));
        }

        if ($bytesWritten === 0) {
            throw new ConnectionException('No bytes written to socket');
        }

        // Read response
        $response = socket_read($socket, 2048);
        if ($response === false) {
            throw new ConnectionException('Failed to read from socket: ' . socket_strerror(socket_last_error($socket)));
        }

        return [
            'success' => true,
            'response' => $response,
            'hex_response' => bin2hex($response),
            'bytes_sent' => $bytesWritten,
            'vehicle_id' => $vehicleId,
            'timestamp' => time()
        ];
    }

    private function addToPool(string $host, int $port, $socket): void
    {
        $key = $host . ':' . $port;
        
        // Check pool size limit
        if (count($this->socketPool) >= $this->config['max_pool_size']) {
            $this->cleanupOldestConnection();
        }

        $this->socketPool[$key] = [
            'socket' => $socket,
            'host' => $host,
            'port' => $port,
            'created' => time(),
            'last_used' => time(),
            'usage_count' => 1,
            'connection_id' => Uuid::uuid4()->toString()
        ];

        $this->logger->debug("Added connection to pool", ['key' => $key, 'pool_size' => count($this->socketPool)]);
    }

    private function recordMetric(string $metric, $value, array $tags = []): void
    {
        if (!$this->config['metrics_enabled']) {
            return;
        }

        $metricData = [
            'metric' => $metric,
            'value' => $value,
            'timestamp' => time(),
            'tags' => $tags,
            'instance_id' => $this->instanceId
        ];

        if ($this->redis) {
            try {
                $this->redis->lpush('socket_pool_metrics', json_encode($metricData));
                $this->redis->expire('socket_pool_metrics', 3600); // Keep for 1 hour
            } catch (\Exception $e) {
                $this->logger->warning("Failed to record metric to Redis", ['error' => $e->getMessage()]);
            }
        }
    }

    private function updateMetrics(): void
    {
        if ($this->config['metrics_enabled']) {
            $this->recordMetric('pool_size', count($this->socketPool));
            $this->recordMetric('active_connections', count($this->socketPool));
        }
    }

    private function getMetrics(): array
    {
        return [
            'pool_size' => count($this->socketPool),
            'max_pool_size' => $this->config['max_pool_size'],
            'instance_id' => $this->instanceId,
            'uptime' => time() - (int) $_SERVER['REQUEST_TIME_FLOAT'],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }

    private function publishMetrics(): void
    {
        if ($this->redis && $this->config['metrics_enabled']) {
            $metrics = $this->getMetrics();
            $this->redis->setex('socket_pool_instance_' . $this->instanceId, 300, json_encode($metrics));
        }
    }

    private function performHealthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'instance_id' => $this->instanceId,
            'timestamp' => time(),
            'checks' => []
        ];

        // Check Unix socket
        $health['checks']['unix_socket'] = file_exists($this->unixSocketPath) ? 'ok' : 'failed';

        // Check Redis connection
        if ($this->config['redis_enabled']) {
            try {
                $this->redis->ping();
                $health['checks']['redis'] = 'ok';
            } catch (\Exception $e) {
                $health['checks']['redis'] = 'failed';
                $health['status'] = 'degraded';
            }
        }

        // Check pool health
        $activeConnections = 0;
        foreach ($this->socketPool as $connection) {
            if ($this->isSocketAlive($connection['socket'])) {
                $activeConnections++;
            }
        }
        $health['checks']['active_connections'] = $activeConnections;

        return $health;
    }

    private function getConfiguration(): array
    {
        return array_merge($this->config, [
            'instance_id' => $this->instanceId,
            'current_pool_size' => count($this->socketPool)
        ]);
    }

    // ... (continue with remaining methods from original service)

public function shutdown(): void
{
    $this->running = false;
    $this->logger->info("Shutting down Socket Pool Service", ['instance_id' => $this->instanceId]);
    
    // Close all pooled connections
    foreach ($this->socketPool as $connection) {
        if ($connection['socket'] instanceof \Socket) {
            socket_shutdown($connection['socket'], 2);
            socket_close($connection['socket']);
        }
    }
    
    // Close Unix socket
    if ($this->unixSocket instanceof \Socket) {
        socket_close($this->unixSocket);
    }
    
    // Remove Unix socket file
    if (file_exists($this->unixSocketPath)) {
        unlink($this->unixSocketPath);
    }
    
    // Close Redis connection
    if ($this->redis) {
        $this->redis->disconnect();
    }
    
    $this->logger->info("Socket Pool Service shut down", ['instance_id' => $this->instanceId]);
}

    // Include remaining methods from the original service...
    private function isSocketAlive($socket): bool
    {
        if (!is_resource($socket)) {
            return false;
        }

        $read = [$socket];
        $write = null;
        $except = null;
        $result = socket_select($read, $write, $except, 0);
        
        return $result !== false;
    }

    private function removeFromPool(string $host, int $port): void
    {
        $key = $host . ':' . $port;
        if (isset($this->socketPool[$key])) {
            $socket = $this->socketPool[$key]['socket'];
            if ($socket instanceof \Socket) {
                socket_shutdown($socket, 2);
                socket_close($socket);
            }
            unset($this->socketPool[$key]);
        }
    }

    private function cleanupExpiredConnections(): void
    {
        $now = time();
        $expired = [];
        
        foreach ($this->socketPool as $key => $connection) {
            if (($now - $connection['last_used']) > $this->config['connection_timeout']) {
                $expired[] = $key;
            }
        }
        
        foreach ($expired as $key) {
            $connection = $this->socketPool[$key];
            $this->logger->debug("Cleaning up expired connection", ['key' => $key]);
            $this->removeFromPool($connection['host'], $connection['port']);
        }
    }

    private function cleanupOldestConnection(): void
    {
        $oldest = null;
        $oldestTime = PHP_INT_MAX;
        
        foreach ($this->socketPool as $key => $connection) {
            if ($connection['last_used'] < $oldestTime) {
                $oldestTime = $connection['last_used'];
                $oldest = $key;
            }
        }
        
        if ($oldest) {
            $connection = $this->socketPool[$oldest];
            $this->removeFromPool($connection['host'], $connection['port']);
        }
    }

    private function updateConnectionStats(string $host, int $port, string $status): void
    {
        $key = $host . ':' . $port;
        if (!isset($this->connectionStats[$key])) {
            $this->connectionStats[$key] = [
                'success' => 0,
                'failed' => 0,
                'total' => 0
            ];
        }
        
        $this->connectionStats[$key][$status]++;
        $this->connectionStats[$key]['total']++;
    }

    private function getConnectionStats(): array
    {
        return [
            'pool_size' => count($this->socketPool),
            'max_pool_size' => $this->config['max_pool_size'],
            'connection_stats' => $this->connectionStats,
            'active_connections' => array_keys($this->socketPool),
            'instance_id' => $this->instanceId
        ];
    }

    private function closeConnection(string $host, int $port): array
    {
        $this->removeFromPool($host, $port);
        return ['success' => true, 'message' => "Connection to $host:$port closed"];
    }

    private function sendResponse($socket, array $response): void
    {
        $json = json_encode($response);
        socket_write($socket, $json, strlen($json));
    }
}