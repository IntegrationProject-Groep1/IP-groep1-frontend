<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Shared retry strategy for transient RabbitMQ publish failures.
 * Now follows the Project-Wide Logging Protocol for Outbound messages.
 */
trait RetryTrait
{
    /**
     * Executes a send operation with retry semantics for transient failures.
     */
    private function sendWithRetry(
        callable $sendFunction,
        int $maxRetries = 3,
        int $waitSeconds = 5
    ): void {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $sendFunction();
                return;
            } catch (\Exception $e) {
                $attempt++;

                // Logging (CI-safe)
                $this->log('warning', 'Retrying message send', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $maxRetries) {
                    $this->log('error', 'Failed to send message after retries', [
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }

                if ($waitSeconds > 0) {
                    sleep(min($waitSeconds, 5));
                }
            }
        }
    }

    /**
     * Log a successful outbound message (Trigger B).
     */
    protected function logOutboundSuccess(string $type, string $queue, string $xml): void
    {
        $correlationId = $this->extractXmlValue($xml, 'correlation_id') ?: 'None';
        $message = "Published [{$type}] to [{$queue}]. CorrelationID: [{$correlationId}].";
        
        // 1. Log to Container Logs
        error_log("RabbitMQ Sender INFO: " . $message);

        // 2. Send to Monitoring Team (Trigger B uses business context action)
        $action = $this->mapTypeToAction($type);
        $this->sendToMonitoring('info', $action, $message);
    }

    /**
     * Log a system failure (Trigger C).
     */
    protected function logSystemError(string $module, string $error): void
    {
        $message = "Internal Error in [{$module}]: {$error}";
        
        // 1. Log to Container Logs
        error_log("RabbitMQ Sender ERROR: " . $message);

        // 2. Send to Monitoring Team
        $this->sendToMonitoring('error', 'system_error', $message);
    }

    private function sendToMonitoring(string $level, string $action, string $message): void
    {
        if (class_exists('\Drupal')) {
            try {
                /** @var \Drupal\rabbitmq_sender\MonitoringLogSender $sender */
                $sender = \Drupal::service('rabbitmq_sender.monitoring_log_sender');
                $sender->send($level, $action, $message);
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }

    /**
     * Safe logger (werkt zowel in Drupal als PHPUnit)
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (class_exists('\Drupal')) {
            \Drupal::logger('rabbitmq_sender')->{$level}($message, $context);

            // Trigger C: Send critical internal errors to Monitoring
            if ($level === 'error') {
                $this->logSystemError('rabbitmq_sender', $message . ': ' . ($context['error'] ?? ''));
            }
        }
    }

    protected function extractXmlValue(string $xml, string $element): string
    {
        if (preg_match("/<{$element}>(.*?)<\/{$element}>/", $xml, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function mapTypeToAction(string $type): string
    {
        $map = [
            'new_registration'      => 'registration',
            'user_registered'       => 'registration',
            'user_created'          => 'user',
            'user_updated'          => 'user',
            'user_deleted'          => 'user',
            'payment_registered'    => 'payment',
            'invoice_request'       => 'invoice',
            'session_view_request'  => 'session',
            'event_ended'           => 'session',
            'calendar_invite'       => 'calendar',
            'send_mailing'          => 'email',
            'wallet_balance_update' => 'wallet',
            'badge_scanned'         => 'badge',
            'identity_request'      => 'identity',
        ];

        return $map[$type] ?? 'system_error';
    }
}
