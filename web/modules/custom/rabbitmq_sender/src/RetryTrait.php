<?php
declare(strict_types=1);

trait RetryTrait
{
    private function sendWithRetry(callable $sendFunction, int $maxRetries = 3): void
    {
        $attempt = 0;

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

                echo "Attempt {$attempt} failed, retrying in 5 seconds...\n";
                sleep(5);
            }
        }
    }
}