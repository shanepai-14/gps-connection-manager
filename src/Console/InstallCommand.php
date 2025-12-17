<?php

declare(strict_types=1);

namespace SocketPool\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reinstallation')
            ->addOption('no-systemd', null, InputOption::VALUE_NONE, 'Skip systemd installation');
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
        $noSystemd = $input->getOption('no-systemd');
        $servicePath = realpath(__DIR__ . '/../../');
        
        $io->title('Installing Socket Pool Service');
        $io->info("Service path: $servicePath");
        
        // Check if already installed
        if (file_exists('/etc/systemd/system/socket-pool.service') && !$force) {
            $io->warning('Service is already installed. Use --force to reinstall.');
            return Command::FAILURE;
        }
        
        try {
            $io->section('Creating directories');
            $this->createDirectories($io, $user, $group, $servicePath);
            
            if (!$noSystemd) {
                $io->section('Installing systemd service');
                $this->installSystemdService($io, $servicePath, $user, $group);
            } else {
                $io->info('Skipping systemd installation');
            }
            
            $io->section('Setting up configuration');
            $this->setupConfiguration($io, $servicePath);
            
            $io->section('Installing log rotation');
            $this->installLogrotate($io, $user, $group, $servicePath);
            
            $io->section('Setting up project permissions');
            $this->setupProjectPermissions($io, $servicePath, $user, $group);
            
            $io->success('Socket Pool Service installed successfully!');
            
            if (!$noSystemd) {
                $io->info([
                    'You can now start the service with:',
                    '  systemctl start socket-pool',
                    '',
                    'Enable auto-start on boot:',
                    '  systemctl enable socket-pool'
                ]);
            } else {
                $io->info([
                    'To start the service manually:',
                    '  ./bin/socket-pool start',
                    '',
                    'To start in background:',
                    '  nohup ./bin/socket-pool start > logs/socket-pool.log 2>&1 &'
                ]);
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Installation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function createDirectories(SymfonyStyle $io, string $user, string $group, string $servicePath): void
    {
        $directories = [
            "$servicePath/logs" => '755',
            '/var/run/socket-pool' => '755', // Keep this for future use
        ];
        
        foreach ($directories as $dir => $permissions) {
            if (!is_dir($dir)) {
                mkdir($dir, octdec($permissions), true);
                $io->info("Created directory: $dir");
            }
            
            // Set ownership
            chown($dir, $user);
            chgrp($dir, $group);
            chmod($dir, octdec($permissions));
        }
    }

    private function setupProjectPermissions(SymfonyStyle $io, string $servicePath, string $user, string $group): void
    {
        $io->info('Setting up project permissions for www-data');
        
        // Make parent directories accessible
        $homeDir = dirname($servicePath);
        $userDir = dirname($homeDir);
        
        chmod($userDir, 0755);
        chmod($homeDir, 0755);
        chmod($servicePath, 0755);
        
        // Set group ownership for the project
        exec("chgrp -R $group $servicePath");
        exec("chmod -R g+r $servicePath");
        exec("chmod g+x $servicePath");
        
        // Ensure logs directory is writable
        exec("chown -R $user:$group $servicePath/logs");
        exec("chmod 755 $servicePath/logs");
        
        // Make binary executable
        chmod("$servicePath/bin/socket-pool", 0755);
        
        $io->info('Project permissions configured');
    }

    private function installSystemdService(SymfonyStyle $io, string $servicePath, string $user, string $group): void
    {
        $serviceContent = $this->generateSystemdService($servicePath, $user, $group);
        file_put_contents('/etc/systemd/system/socket-pool.service', $serviceContent);
        
        $envContent = $this->generateEnvironmentFile($servicePath);
        file_put_contents('/etc/default/socket-pool', $envContent);
        
        exec('systemctl daemon-reload');
        exec('systemctl enable socket-pool.service');
        
        $io->info('Systemd service installed and enabled');
    }

    private function setupConfiguration(SymfonyStyle $io, string $servicePath): void
    {
        // Ensure .env file exists in the project
        if (!file_exists("$servicePath/.env")) {
            if (file_exists("$servicePath/.env.example")) {
                copy("$servicePath/.env.example", "$servicePath/.env");
                $io->info("Created .env file from .env.example");
            }
        }
        
        // Update .env to use project logs
        $envContent = file_get_contents("$servicePath/.env");
        $envContent = preg_replace('/SOCKET_POOL_LOG_FILE=.*/', "SOCKET_POOL_LOG_FILE=$servicePath/logs/socket_pool_service.log", $envContent);
        file_put_contents("$servicePath/.env", $envContent);
        
        $io->info("Updated .env file to use project logs");
    }

    private function installLogrotate(SymfonyStyle $io, string $user, string $group, string $servicePath): void
    {
        $logrotateContent = <<<EOF
$servicePath/logs/*.log {
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
        $io->info('Log rotation configured for project logs');
    }

    private function generateSystemdService(string $servicePath, string $user, string $group): string
    {
        $socketPoolBinary = $servicePath . '/bin/socket-pool';
        
        return <<<EOF
[Unit]
Description=Socket Pool Service
After=network.target

[Service]
Type=simple
User=$user
Group=$group
WorkingDirectory=$servicePath
PermissionsStartOnly=true
Environment=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
EnvironmentFile=-/etc/default/socket-pool
EnvironmentFile=-$servicePath/.env

# Service execution
ExecStartPre=/bin/rm -f /var/run/socket-pool/socket_pool_service.sock
ExecStartPre=/bin/mkdir -p /var/run/socket-pool $servicePath/logs
ExecStartPre=/bin/chown $user:$group /var/run/socket-pool $servicePath/logs
ExecStartPre=/bin/touch /var/run/socket-pool/socket_pool_service.sock
ExecStartPre=/bin/chown $user:$group /var/run/socket-pool/socket_pool_service.sock
ExecStart=/usr/bin/php $socketPoolBinary start
ExecStop=/bin/kill -TERM \$MAINPID

# Restart policy
Restart=on-failure
RestartSec=5
StartLimitInterval=60
StartLimitBurst=3

# Timeouts
TimeoutStartSec=30
TimeoutStopSec=15

# Resource limits
LimitNOFILE=65536

# Logging
StandardOutput=append:$servicePath/logs/systemd.log
StandardError=append:$servicePath/logs/systemd.log
SyslogIdentifier=socket-pool

[Install]
WantedBy=multi-user.target
EOF;
    }

    private function generateEnvironmentFile(string $servicePath): string
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

# Service paths (using project directory)
SOCKET_POOL_UNIX_PATH=/var/run/socket-pool/socket_pool_service.sock
SOCKET_POOL_LOG_FILE=$servicePath/logs/socket_pool_service.log

# Logging configuration
SOCKET_POOL_LOG_LEVEL=INFO

# Redis configuration (optional)
SOCKET_POOL_REDIS_ENABLED=false
SOCKET_POOL_REDIS_HOST=127.0.0.1
SOCKET_POOL_REDIS_PORT=6379
SOCKET_POOL_REDIS_PASSWORD=

# Metrics and monitoring
SOCKET_POOL_METRICS_ENABLED=true
SOCKET_POOL_HEALTH_CHECK_INTERVAL=60

# Advanced configuration
SOCKET_POOL_MAX_CONNECTIONS_PER_HOST=10
SOCKET_POOL_CLEANUP_INTERVAL=300
SOCKET_POOL_SOCKET_REUSE=true
EOF;
    }
}
