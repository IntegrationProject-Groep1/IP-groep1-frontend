<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

class BadgeScannedReceiver
{
    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();
        $channel->queue_declare('badge.scanned', false, true, false, false);

        $channel->basic_consume(
            'badge.scanned',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) {
                $this->handleMessage($msg);
            }
        );

        echo "Listening for badge scans...\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    public function processMessageFromXml(string $xmlString): bool
    {
        $xml = $this->parseXml($xmlString);

        $userId = (string) $xml->body->user_id;
        $badgeId = (string) $xml->body->badge_id;

        if (empty($userId)) {
            throw new \InvalidArgumentException('user_id is required');
        }

        if (empty($badgeId)) {
            throw new \InvalidArgumentException('badge_id is required');
        }

        return true;
    }

    private function handleMessage(AMQPMessage $msg): void
    {
        try {
            $xml = $this->parseXml($msg->body);

            $userId = (string) $xml->body->user_id;
            $badgeId = (string) $xml->body->badge_id;

            if (empty($userId) || empty($badgeId)) {
                throw new \InvalidArgumentException('Missing required fields');
            }

            echo "Badge scanned: {$userId} - {$badgeId}\n";

            $msg->ack();

        } catch (\Throwable $e) {
            error_log('BadgeScannedReceiver error: ' . $e->getMessage());
            $msg->nack(false, false); // discard message (no infinite retry loop)
        }
    }

    private function parseXml(string $xmlString): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        return $xml;
    }
}