<?php
declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

class RabbitMQClient
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password
    ) {
        if (empty($host)) {
            throw new \InvalidArgumentException('Host cannot be empty');
        }
        if ($port <= 0) {
            throw new \InvalidArgumentException('Port must be greater than 0');
        }

        $this->connection = new AMQPStreamConnection($host, $port, $user, $password);
        $this->channel = $this->connection->channel();
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function close(): void
    {
        $this->channel->close();
        $this->connection->close();
    }
}