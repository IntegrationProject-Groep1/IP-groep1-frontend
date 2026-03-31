<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Lightweight RabbitMQ wrapper with lazy connection/channel initialization.
 */
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
            // Open the connection only when first needed.
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
        // Declare queue idempotently so publishers can run without pre-provisioning.
        $this->getChannel()->queue_declare($queueName, false, $durable, false, false);
    }

    public function publishToQueue(string $queueName, string $body, int $deliveryMode = 2): void
    {
        // Use persistent delivery mode by default for resilience across broker restarts.
        $message = new AMQPMessage($body, ['delivery_mode' => $deliveryMode]);
        $this->getChannel()->basic_publish($message, '', $queueName);
    }

    public function declareExchange(string $exchangeName, string $type = 'topic', bool $durable = true): void
    {
        // Declare exchange idempotently so any service can run without pre-provisioning.
        $this->getChannel()->exchange_declare($exchangeName, $type, false, $durable, false);
    }

    public function publishToExchange(string $exchangeName, string $routingKey, string $body, int $deliveryMode = 2): void
    {
        // Publish to a named exchange with a routing key instead of the default direct queue.
        $message = new AMQPMessage($body, [
            'delivery_mode' => $deliveryMode,
            'content_type' => 'application/xml',
        ]);
        $this->getChannel()->basic_publish($message, $exchangeName, $routingKey);
    }

    public function close(): void
    {
        // Close channel first, then connection, to release broker resources cleanly.
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