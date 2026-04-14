<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable; // ✅ toegevoegd

/**
 * Consumes VAT validation error events from RabbitMQ.
 */
class VatValidationErrorReceiver
{
    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();

        // ✅ DLX + DLQ toegevoegd
        $channel->exchange_declare('dlx_exchange', 'direct', false, true, false);
        $channel->queue_declare('vat.validation.error.dlq', false, true, false, false);
        $channel->queue_bind('vat.validation.error.dlq', 'dlx_exchange', 'vat.validation.error.dlq');

        // ✅ Main queue aangepast
        $args = new AMQPTable([
            'x-dead-letter-exchange' => 'dlx_exchange',
            'x-dead-letter-routing-key' => 'vat.validation.error.dlq'
        ]);

        $channel->queue_declare(
            'vat.validation.error',
            false,
            true,
            false,
            false,
            false,
            $args
        );

        $channel->basic_consume(
            'vat.validation.error',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) {
                $this->processMessage($msg);
            }
        );

        echo "Listening for VAT validation errors...\n";

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
        $vatNumber = (string) $xml->body->vat_number;

        if (empty($userId)) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($vatNumber)) {
            throw new \InvalidArgumentException('vat_number is required');
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
            $vatNumber = (string) $xml->body->vat_number;
            $errorMessage = (string) $xml->body->error_message;

            if (empty($userId)) {
                throw new \InvalidArgumentException('user_id is required');
            }
            if (empty($vatNumber)) {
                throw new \InvalidArgumentException('vat_number is required');
            }

            echo "VAT validation error: {$userId} - {$vatNumber} - {$errorMessage}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('VatValidationErrorReceiver error: ' . $e->getMessage());

            $msg->nack(false, false); // 🔥 aangepast → DLQ
        }
    }
}