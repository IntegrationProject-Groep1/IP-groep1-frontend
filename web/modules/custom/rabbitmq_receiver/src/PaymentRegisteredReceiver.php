<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Consumes payment registration events from RabbitMQ.
 */
class PaymentRegisteredReceiver
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
        $channel->queue_declare('payment.registered.dlq', false, true, false, false);
        $channel->queue_bind('payment.registered.dlq', 'dlx_exchange', 'payment.registered.dlq');

        // Main queue met DLQ config
        $args = new AMQPTable([
            'x-dead-letter-exchange' => 'dlx_exchange',
            'x-dead-letter-routing-key' => 'payment.registered.dlq'
        ]);

        $channel->queue_declare(
            'payment.registered',
            false,
            true,
            false,
            false,
            false,
            $args
        );

        $channel->basic_consume(
            'payment.registered',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) {
                $this->processMessage($msg);
            }
        );

        echo "Listening for payment registrations...\n";

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
        $status = (string) $xml->body->status;

        if (empty($userId)) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($status)) {
            throw new \InvalidArgumentException('status is required');
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
            $status = (string) $xml->body->status;

            if (empty($userId)) {
                throw new \InvalidArgumentException('user_id is required');
            }
            if (empty($status)) {
                throw new \InvalidArgumentException('status is required');
            }

            echo "Payment registered: {$userId} - {$status}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('PaymentRegisteredReceiver error: ' . $e->getMessage());

            // naar DLQ
            $msg->nack(false, false);
        }
    }
}