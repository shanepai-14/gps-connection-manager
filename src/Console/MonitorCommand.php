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

class MonitorCommand extends Command
{
    protected static $defaultName = 'monitor';
    protected static $defaultDescription = 'Run monitoring daemon';

    protected function configure(): void
    {
        $this
            ->setDescription('Run service monitoring daemon')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Check interval in seconds', '60')
            ->addOption('alert-email', null, InputOption::VALUE_REQUIRED, 'Email for alerts')
            ->addOption('webhook-url', null, InputOption::VALUE_REQUIRED, 'Webhook URL for alerts');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $interval = (int) $input->getOption('interval');
        $alertEmail = $input->getOption('alert-email');
        $webhookUrl = $input->getOption('webhook-url');

        $io->info("Starting monitoring daemon (interval: {$interval}s)");

        $client = new SocketPoolClient();
        $consecutiveFailures = 0;
        $maxFailures = 3;

        while (true) {
            try {
                $health = $client->performHealthCheck();
                
                if ($health['success']) {
                    if ($consecutiveFailures > 0) {
                        $this->sendAlert("Service recovered after $consecutiveFailures failures", $alertEmail, $webhookUrl);
                        $consecutiveFailures = 0;
                        $io->info('Service recovered');
                    }
                } else {
                    $consecutiveFailures++;
                    $error = $health['error'] ?? 'Unknown error';
                    $io->warning("Health check failed ($consecutiveFailures/$maxFailures): $error");
                    
                    if ($consecutiveFailures >= $maxFailures) {
                        $this->sendAlert("Service health check failed $consecutiveFailures times: $error", $alertEmail, $webhookUrl);
                    }
                }

                // Log current status
                $timestamp = date('Y-m-d H:i:s');
                $status = $health['success'] ? 'OK' : 'FAILED';
                file_put_contents('/var/log/socket-pool/monitor.log', 
                    "[$timestamp] Health check: $status\n", FILE_APPEND);

            } catch (\Exception $e) {
                $consecutiveFailures++;
                $io->error("Monitoring error: " . $e->getMessage());
                
                if ($consecutiveFailures >= $maxFailures) {
                    $this->sendAlert("Monitoring failed: " . $e->getMessage(), $alertEmail, $webhookUrl);
                }
            }

            sleep($interval);
        }

        return Command::SUCCESS;
    }

    private function sendAlert(string $message, ?string $email, ?string $webhook): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $fullMessage = "[$timestamp] Socket Pool Service Alert: $message";

        // Log alert
        file_put_contents('/var/log/socket-pool/alerts.log', $fullMessage . "\n", FILE_APPEND);

        // Send email if configured
        if ($email) {
            $subject = "Socket Pool Service Alert";
            mail($email, $subject, $fullMessage);
        }

        // Send webhook if configured
        if ($webhook) {
            $postData = json_encode(['text' => $fullMessage]);
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => $postData
                ]
            ]);
            @file_get_contents($webhook, false, $context);
        }
    }
}