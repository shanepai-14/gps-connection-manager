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



class StopCommand extends Command
{
    protected static $defaultName = 'stop';
    protected static $defaultDescription = 'Stop the Socket Pool Service';

    protected function configure(): void
    {
        $this
            ->setDescription('Stop the Socket Pool Service')
            ->addOption('pid-file', 'p', InputOption::VALUE_REQUIRED, 'PID file location', '/var/run/socket-pool/socket-pool.pid')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force kill the process')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Shutdown timeout in seconds', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pidFile = $input->getOption('pid-file');
        $timeout = (int) $input->getOption('timeout');
        
        if (!file_exists($pidFile)) {
            $io->warning('PID file not found. Service may not be running.');
            return Command::SUCCESS;
        }

        $pid = (int) file_get_contents($pidFile);
        
        if (!posix_kill($pid, 0)) {
            $io->warning("Process with PID $pid is not running. Cleaning up PID file.");
            unlink($pidFile);
            return Command::SUCCESS;
        }

        $io->info("Stopping service with PID: $pid");
        
        $signal = $input->getOption('force') ? SIGKILL : SIGTERM;
        
        if (posix_kill($pid, $signal)) {
            // Wait for process to terminate with progress bar
            $progressBar = new ProgressBar($output, $timeout);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% Waiting for graceful shutdown...');
            $progressBar->start();
            
            while ($timeout > 0 && posix_kill($pid, 0)) {
                sleep(1);
                $timeout--;
                $progressBar->advance();
            }
            $progressBar->finish();
            $output->writeln('');
            
            if (posix_kill($pid, 0)) {
                $io->warning('Process did not terminate gracefully. Sending SIGKILL...');
                posix_kill($pid, SIGKILL);
                sleep(2);
            }
            
            unlink($pidFile);
            $io->success('Service stopped successfully');
            return Command::SUCCESS;
        } else {
            $io->error('Failed to stop service');
            return Command::FAILURE;
        }
    }
}