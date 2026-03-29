<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQClient
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $vhost = '/'
    ) {
        if (empty($host)) {
            throw new \InvalidArgumentException('Host cannot be empty');
        }
        if ($port <= 0) {
            throw new \InvalidArgumentException('Port must be greater than 0');
        }

        $this->connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
        $this->channel = $this->connection->channel();
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function declareQueue(string $queueName, bool $durable = true): void
    {
        $this->channel->queue_declare($queueName, false, $durable, false, false);
    }

    public function publishToQueue(string $queueName, string $body, int $deliveryMode = 2): void
    {
        $message = new AMQPMessage($body, ['delivery_mode' => $deliveryMode]);
        $this->channel->basic_publish($message, '', $queueName);
    }

    public function close(): void
    {
        $this->channel->close();
        $this->connection->close();
    }
}