<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

/**
 * Trait for consistent logging in RabbitMQ receivers following Project Protocol.
 */
trait ReceiverLogTrait
{
    /**
     * Log an inbound validation success (Trigger A - Success).
     */
    protected function logReceiverSuccess(string $type, string $source): void
    {
        $message = "Received [{$type}] from [{$source}]. Validation: [Success].";
        
        // 1. Log to Container Logs
        error_log("RabbitMQ Receiver INFO: " . $message);

        // 2. Send to Monitoring Team
        $this->sendToMonitoring('info', 'xml_validation', $message);
    }

    /**
     * Log a receiver error (Trigger A - Failure or Trigger C).
     */
    protected function logReceiverError(\Throwable $e, string $queue, string $xml = ''): void
    {
        // Identify if it's a validation error for Protocol A
        $isValidationError = (strpos($e->getMessage(), 'validation') !== false || strpos($e->getMessage(), 'XSD') !== false);
        
        $action = $isValidationError ? 'xml_validation' : 'system_error';
        $level  = 'error';
        
        // Format message per protocol
        if ($isValidationError) {
            // Try to extract type/source from XML if possible, otherwise use generic
            $type = $this->extractXmlValue($xml, 'type') ?: 'Unknown';
            $source = $this->extractXmlValue($xml, 'source') ?: 'Unknown';
            $message = "Received [{$type}] from [{$source}]. Validation: [Failure]. Details: " . $e->getMessage();
        } else {
            $message = "Internal Error in Receiver [{$queue}]: " . $e->getMessage();
        }

        // 1. Log to Container Logs
        error_log("RabbitMQ Receiver ERROR: " . $message);

        // 2. Log to Drupal Watchdog
        if (class_exists('\Drupal')) {
            \Drupal::logger('rabbitmq_receiver')->error($message, [
                'queue' => $queue,
                'xml_snippet' => substr($xml, 0, 500),
            ]);
        }

        // 3. Send to Monitoring Team
        $this->sendToMonitoring($level, $action, $message);
    }

    private function sendToMonitoring(string $level, string $action, string $message): void
    {
        if (class_exists('\Drupal')) {
            try {
                /** @var \Drupal\rabbitmq_sender\MonitoringLogSender $sender */
                $sender = \Drupal::service('rabbitmq_sender.monitoring_log_sender');
                $sender->send($level, $action, $message);
            } catch (\Throwable $e_monitoring) {
                // Silently ignore to avoid loops
            }
        }
    }

    protected function extractXmlValue(string $xml, string $element): string
    {
        if (empty($xml)) return '';
        if (preg_match("/<{$element}>(.*?)<\/{$element}>/", $xml, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
