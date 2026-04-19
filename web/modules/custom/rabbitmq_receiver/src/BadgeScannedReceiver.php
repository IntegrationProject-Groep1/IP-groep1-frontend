<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Consumes badge scan events from RabbitMQ.
 */
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

        // DLX + DLQ setup
        $channel->exchange_declare('dlx_exchange', 'direct', false, true, false);
        $channel->queue_declare('badge.scanned.dlq', false, true, false, false);
        $channel->queue_bind('badge.scanned.dlq', 'dlx_exchange', 'badge.scanned.dlq');

        // Main queue met DLQ config
        $args = new AMQPTable([
            'x-dead-letter-exchange' => 'dlx_exchange',
            'x-dead-letter-routing-key' => 'badge.scanned.dlq'
        ]);

        $channel->queue_declare(
            'badge.scanned',
            false,
            true,
            false,
            false,
            false,
            $args
        );

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

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $xml = simplexml_load_string($msg->body);

            if ($xml === false) {
                throw new \InvalidArgumentException('Invalid XML received');
            }

            $userId = (string) $xml->body->user_id;
            $badgeId = (string) $xml->body->badge_id;

            if (empty($userId)) {
                throw new \InvalidArgumentException('user_id is required');
            }
            if (empty($badgeId)) {
                throw new \InvalidArgumentException('badge_id is required');
            }

            echo "Badge scanned: {$userId} - {$badgeId}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('BadgeScannedReceiver error: ' . $e->getMessage());

            // naar DLQ
            $msg->nack(false, false);
        }
    }
}