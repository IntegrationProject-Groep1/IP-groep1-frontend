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

        while ($attempt < $maxRetries) {
            try {
                $sendFunction();
                return;
            } catch (\Exception $e) {
                $attempt++;

                // ⚠️ Optional: warning bij retry
                \Drupal::logger('rabbitmq_sender')->warning('Retrying message send', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $maxRetries) {
                    // ❗ Final failure = error
                    \Drupal::logger('rabbitmq_sender')->error('Failed to send message after retries', [
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
}