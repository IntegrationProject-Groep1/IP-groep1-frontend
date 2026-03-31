<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

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
        $channel->queue_declare('payment.registered', false, true, false, false);

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

            // Update payment status in Drupal storage.
            echo "Payment registered: {$userId} - {$status}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('PaymentRegisteredReceiver error: ' . $e->getMessage());
            $msg->nack();
        }
    }
}