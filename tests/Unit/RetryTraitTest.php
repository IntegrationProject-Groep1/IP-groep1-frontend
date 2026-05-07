<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\RetryTrait;
/**
 * Unit tests for retry behavior shared by RabbitMQ senders.
 */
class RetryTraitTest extends TestCase
{
    public function test_succeeds_on_first_attempt(): void
    {
        $mock = new class {
            use RetryTrait;
            public function run(callable $fn): void
            {
                $this->sendWithRetry($fn, 3, 0);
            }
        };
        $counter = 0;
        $mock->run(function () use (&$counter) {
            $counter++;
        });
        $this->assertEquals(1, $counter);
    }

    public function test_throws_exception_after_max_retries(): void
    {
        $mock = new class {
            use RetryTrait;
            public function run(callable $fn): void
            {
                $this->sendWithRetry($fn, 3, 0);
            }
        };
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection failed');
        ob_start();
        try {
            $mock->run(function () {
                throw new \Exception('Connection failed');
            });
        } finally {
            ob_end_clean();
        }
    }
} 

