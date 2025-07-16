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


class RestartCommand extends Command
{
    protected static $defaultName = 'restart';
    protected static $defaultDescription = 'Restart the Socket Pool Service';

    protected function configure(): void
    {
        $this
            ->setDescription('Restart the Socket Pool Service')
            ->addOption('pid-file', 'p', InputOption::VALUE_REQUIRED, 'PID file location', '/var/run/socket-pool/socket-pool.pid')
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Restart as daemon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->info('Restarting Socket Pool Service...');
        
        // Stop the service
        $stopCommand = new StopCommand();
        $stopResult = $stopCommand->run($input, $output);
        
        if ($stopResult !== Command::SUCCESS) {
            $io->error('Failed to stop service');
            return Command::FAILURE;
        }
        
        // Wait a moment
        $io->info('Waiting for complete shutdown...');
        sleep(2);
        
        // Start the service
        $startCommand = new StartCommand();
        return $startCommand->run($input, $output);
    }
}