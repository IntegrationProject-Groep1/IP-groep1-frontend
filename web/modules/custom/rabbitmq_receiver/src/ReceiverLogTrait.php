<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

/**
 * Trait for consistent logging in RabbitMQ receivers.
 */
trait ReceiverLogTrait
{
    /**
     * Log a receiver error to both Drupal watchdog and container logs (STDERR).
     */
    protected function logReceiverError(\Throwable $e, string $queue, string $xml = ''): void
    {
        $message = sprintf(
            'RabbitMQ Receiver Error [%s]: %s',
            $queue,
            $e->getMessage()
        );

        // 1. Log to Container Logs (Visible in 'docker logs')
        error_log($message);

        // 2. Log to Drupal Watchdog (Visible in /admin/reports/dblog)
        if (class_exists('\Drupal')) {
            \Drupal::logger('rabbitmq_receiver')->error($message, [
                'queue' => $queue,
                'exception' => $e,
                'xml_snippet' => substr($xml, 0, 1000),
            ]);

            // 3. Send to Monitoring Team (RabbitMQ logs queue)
            try {
                /** @var \Drupal\rabbitmq_sender\MonitoringLogSender $sender */
                $sender = \Drupal::service('rabbitmq_sender.monitoring_log_sender');
                $sender->send('error', 'system_error', $message);
            } catch (\Throwable $e_monitoring) {
                // Silently ignore monitoring failures to prevent recursive loops
            }
        }
    }
}
