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
}

