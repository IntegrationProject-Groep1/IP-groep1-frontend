<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQClient
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost = '/'
    ) {
        if (empty($host)) {
            throw new \InvalidArgumentException('Host cannot be empty');
        }
        if ($port <= 0) {
            throw new \InvalidArgumentException('Port must be greater than 0');
        }
    }

    public function getChannel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost
            );
            $this->channel = $this->connection->channel();
        }

        return $this->channel;
    }

    public function declareQueue(string $queueName, bool $durable = true): void
    {
        $this->getChannel()->queue_declare($queueName, false, $durable, false, false);
    }

    public function publishToQueue(string $queueName, string $body, int $deliveryMode = 2): void
    {
        $message = new AMQPMessage($body, ['delivery_mode' => $deliveryMode]);
        $this->getChannel()->basic_publish($message, '', $queueName);
    }

    public function close(): void
    {
        if ($this->channel !== null) {
            $this->channel->close();
            $this->channel = null;
        }
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }
}