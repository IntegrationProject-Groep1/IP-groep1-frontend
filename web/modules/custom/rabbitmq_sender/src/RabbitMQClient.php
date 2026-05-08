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
    private readonly string $host;
    private readonly int $port;
    private readonly string $user;
    private readonly string $password;
    private readonly string $vhost;

    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $user = null,
        ?string $password = null,
        ?string $vhost = null
    ) {
        $this->host = $host ?? (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker');
        $this->port = $port ?? (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $this->user = $user ?? (string) (getenv('RABBITMQ_USER') ?: 'guest');
        $this->password = $password ?? (string) (getenv('RABBITMQ_PASS') ?: 'guest');
        $this->vhost = $vhost ?? (string) (getenv('RABBITMQ_VHOST') ?: '/');

        if (empty($this->host)) {
            throw new \InvalidArgumentException('Host cannot be empty');
        }
        if ($this->port <= 0) {
            throw new \InvalidArgumentException('Port must be greater than 0');
        }
    }

    public function getChannel(): AMQPChannel
    {
        if ($this->channel === null) {
            try {
                $this->connection = new AMQPStreamConnection(
                    $this->host,
                    $this->port,
                    $this->user,
                    $this->password,
                    $this->vhost
                );

                $this->channel = $this->connection->channel();

                \Drupal::logger('rabbitmq_sender')->info('RabbitMQ connection established', [
                    'host' => $this->host,
                    'port' => $this->port,
                ]);

            } catch (\Throwable $e) {
                \Drupal::logger('rabbitmq_sender')->error('Failed to connect to RabbitMQ', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return $this->channel;
    }

    public function declareQueue(string $queueName, bool $durable = true): void
    {
        try {
            $this->getChannel()->queue_declare($queueName, false, $durable, false, false);

            \Drupal::logger('rabbitmq_sender')->info('Queue declared', [
                'queue' => $queueName,
            ]);

        } catch (\Throwable $e) {
            \Drupal::logger('rabbitmq_sender')->error('Failed to declare queue', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function publishToQueue(string $queueName, string $body, int $deliveryMode = 2): void
    {
        try {
            $message = new AMQPMessage($body, ['delivery_mode' => $deliveryMode]);

            $this->getChannel()->basic_publish($message, '', $queueName);

            \Drupal::logger('rabbitmq_sender')->info('Message published to queue', [
                'queue' => $queueName,
            ]);

        } catch (\Throwable $e) {
            \Drupal::logger('rabbitmq_sender')->error('Failed to publish message to queue', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function declareExchange(string $exchangeName, string $type = 'topic', bool $durable = true): void
    {
        try {
            $this->getChannel()->exchange_declare($exchangeName, $type, false, $durable, false);

            \Drupal::logger('rabbitmq_sender')->info('Exchange declared', [
                'exchange' => $exchangeName,
                'type' => $type,
            ]);

        } catch (\Throwable $e) {
            \Drupal::logger('rabbitmq_sender')->error('Failed to declare exchange', [
                'exchange' => $exchangeName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function publishToExchange(string $exchangeName, string $routingKey, string $body, int $deliveryMode = 2): void
    {
        try {
            $message = new AMQPMessage($body, [
                'delivery_mode' => $deliveryMode,
                'content_type' => 'application/xml',
            ]);

            $this->getChannel()->basic_publish($message, $exchangeName, $routingKey);

            \Drupal::logger('rabbitmq_sender')->info('Message published to exchange', [
                'exchange' => $exchangeName,
                'routing_key' => $routingKey,
            ]);

        } catch (\Throwable $e) {
            \Drupal::logger('rabbitmq_sender')->error('Failed to publish message to exchange', [
                'exchange' => $exchangeName,
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function close(): void
    {
        try {
            if ($this->channel !== null) {
                $this->channel->close();
                $this->channel = null;
            }

            if ($this->connection !== null) {
                $this->connection->close();
                $this->connection = null;
            }

            \Drupal::logger('rabbitmq_sender')->info('RabbitMQ connection closed');

        } catch (\Throwable $e) {
            \Drupal::logger('rabbitmq_sender')->warning('Error while closing RabbitMQ connection', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
