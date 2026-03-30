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
                $this->processMessage($msg);
            }
        );

        echo "Listening for badge scans...\n";

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

        $userId = (string) $xml->payload->user_id;
        $badgeId = (string) $xml->payload->badge_id;

        if (empty($userId)) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($badgeId)) {
            throw new \InvalidArgumentException('badge_id is required');
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

            $userId = (string) $xml->payload->user_id;
            $badgeId = (string) $xml->payload->badge_id;

            if (empty($userId)) {
                throw new \InvalidArgumentException('user_id is required');
            }
            if (empty($badgeId)) {
                throw new \InvalidArgumentException('badge_id is required');
            }

            // Update badge_id in Drupal database
            echo "Badge scanned: {$userId} - {$badgeId}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('BadgeScannedReceiver error: ' . $e->getMessage());
            $msg->nack();
        }
    }
}