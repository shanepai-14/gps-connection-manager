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

class BackupCommand extends Command
{
    protected static $defaultName = 'backup';
    protected static $defaultDescription = 'Create service backup';

    protected function configure(): void
    {
        $this
            ->setDescription('Create service backup')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Backup output directory', '/var/backups/socket-pool')
            ->addOption('compress', 'c', InputOption::VALUE_NONE, 'Compress backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $backupDir = $input->getOption('output');
        $compress = $input->getOption('compress');

        $io->title('Creating Socket Pool Service Backup');

        try {
            // Create backup directory
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $timestamp = date('Y-m-d_H-i-s');
            $backupName = "socket-pool-backup-$timestamp";
            $backupPath = "$backupDir/$backupName";

            if ($compress) {
                $backupPath .= '.tar.gz';
            }

            $io->info("Creating backup: $backupPath");

            // Files to backup
            $sources = [
                '/opt/socket-pool-service' => 'service',
                '/etc/socket-pool' => 'config',
                '/var/log/socket-pool' => 'logs'
            ];

            if ($compress) {
                $command = 'tar -czf ' . escapeshellarg($backupPath);
                foreach ($sources as $source => $name) {
                    if (is_dir($source)) {
                        $command .= ' -C ' . dirname($source) . ' ' . basename($source);
                    }
                }
                exec($command, $output, $returnCode);
            } else {
                mkdir($backupPath, 0755, true);
                foreach ($sources as $source => $name) {
                    if (is_dir($source)) {
                        $destDir = "$backupPath/$name";
                        mkdir($destDir, 0755, true);
                        exec("cp -r $source/* $destDir/");
                    }
                }
            }

            // Cleanup old backups (keep last 10)
            $backups = glob("$backupDir/socket-pool-backup-*");
            if (count($backups) > 10) {
                sort($backups);
                $toDelete = array_slice($backups, 0, count($backups) - 10);
                foreach ($toDelete as $old) {
                    if (is_file($old)) {
                        unlink($old);
                    } elseif (is_dir($old)) {
                        exec("rm -rf " . escapeshellarg($old));
                    }
                }
            }

            $io->success("Backup created: $backupPath");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Backup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}