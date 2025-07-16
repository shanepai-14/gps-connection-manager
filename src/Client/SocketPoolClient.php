<?php

declare(strict_types=1);

namespace SocketPool\Client;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Predis\Client as RedisClient;
use Ramsey\Uuid\Uuid;
use SocketPool\Exceptions\SocketPoolException;
use SocketPool\Exceptions\ConnectionException;

/**
 * Enhanced Socket Pool Client for Laravel
 * Communicates with the Socket Pool Service via Unix domain socket
 */
class SocketPoolClient
{
    private string $socketPath;
    private int $timeout;
    private Logger $logger;
    private ?RedisClient $redis = null;
    private array $config = [];
    private static ?SocketPoolClient $instance = null;

    public function __construct(string $socketPath = '/tmp/socket_pool_service.sock', int $timeout = 5)
    {
        $this->socketPath = $socketPath;
        $this->timeout = $timeout;
        $this->loadConfiguration();
        $this->initializeLogger();
        $this->initializeRedis();
    }

    public static function getInstance(): SocketPoolClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration(): void
    {
        $this->config = [
            'cache_enabled' => env('SOCKET_POOL_CACHE_ENABLED', true),
            'cache_ttl' => (int) env('SOCKET_POOL_CACHE_TTL', 300),
            'retry_attempts' => (int) env('SOCKET_POOL_RETRY_ATTEMPTS', 3),
            'retry_delay' => (int) env('SOCKET_POOL_RETRY_DELAY', 100), // milliseconds
            'circuit_breaker_enabled' => env('SOCKET_POOL_CIRCUIT_BREAKER', true),
            'circuit_breaker_threshold' => (int) env('SOCKET_POOL_CB_THRESHOLD', 5),
            'circuit_breaker_timeout' => (int) env('SOCKET_POOL_CB_TIMEOUT', 60),
            'metrics_enabled' => env('SOCKET_POOL_METRICS_ENABLED', true),
        ];
    }

    private function initializeLogger(): void
    {
        $this->logger = new Logger('socket_pool_client');
        
        if (function_exists('config') && config('logging.channels.socket_pool')) {
            // Use Laravel's logging configuration if available
            $logPath = storage_path('logs/socket_pool_client.log');
        } else {
            $logPath = '/var/log/socket_pool_client.log';
        }
        
        $handler = new StreamHandler($logPath, Logger::INFO);
        $this->logger->pushHandler($handler);
    }

    private function initializeRedis(): void
    {
        if (!$this->config['cache_enabled']) {
            return;
        }

        try {
            // Try to use Laravel's Redis configuration if available
            if (function_exists('config') && config('database.redis.default')) {
                $redisConfig = config('database.redis.default');
                $this->redis = new RedisClient([
                    'scheme' => 'tcp',
                    'host' => $redisConfig['host'],
                    'port' => $redisConfig['port'],
                    'password' => $redisConfig['password'] ?? null,
                    'database' => $redisConfig['database'] ?? 0,
                ]);
            } else {
                // Fallback to environment variables
                $this->redis = new RedisClient([
                    'scheme' => 'tcp',
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => env('REDIS_PASSWORD'),
                ]);
            }
            
            $this->redis->ping();
            $this->logger->info("Redis connection established for client caching");
            
        } catch (\Exception $e) {
            $this->logger->warning("Failed to connect to Redis for caching", ['error' => $e->getMessage()]);
            $this->redis = null;
        }
    }

    /**
     * Send GPS data using the socket pool service with enhanced features
     */
    public function sendGpsData(string $gpsData, string $host, int $port, string $vehicleId, array $options = []): array
    {
        $requestId = Uuid::uuid4()->toString();
        $startTime = microtime(true);
        
        $this->logger->debug("Sending GPS data", [
            'request_id' => $requestId,
            'vehicle_id' => $vehicleId,
            'host' => $host,
            'port' => $port
        ]);

        try {
            // Check circuit breaker
            if ($this->isCircuitBreakerOpen($host, $port)) {
                throw new SocketPoolException("Circuit breaker is open for $host:$port");
            }

            // Check cache for recent similar requests (optional optimization)
            $cacheKey = $this->getCacheKey('gps_send', $host, $port, $gpsData);
            if ($this->config['cache_enabled'] && isset($options['use_cache']) && $options['use_cache']) {
                $cachedResult = $this->getFromCache($cacheKey);
                if ($cachedResult) {
                    $this->logger->debug("Returning cached GPS result", ['request_id' => $requestId]);
                    return $cachedResult;
                }
            }

            $request = [
                'action' => 'send_gps',
                'message' => $gpsData,
                'host' => $host,
                'port' => $port,
                'vehicle_id' => $vehicleId,
                'request_id' => $requestId,
                'options' => $options
            ];

            $result = $this->sendRequestWithRetry($request);
            
            // Cache successful results if enabled
            if ($result['success'] && $this->config['cache_enabled'] && isset($options['use_cache']) && $options['use_cache']) {
                $this->setCache($cacheKey, $result, $this->config['cache_ttl']);
            }

            // Record metrics
            $this->recordMetric('gps_send', [
                'success' => $result['success'],
                'host' => $host,
                'port' => $port,
                'duration' => (microtime(true) - $startTime) * 1000,
                'vehicle_id' => $vehicleId
            ]);

            // Update circuit breaker
            $this->updateCircuitBreaker($host, $port, $result['success']);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Error sending GPS data", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'host' => $host,
                'port' => $port
            ]);

            // Update circuit breaker on failure
            $this->updateCircuitBreaker($host, $port, false);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'request_id' => $requestId,
                'duration' => (microtime(true) - $startTime) * 1000
            ];
        }
    }

    /**
     * Batch send GPS data for multiple vehicles
     */
    public function batchSendGpsData(array $gpsDataArray, array $options = []): array
    {
        $batchId = Uuid::uuid4()->toString();
        $startTime = microtime(true);
        $results = [];
        
        $this->logger->info("Starting batch GPS send", [
            'batch_id' => $batchId,
            'count' => count($gpsDataArray)
        ]);

        $concurrent = $options['concurrent'] ?? false;
        $maxConcurrency = $options['max_concurrency'] ?? 10;

        if ($concurrent && extension_loaded('pcntl')) {
            $results = $this->batchSendConcurrent($gpsDataArray, $maxConcurrency, $batchId);
        } else {
            $results = $this->batchSendSequential($gpsDataArray, $batchId);
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $successful = count(array_filter($results, fn($r) => $r['success']));
        $failed = count($results) - $successful;

        $this->logger->info("Batch GPS send completed", [
            'batch_id' => $batchId,
            'total' => count($results),
            'successful' => $successful,
            'failed' => $failed,
            'duration' => $duration
        ]);

        return [
            'batch_id' => $batchId,
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'successful' => $successful,
                'failed' => $failed,
                'duration' => $duration
            ]
        ];
    }

    private function batchSendSequential(array $gpsDataArray, string $batchId): array
    {
        $results = [];
        
        foreach ($gpsDataArray as $index => $data) {
            $result = $this->sendGpsData(
                $data['gps_data'] ?? '',
                $data['host'] ?? '',
                $data['port'] ?? 0,
                $data['vehicle_id'] ?? '',
                $data['options'] ?? []
            );
            
            $result['batch_id'] = $batchId;
            $result['batch_index'] = $index;
            $results[] = $result;
        }
        
        return $results;
    }

    private function batchSendConcurrent(array $gpsDataArray, int $maxConcurrency, string $batchId): array
    {
        $results = [];
        $chunks = array_chunk($gpsDataArray, $maxConcurrency);
        
        foreach ($chunks as $chunk) {
            $pids = [];
            $pipes = [];
            
            foreach ($chunk as $index => $data) {
                $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                
                if ($pid == -1) {
                    throw new SocketPoolException("Could not fork process for concurrent execution");
                } elseif ($pid) {
                    // Parent process
                    fclose($pipe[1]);
                    $pids[$pid] = $pipe[0];
                    $pipes[$pid] = $index;
                } else {
                    // Child process
                    fclose($pipe[0]);
                    
                    $result = $this->sendGpsData(
                        $data['gps_data'] ?? '',
                        $data['host'] ?? '',
                        $data['port'] ?? 0,
                        $data['vehicle_id'] ?? '',
                        $data['options'] ?? []
                    );
                    
                    fwrite($pipe[1], json_encode($result));
                    fclose($pipe[1]);
                    exit(0);
                }
            }
            
            // Collect results from child processes
            foreach ($pids as $pid => $pipe) {
                pcntl_waitpid($pid, $status);
                $resultJson = stream_get_contents($pipe);
                fclose($pipe);
                
                $result = json_decode($resultJson, true);
                $result['batch_id'] = $batchId;
                $result['batch_index'] = $pipes[$pid];
                $results[] = $result;
            }
        }
        
        return $results;
    }

    /**
     * Get connection statistics from the socket pool service
     */
    public function getConnectionStats(): array
    {
        $request = ['action' => 'get_stats'];
        return $this->sendRequest($request);
    }

    /**
     * Get service metrics
     */
    public function getMetrics(): array
    {
        $request = ['action' => 'get_metrics'];
        return $this->sendRequest($request);
    }

    /**
     * Close a specific connection in the pool
     */
    public function closeConnection(string $host, int $port): array
    {
        $request = [
            'action' => 'close_connection',
            'host' => $host,
            'port' => $port
        ];
        return $this->sendRequest($request);
    }

    /**
     * Perform health check on the service
     */
    public function performHealthCheck(): array
    {
        $request = ['action' => 'health_check'];
        return $this->sendRequest($request);
    }

    /**
     * Get service configuration
     */
    public function getConfiguration(): array
    {
        $request = ['action' => 'get_config'];
        return $this->sendRequest($request);
    }

    /**
     * Send request with retry logic
     */
    private function sendRequestWithRetry(array $request): array
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->config['retry_attempts']; $attempt++) {
            try {
                $result = $this->sendRequest($request);
                
                if ($result['success']) {
                    return $result;
                }
                
                // If not successful but no exception, treat as retriable
                if ($attempt < $this->config['retry_attempts']) {
                    $this->logger->warning("Request failed, retrying", [
                        'attempt' => $attempt,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                    usleep($this->config['retry_delay'] * 1000 * $attempt); // Exponential backoff
                    continue;
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt < $this->config['retry_attempts']) {
                    $this->logger->warning("Request exception, retrying", [
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                    usleep($this->config['retry_delay'] * 1000 * $attempt);
                } else {
                    throw $e;
                }
            }
        }
        
        if ($lastException) {
            throw $lastException;
        }
        
        return ['success' => false, 'error' => 'Max retry attempts exceeded'];
    }

    /**
     * Send request to the socket pool service
     */
    private function sendRequest(array $request): array
    {
        $socket = null;
        
        try {
            // Create Unix domain socket
            $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            if (!$socket) {
                throw new SocketPoolException("Failed to create socket: " . socket_strerror(socket_last_error()));
            }

            // Set timeout
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => $this->timeout, "usec" => 0]);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => $this->timeout, "usec" => 0]);

            // Connect to service
            if (!socket_connect($socket, $this->socketPath)) {
                throw new ConnectionException("Failed to connect to socket service: " . socket_strerror(socket_last_error()));
            }

            // Send request
            $requestJson = json_encode($request);
            $bytesWritten = socket_write($socket, $requestJson, strlen($requestJson));
            
            if ($bytesWritten === false) {
                throw new ConnectionException("Failed to write to socket: " . socket_strerror(socket_last_error()));
            }

            // Read response
            $response = socket_read($socket, 8192); // Increased buffer size
            if ($response === false) {
                throw new ConnectionException("Failed to read from socket: " . socket_strerror(socket_last_error()));
            }

            $decodedResponse = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SocketPoolException("Invalid JSON response: " . json_last_error_msg());
            }

            return $decodedResponse;

        } catch (\Exception $e) {
            $this->logger->error("Socket Pool Client Error: " . $e->getMessage());
            throw $e;
        } finally {
            if ($socket && is_resource($socket)) {
                socket_close($socket);
            }
        }
    }

    /**
     * Check if the socket pool service is running
     */
    public function isServiceRunning(): bool
    {
        if (!file_exists($this->socketPath)) {
            return false;
        }

        try {
            $health = $this->performHealthCheck();
            return $health['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->debug("Service health check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Circuit breaker implementation
     */
    private function isCircuitBreakerOpen(string $host, int $port): bool
    {
        if (!$this->config['circuit_breaker_enabled'] || !$this->redis) {
            return false;
        }

        $key = $this->getCircuitBreakerKey($host, $port);
        $state = $this->redis->hgetall($key);
        
        if (empty($state)) {
            return false;
        }

        $failures = (int) ($state['failures'] ?? 0);
        $lastFailure = (int) ($state['last_failure'] ?? 0);
        $isOpen = ($state['state'] ?? 'closed') === 'open';

        if ($isOpen) {
            // Check if we should transition to half-open
            if ((time() - $lastFailure) > $this->config['circuit_breaker_timeout']) {
                $this->redis->hset($key, 'state', 'half-open');
                return false; // Allow one request to test
            }
            return true;
        }

        return $failures >= $this->config['circuit_breaker_threshold'];
    }

    private function updateCircuitBreaker(string $host, int $port, bool $success): void
    {
        if (!$this->config['circuit_breaker_enabled'] || !$this->redis) {
            return;
        }

        $key = $this->getCircuitBreakerKey($host, $port);
        
        if ($success) {
            // Reset circuit breaker on success
            $this->redis->del($key);
        } else {
            // Increment failure count
            $this->redis->hincrby($key, 'failures', 1);
            $this->redis->hset($key, 'last_failure', time());
            
            $failures = $this->redis->hget($key, 'failures');
            if ($failures >= $this->config['circuit_breaker_threshold']) {
                $this->redis->hset($key, 'state', 'open');
            }
            
            $this->redis->expire($key, 3600); // Expire after 1 hour
        }
    }

    private function getCircuitBreakerKey(string $host, int $port): string
    {
        return "socket_pool_circuit_breaker:{$host}:{$port}";
    }

    /**
     * Caching methods
     */
    private function getCacheKey(string $action, string $host, int $port, string $data = ''): string
    {
        return "socket_pool_cache:{$action}:{$host}:{$port}:" . md5($data);
    }

    private function getFromCache(string $key): ?array
    {
        if (!$this->redis) {
            return null;
        }

        try {
            $cached = $this->redis->get($key);
            return $cached ? json_decode($cached, true) : null;
        } catch (\Exception $e) {
            $this->logger->warning("Cache read failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function setCache(string $key, array $data, int $ttl): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->setex($key, $ttl, json_encode($data));
        } catch (\Exception $e) {
            $this->logger->warning("Cache write failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Metrics recording
     */
    private function recordMetric(string $action, array $data): void
    {
        if (!$this->config['metrics_enabled']) {
            return;
        }

        $metric = [
            'action' => $action,
            'timestamp' => time(),
            'data' => $data,
            'client_id' => gethostname()
        ];

        if ($this->redis) {
            try {
                $this->redis->lpush('socket_pool_client_metrics', json_encode($metric));
                $this->redis->ltrim('socket_pool_client_metrics', 0, 999); // Keep last 1000 metrics
            } catch (\Exception $e) {
                $this->logger->warning("Metrics recording failed", ['error' => $e->getMessage()]);
            }
        }

        $this->logger->debug("Metric recorded", $metric);
    }

    /**
     * Get client metrics
     */
    public function getClientMetrics(): array
    {
        if (!$this->redis) {
            return ['error' => 'Redis not available for metrics'];
        }

        try {
            $metrics = $this->redis->lrange('socket_pool_client_metrics', 0, -1);
            return [
                'success' => true,
                'metrics' => array_map('json_decode', $metrics),
                'count' => count($metrics)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to get metrics: ' . $e->getMessage()];
        }
    }

    /**
     * Connection pool management
     */
    public function warmUpConnections(array $hostPorts): array
    {
        $results = [];
        
        foreach ($hostPorts as $hostPort) {
            $host = $hostPort['host'] ?? '';
            $port = $hostPort['port'] ?? 0;
            
            if (empty($host) || empty($port)) {
                continue;
            }
            
            try {
                // Send a simple test message to warm up the connection
                $result = $this->sendGpsData('TEST', $host, $port, 'WARMUP', ['warm_up' => true]);
                $results[] = [
                    'host' => $host,
                    'port' => $port,
                    'success' => $result['success'],
                    'error' => $result['error'] ?? null
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'host' => $host,
                    'port' => $port,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Async request (requires ReactPHP - optional enhancement)
     */
    public function sendGpsDataAsync(string $gpsData, string $host, int $port, string $vehicleId): \React\Promise\PromiseInterface
    {
        // This would require ReactPHP integration
        // For now, return a resolved promise with sync result
        return \React\Promise\resolve($this->sendGpsData($gpsData, $host, $port, $vehicleId));
    }

    /**
     * Bulk operations
     */
    public function bulkCloseConnections(array $hostPorts): array
    {
        $results = [];
        
        foreach ($hostPorts as $hostPort) {
            $host = $hostPort['host'] ?? '';
            $port = $hostPort['port'] ?? 0;
            
            if (empty($host) || empty($port)) {
                continue;
            }
            
            try {
                $result = $this->closeConnection($host, $port);
                $results[] = array_merge($result, ['host' => $host, 'port' => $port]);
            } catch (\Exception $e) {
                $results[] = [
                    'host' => $host,
                    'port' => $port,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Connection testing
     */
    public function testConnection(string $host, int $port): array
    {
        $startTime = microtime(true);
        
        try {
            $result = $this->sendGpsData('PING', $host, $port, 'TEST_CONNECTION', ['test_mode' => true]);
            $duration = (microtime(true) - $startTime) * 1000;
            
            return [
                'success' => $result['success'],
                'host' => $host,
                'port' => $port,
                'response_time' => round($duration, 2),
                'response' => $result['response'] ?? null,
                'error' => $result['error'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'host' => $host,
                'port' => $port,
                'response_time' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get service version and info
     */
    public function getServiceInfo(): array
    {
        try {
            $config = $this->getConfiguration();
            $health = $this->performHealthCheck();
            $stats = $this->getConnectionStats();
            
            return [
                'success' => true,
                'service_name' => 'Socket Pool Service',
                'version' => '1.0.0',
                'healthy' => $health['success'] ?? false,
                'pool_size' => $stats['data']['pool_size'] ?? 0,
                'instance_id' => $config['data']['instance_id'] ?? 'unknown',
                'uptime' => $config['data']['uptime'] ?? 0
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}