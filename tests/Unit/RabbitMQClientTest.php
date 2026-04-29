<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for RabbitMQ client configuration validation.
 */
class RabbitMQClientTest extends TestCase
{
    public function test_throws_exception_when_host_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $client = new RabbitMQClient('', 5672, 'guest', 'guest');
    }

    public function test_throws_exception_when_port_is_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $client = new RabbitMQClient('localhost', 0, 'guest', 'guest');
    }

    public function test_throws_exception_when_port_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RabbitMQClient('localhost', -1, 'guest', 'guest');
    }

    public function test_constructor_accepts_valid_arguments(): void
    {
        // Should not throw — only verifies construction succeeds (no network call yet).
        $client = new RabbitMQClient('localhost', 5672, 'guest', 'guest');
        $this->assertInstanceOf(RabbitMQClient::class, $client);
    }

    public function test_constructor_accepts_custom_vhost(): void
    {
        $client = new RabbitMQClient('localhost', 5672, 'user', 'pass', '/myvhost');
        $this->assertInstanceOf(RabbitMQClient::class, $client);
    }

    public function test_constructor_accepts_environment_defaults(): void
    {
        $previousHost = getenv('RABBITMQ_HOST');
        $previousPort = getenv('RABBITMQ_PORT');
        $previousUser = getenv('RABBITMQ_USER');
        $previousPass = getenv('RABBITMQ_PASS');
        $previousVhost = getenv('RABBITMQ_VHOST');

        putenv('RABBITMQ_HOST=localhost');
        putenv('RABBITMQ_PORT=5672');
        putenv('RABBITMQ_USER=guest');
        putenv('RABBITMQ_PASS=guest');
        putenv('RABBITMQ_VHOST=/');

        try {
            $client = new RabbitMQClient();
            $this->assertInstanceOf(RabbitMQClient::class, $client);
        } finally {
            $this->restoreEnv('RABBITMQ_HOST', $previousHost);
            $this->restoreEnv('RABBITMQ_PORT', $previousPort);
            $this->restoreEnv('RABBITMQ_USER', $previousUser);
            $this->restoreEnv('RABBITMQ_PASS', $previousPass);
            $this->restoreEnv('RABBITMQ_VHOST', $previousVhost);
        }
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }
}
