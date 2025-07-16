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


class ConfigCommand extends Command
{
    protected static $defaultName = 'config';
    protected static $defaultDescription = 'Manage service configuration';

    protected function configure(): void
    {
        $this
            ->setDescription('Manage service configuration')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: show|set|get|validate')
            ->addArgument('key', InputArgument::OPTIONAL, 'Configuration key')
            ->addArgument('value', InputArgument::OPTIONAL, 'Configuration value')
            ->addOption('config-file', 'c', InputOption::VALUE_REQUIRED, 'Configuration file path', '.env');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $key = $input->getArgument('key');
        $value = $input->getArgument('value');
        $configFile = $input->getOption('config-file');

        switch ($action) {
            case 'show':
                return $this->showConfig($io, $configFile);
            case 'get':
                return $this->getConfig($io, $configFile, $key);
            case 'set':
                return $this->setConfig($io, $configFile, $key, $value);
            case 'validate':
                return $this->validateConfig($io, $configFile);
            default:
                $io->error("Unknown action: $action");
                return Command::FAILURE;
        }
    }

    private function showConfig(SymfonyStyle $io, string $configFile): int
    {
        if (!file_exists($configFile)) {
            $io->error("Configuration file not found: $configFile");
            return Command::FAILURE;
        }

        $io->title('Current Configuration');
        
        $config = $this->parseEnvFile($configFile);
        
        $table = new Table($io);
        $table->setHeaders(['Key', 'Value']);
        
        foreach ($config as $key => $value) {
            // Hide sensitive values
            if (str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'secret')) {
                $value = str_repeat('*', strlen($value));
            }
            $table->addRow([$key, $value]);
        }
        
        $table->render();
        return Command::SUCCESS;
    }

    private function getConfig(SymfonyStyle $io, string $configFile, ?string $key): int
    {
        if (!$key) {
            $io->error('Key is required for get action');
            return Command::FAILURE;
        }

        if (!file_exists($configFile)) {
            $io->error("Configuration file not found: $configFile");
            return Command::FAILURE;
        }

        $config = $this->parseEnvFile($configFile);
        
        if (isset($config[$key])) {
            $io->writeln($config[$key]);
            return Command::SUCCESS;
        } else {
            $io->error("Configuration key not found: $key");
            return Command::FAILURE;
        }
    }

    private function setConfig(SymfonyStyle $io, string $configFile, ?string $key, ?string $value): int
    {
        if (!$key || $value === null) {
            $io->error('Key and value are required for set action');
            return Command::FAILURE;
        }

        if (!file_exists($configFile)) {
            $io->error("Configuration file not found: $configFile");
            return Command::FAILURE;
        }

        $content = file_get_contents($configFile);
        $lines = explode("\n", $content);
        $found = false;

        foreach ($lines as &$line) {
            if (preg_match("/^$key\s*=/", $line)) {
                $line = "$key=$value";
                $found = true;
                break;
            }
        }

        if (!$found) {
            $lines[] = "$key=$value";
        }

        file_put_contents($configFile, implode("\n", $lines));
        $io->success("Configuration updated: $key=$value");
        
        return Command::SUCCESS;
    }

    private function validateConfig(SymfonyStyle $io, string $configFile): int
    {
        if (!file_exists($configFile)) {
            $io->error("Configuration file not found: $configFile");
            return Command::FAILURE;
        }

        $io->title('Configuration Validation');
        
        $config = $this->parseEnvFile($configFile);
        $errors = [];
        $warnings = [];

        // Required settings
        $required = [
            'SOCKET_POOL_MAX_SIZE' => 'integer',
            'SOCKET_POOL_TIMEOUT' => 'integer',
            'SOCKET_POOL_MAX_RETRIES' => 'integer',
            'SOCKET_POOL_UNIX_PATH' => 'string',
            'SOCKET_POOL_LOG_FILE' => 'string'
        ];

        foreach ($required as $key => $type) {
            if (!isset($config[$key])) {
                $errors[] = "Missing required setting: $key";
            } else {
                $value = $config[$key];
                if ($type === 'integer' && !ctype_digit($value)) {
                    $errors[] = "$key must be an integer, got: $value";
                }
            }
        }

        // Validate specific values
        if (isset($config['SOCKET_POOL_MAX_SIZE'])) {
            $maxSize = (int) $config['SOCKET_POOL_MAX_SIZE'];
            if ($maxSize < 1 || $maxSize > 1000) {
                $warnings[] = "SOCKET_POOL_MAX_SIZE should be between 1 and 1000, got: $maxSize";
            }
        }

        if (isset($config['SOCKET_POOL_LOG_LEVEL'])) {
            $validLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
            if (!in_array($config['SOCKET_POOL_LOG_LEVEL'], $validLevels)) {
                $warnings[] = "Invalid log level: " . $config['SOCKET_POOL_LOG_LEVEL'];
            }
        }

        // Display results
        if (empty($errors) && empty($warnings)) {
            $io->success('Configuration is valid');
            return Command::SUCCESS;
        }

        if (!empty($errors)) {
            $io->error('Configuration errors found:');
            $io->listing($errors);
        }

        if (!empty($warnings)) {
            $io->warning('Configuration warnings:');
            $io->listing($warnings);
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    private function parseEnvFile(string $file): array
    {
        $config = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            
            list($key, $value) = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        }
        
        return $config;
    }
}