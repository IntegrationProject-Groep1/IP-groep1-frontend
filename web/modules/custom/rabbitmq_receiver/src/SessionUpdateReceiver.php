<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumes session_updated events from Planning via planning.exchange topic exchange.
 *
 * Planning publishes on:
 *   Exchange:    planning.exchange                    (topic, durable)
 *   Routing key: planning.to.frontend.session.updated
 *
 * We bind our own durable queue so we survive broker restarts.
 *   Queue: frontend.planning.session.updated
 */
class SessionUpdateReceiver
{
    private const EXCHANGE     = 'planning.exchange';
    private const ROUTING_KEY  = 'planning.to.frontend.session.updated';
    private const QUEUE        = 'frontend.planning.session.updated';

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();
        $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $channel->queue_declare(self::QUEUE, false, true, false, false);
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        $channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) {
                $this->processMessage($msg);
            }
        );

        echo 'Listening for session_updated on ' . self::EXCHANGE . ' → ' . self::QUEUE . "\n";

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

        $msgType   = (string) $xml->header->type;
        $sessionId = (string) $xml->body->session_id;

        if ($msgType !== 'session_updated') {
            throw new \InvalidArgumentException("Expected type session_updated, got {$msgType}");
        }
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

            $sessionId      = (string) $xml->body->session_id;
            $title          = (string) $xml->body->title;
            $startDatetime  = (string) $xml->body->start_datetime;
            $endDatetime    = (string) $xml->body->end_datetime;
            $location       = (string) $xml->body->location;
            $status         = (string) $xml->body->status;
            $changeReason   = (string) $xml->body->change_reason;

            if (empty($sessionId)) {
                throw new \InvalidArgumentException('session_id is required');
            }

            // Update the session in Drupal storage.
            echo "Session updated: {$sessionId} | {$title} | {$startDatetime} → {$endDatetime} | {$location} | {$status}" . ($changeReason ? " | reason: {$changeReason}" : '') . "\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('SessionUpdateReceiver error: ' . $e->getMessage());
            $msg->nack();
        }
    }
}