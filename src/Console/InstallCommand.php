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

class InstallCommand extends Command
{
    protected static $defaultName = 'install';
    protected static $defaultDescription = 'Install Socket Pool Service';

    protected function configure(): void
    {
        $this
            ->setDescription('Install Socket Pool Service as system service')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User to run service as', 'www-data')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Group to run service as', 'www-data')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reinstallation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        if (posix_getuid() !== 0) {
            $io->error('This command must be run as root');
            return Command::FAILURE;
        }
        
        $user = $input->getOption('user');
        $group = $input->getOption('group');
        $force = $input->getOption('force');
        $servicePath = realpath(__DIR__ . '/../../');
        
        $io->title('Installing Socket Pool Service');
        
        // Check if already installed
        if (file_exists('/etc/systemd/system/socket-pool.service') && !$force) {
            $io->warning('Service is already installed. Use --force to reinstall.');
            return Command::FAILURE;
        }
        
        try {
            $io->section('Creating directories');
            $this->createDirectories($io, $user, $group);
            
            $io->section('Installing systemd service');
            $this->installSystemdService($io, $servicePath, $user, $group);
            
            $io->section('Setting up configuration');
            $this->setupConfiguration($io);
            
            $io->section('Installing log rotation');
            $this->installLogrotate($io, $user, $group);
            
            $io->section('Setting up monitoring');
            $this->setupMonitoring($io, $servicePath, $user, $group);
            
            $io->success('Socket Pool Service installed successfully!');
            $io->info([
                'You can now start the service with:',
                '  systemctl start socket-pool',
                '',
                'Enable auto-start on boot:',
                '  systemctl enable socket-pool'
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Installation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function createDirectories(SymfonyStyle $io, string $user, string $group): void
    {
        $directories = [
            '/var/log/socket-pool' => '755',
            '/var/run/socket-pool' => '755',
            '/etc/socket-pool' => '755'
        ];
        
        foreach ($directories as $dir => $permissions) {
            if (!is_dir($dir)) {
                mkdir($dir, octdec($permissions), true);
                chown($dir, $user);
                chgrp($dir, $group);
                $io->info("Created directory: $dir");
            }
        }
    }

    private function installSystemdService(SymfonyStyle $io, string $servicePath, string $user, string $group): void
    {
        $serviceContent = $this->generateSystemdService($servicePath, $user, $group);
        file_put_contents('/etc/systemd/system/socket-pool.service', $serviceContent);
        
        $envContent = $this->generateEnvironmentFile();
        file_put_contents('/etc/default/socket-pool', $envContent);
        
        exec('systemctl daemon-reload');
        exec('systemctl enable socket-pool.service');
        
        $io->info('Systemd service installed and enabled');
    }

    private function setupConfiguration(SymfonyStyle $io): void
    {
        // Copy default configuration if it doesn't exist
        $configSource = __DIR__ . '/../../.env.example';
        $configTarget = '/etc/socket-pool/socket-pool.env';
        
        if (file_exists($configSource) && !file_exists($configTarget)) {
            copy($configSource, $configTarget);
            $io->info("Configuration template copied to: $configTarget");
        }
    }

    private function installLogrotate(SymfonyStyle $io, string $user, string $group): void
    {
        $logrotateContent = <<<EOF
/var/log/socket-pool/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 644 $user $group
    sharedscripts
    postrotate
        systemctl reload socket-pool > /dev/null 2>&1 || true
    endscript
}
EOF;
        
        file_put_contents('/etc/logrotate.d/socket-pool', $logrotateContent);
        $io->info('Log rotation configured');
    }

    private function setupMonitoring(SymfonyStyle $io, string $servicePath, string $user, string $group): void
    {
        // Create monitoring service
        $monitoringService = <<<EOF
[Unit]
Description=Socket Pool Health Monitor
After=socket-pool.service
Requires=socket-pool.service

[Service]
Type=simple
User=$user
Group=$group
ExecStart=$servicePath/bin/socket-pool monitor
Restart=always
RestartSec=30
StandardOutput=append:/var/log/socket-pool/monitor.log
StandardError=append:/var/log/socket-pool/monitor.log

[Install]
WantedBy=multi-user.target
EOF;
        
        file_put_contents('/etc/systemd/system/socket-pool-monitor.service', $monitoringService);
        exec('systemctl daemon-reload');
        exec('systemctl enable socket-pool-monitor.service');
        
        $io->info('Monitoring service installed');
    }

    private function generateSystemdService(string $servicePath, string $user, string $group): string
    {
        $pidFile = '/var/run/socket-pool/socket-pool.pid';
        $logFile = '/var/log/socket-pool/service.log';
        $socketPoolBinary = $servicePath . '/bin/socket-pool';
        
        return <<<EOF
[Unit]
Description=Socket Pool Microservice - High Performance TCP Connection Pool
Documentation=https://github.com/your-org/socket-pool-service
After=network.target network-online.target redis.service
Wants=network-online.target
RequiresMountsFor=/var/log/socket-pool /var/run/socket-pool

[Service]
Type=forking
User=$user
Group=$group
WorkingDirectory=$servicePath
Environment=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
EnvironmentFile=-/etc/default/socket-pool
EnvironmentFile=-/etc/socket-pool/socket-pool.env

# Service execution
ExecStartPre=/bin/mkdir -p /var/run/socket-pool /var/log/socket-pool
ExecStartPre=/bin/chown $user:$group /var/run/socket-pool /var/log/socket-pool
ExecStart=$socketPoolBinary start --daemon --pid-file=$pidFile
ExecStop=$socketPoolBinary stop --pid-file=$pidFile --timeout 15
ExecReload=$socketPoolBinary restart --pid-file=$pidFile
ExecStartPost=/bin/sleep 2
ExecStartPost=/bin/bash -c 'if [ -f $pidFile ]; then echo "Service started with PID: \$(cat $pidFile)"; fi'

# PID and process management
PIDFile=$pidFile
KillMode=mixed
KillSignal=SIGTERM
TimeoutStartSec=30
TimeoutStopSec=30
TimeoutReloadSec=20
Restart=always
RestartSec=10
StartLimitInterval=60
StartLimitBurst=3

# Resource limits
LimitNOFILE=65536
LimitNPROC=4096
LimitCORE=0
LimitMEMLOCK=64K

# Logging
StandardOutput=append:$logFile
StandardError=append:$logFile
SyslogIdentifier=socket-pool

# Security and isolation settings
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictRealtime=yes
RestrictNamespaces=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
RemoveIPC=yes

# File system access
ReadWritePaths=/var/log/socket-pool /var/run/socket-pool /tmp
ReadOnlyPaths=/etc/socket-pool
BindReadOnlyPaths=/etc/passwd /etc/group

# Network and capabilities
PrivateNetwork=no
CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_DAC_OVERRIDE
AmbientCapabilities=

# Additional hardening
ProtectHostname=yes
ProtectClock=yes
SystemCallFilter=@system-service
SystemCallFilter=~@debug @mount @cpu-emulation @obsolete @privileged @reboot @swap
SystemCallErrorNumber=EPERM

[Install]
WantedBy=multi-user.target
Alias=socket-pool.service
EOF;
    }

    private function generateEnvironmentFile(): string
    {
        return <<<EOF
# Socket Pool Service Environment Configuration
# This file is sourced by systemd and contains environment variables for the service

# =============================================================================
# CORE SERVICE CONFIGURATION
# =============================================================================

# Pool size configuration
SOCKET_POOL_MAX_SIZE=100
SOCKET_POOL_TIMEOUT=30
SOCKET_POOL_MAX_RETRIES=3
SOCKET_POOL_CONNECTION_TTL=300

# Service paths
SOCKET_POOL_UNIX_PATH=/tmp/socket_pool_service.sock
SOCKET_POOL_LOG_FILE=/var/log/socket-pool/service.log
SOCKET_POOL_PID_FILE=/var/run/socket-pool/socket-pool.pid

# =============================================================================
# LOGGING CONFIGURATION
# =============================================================================

# Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL
SOCKET_POOL_LOG_LEVEL=INFO
SOCKET_POOL_LOG_FORMAT=json
SOCKET_POOL_LOG_MAX_FILES=30
SOCKET_POOL_SLOW_QUERY_LOG=true
SOCKET_POOL_SLOW_QUERY_THRESHOLD=1000

# =============================================================================
# REDIS CONFIGURATION (OPTIONAL)
# =============================================================================

# Enable Redis for caching, metrics, and circuit breaker
SOCKET_POOL_REDIS_ENABLED=false
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
REDIS_TIMEOUT=5

# =============================================================================
# PERFORMANCE AND MONITORING
# =============================================================================

# Metrics and health monitoring
SOCKET_POOL_METRICS_ENABLED=true
SOCKET_POOL_HEALTH_INTERVAL=60
SOCKET_POOL_STATS_RETENTION=3600

# Circuit breaker configuration
SOCKET_POOL_CIRCUIT_BREAKER=true
SOCKET_POOL_CB_THRESHOLD=5
SOCKET_POOL_CB_TIMEOUT=60
SOCKET_POOL_CB_HALF_OPEN_MAX_CALLS=3

# Connection management
SOCKET_POOL_CLEANUP_INTERVAL=30
SOCKET_POOL_IDLE_TIMEOUT=300
SOCKET_POOL_MAX_IDLE_TIME=600

# =============================================================================
# CLIENT CONFIGURATION
# =============================================================================

# Client-side settings
SOCKET_POOL_CLIENT_TIMEOUT=5
SOCKET_POOL_CLIENT_RETRIES=3
SOCKET_POOL_CLIENT_RETRY_DELAY=100
SOCKET_POOL_CACHE_ENABLED=true
SOCKET_POOL_CACHE_TTL=300

# =============================================================================
# SECURITY CONFIGURATION
# =============================================================================

# Authentication (if enabled)
SOCKET_POOL_AUTH_ENABLED=false
SOCKET_POOL_AUTH_TOKEN=
SOCKET_POOL_SSL_ENABLED=false
SOCKET_POOL_SSL_CERT_PATH=
SOCKET_POOL_SSL_KEY_PATH=

# =============================================================================
# ADVANCED CONFIGURATION
# =============================================================================

# Memory and resource management
SOCKET_POOL_MEMORY_LIMIT=256M
SOCKET_POOL_GC_ENABLED=true
SOCKET_POOL_GC_INTERVAL=300
SOCKET_POOL_MAX_EXECUTION_TIME=0

# Worker processes (if using multi-process mode)
SOCKET_POOL_WORKER_PROCESSES=1
SOCKET_POOL_MAX_REQUESTS_PER_CHILD=1000

# Development and debugging
SOCKET_POOL_DEBUG=false
SOCKET_POOL_PROFILE=false
SOCKET_POOL_XDEBUG_ENABLED=false

# =============================================================================
# ALERTS AND NOTIFICATIONS
# =============================================================================

# Email alerts (optional)
SOCKET_POOL_ALERT_EMAIL_ENABLED=false
SOCKET_POOL_ALERT_EMAIL=
SOCKET_POOL_SMTP_HOST=
SOCKET_POOL_SMTP_PORT=587
SOCKET_POOL_SMTP_USER=
SOCKET_POOL_SMTP_PASS=

# Webhook notifications (optional)
SOCKET_POOL_WEBHOOK_ENABLED=false
SOCKET_POOL_WEBHOOK_URL=
SOCKET_POOL_WEBHOOK_TOKEN=

# =============================================================================
# CUSTOM CONFIGURATION
# =============================================================================

# Add your custom environment variables below
# CUSTOM_VARIABLE=value
EOF;
    }
}

