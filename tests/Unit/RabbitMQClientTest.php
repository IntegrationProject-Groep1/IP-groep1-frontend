<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\RabbitMQClient;

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
}
