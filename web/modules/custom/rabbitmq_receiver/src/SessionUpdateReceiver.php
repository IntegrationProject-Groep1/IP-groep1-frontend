<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

class SessionUpdateReceiver
{
    private RabbitMQClient $client;
    private string $queueName;

    public function __construct(RabbitMQClient $client, ?string $queueName = null)
    {
        $this->client = $client;
        $prefix = getenv('RABBITMQ_PREFIX') ?: 'frontend.';
        $this->queueName = $queueName ?? ($prefix . 'session.update');
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();
        $channel->queue_declare($this->queueName, false, true, false, false);

        $channel->basic_consume(
            $this->queueName,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) {
                $this->processMessage($msg);
            }
        );

        echo "Listening for session updates...\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    public function processMessageFromXml(string $xmlString): bool
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $sessionId = (string) $xml->body->session_id;

        if (empty($sessionId)) {
            throw new \InvalidArgumentException('session_id is required');
        }

        return true;
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $xml = simplexml_load_string($msg->body);

            if ($xml === false) {
                throw new \InvalidArgumentException('Invalid XML received');
            }

            $sessionId = (string) $xml->body->session_id;
            $newTime = (string) $xml->body->new_time;
            $newLocation = (string) $xml->body->location;

            if (empty($sessionId)) {
                throw new \InvalidArgumentException('session_id is required');
            }

            // Update sessie in Drupal database
            // Dit wordt later gekoppeld aan de Drupal API
            echo "Session updated: {$sessionId} - {$newTime} - {$newLocation}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('SessionUpdateReceiver error: ' . $e->getMessage());
            $msg->nack();
        }
    }
}