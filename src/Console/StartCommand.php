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



/**
 * Start Command - Starts the Socket Pool Service
 */
class StartCommand extends Command
{
    protected static $defaultName = 'start';
    protected static $defaultDescription = 'Start the Socket Pool Service';

    protected function configure(): void
    {
        $this
            ->setDescription('Start the Socket Pool Service')
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run as daemon process')
            ->addOption('pid-file', 'p', InputOption::VALUE_REQUIRED, 'PID file location', '/var/run/socket_pool_service.pid')
            ->setHelp('This command starts the Socket Pool Service...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $pidFile = $input->getOption('pid-file');
            
            // Check if already running
            if (file_exists($pidFile)) {
                $pid = (int) file_get_contents($pidFile);
                if (posix_kill($pid, 0)) {
                    $io->error("Service is already running with PID: $pid");
                    return Command::FAILURE;
                } else {
                    // PID file exists but process is dead, remove it
                    unlink($pidFile);
                }
            }

            $io->info('Starting Socket Pool Service...');
            
            if ($input->getOption('daemon')) {
                $this->startAsDaemon($pidFile, $io);
            } else {
                $this->startForeground($io);
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Failed to start service: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function startAsDaemon(string $pidFile, SymfonyStyle $io): void
    {
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            throw new \Exception('Could not fork process');
        } elseif ($pid) {
            // Parent process
            file_put_contents($pidFile, $pid);
            $io->success("Service started as daemon with PID: $pid");
            exit(0);
        } else {
            // Child process
            posix_setsid();
            $service = SocketPoolService::getInstance();
            $service->run();
        }
    }

    private function startForeground(SymfonyStyle $io): void
    {
        $io->info('Starting service in foreground mode...');
        $service = SocketPoolService::getInstance();
        $service->run();
    }
}

