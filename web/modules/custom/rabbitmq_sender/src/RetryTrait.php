<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Shared retry strategy for transient RabbitMQ publish failures.
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

        // Retry up to maxRetries and rethrow on final failure.
        while ($attempt < $maxRetries) {
            try {
                $sendFunction();
                return;
            } catch (\Exception $e) {
                $attempt++;

                if ($attempt >= $maxRetries) {
                    error_log("Failed to send message after {$maxRetries} attempts: " . $e->getMessage());
                    throw $e;
                }

                if ($waitSeconds > 0) {
                    // Keep retries bounded and quiet in web requests.
                    sleep(min($waitSeconds, 5));
                }
            }
        }
    }
}